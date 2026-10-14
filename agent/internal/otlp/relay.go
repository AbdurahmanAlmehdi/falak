package otlp

import (
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	logspb "go.opentelemetry.io/proto/otlp/logs/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
)

// SpanObserver sees every (enriched, pre-sampling) span batch — used by the Insights tee.
type SpanObserver interface {
	ObserveSpans(rs []*tracepb.ResourceSpans)
}

// Options configure a Relay.
type Options struct {
	BufferDir     string        // on-disk buffer directory (required)
	BatchSize     int           // resource entries per export (default 512)
	FlushInterval time.Duration // max batching delay (default 2s)
	QueueSize     int           // intake queue (default 4096 items)
	Observer      SpanObserver  // optional
	HTTPClient    *http.Client  // optional
	Logger        *slog.Logger
	// ReplayBackoff bounds retry spacing for buffered segments (default 1s … 60s).
	ReplayMin, ReplayMax time.Duration
}

type item struct {
	spans   []*tracepb.ResourceSpans
	logs    []*logspb.ResourceLogs
	metrics []*metricspb.ResourceMetrics
}

type encoded struct {
	sig  Signal
	body []byte
}

// Relay batches incoming telemetry and exports it.
type Relay struct {
	opts   Options
	cfg    atomic.Pointer[Config]
	in     chan item
	out    chan encoded
	flush  chan chan struct{}
	buf    *DiskBuffer
	client *http.Client
	log    *slog.Logger

	dropped   atomic.Uint64
	exported  atomic.Uint64
	replayNow chan struct{}
	started   sync.Once
}

// NewRelay creates a relay; call Run to start processing.
func NewRelay(opts Options) (*Relay, error) {
	if opts.BatchSize <= 0 {
		opts.BatchSize = 512
	}
	if opts.FlushInterval <= 0 {
		opts.FlushInterval = 2 * time.Second
	}
	if opts.QueueSize <= 0 {
		opts.QueueSize = 4096
	}
	if opts.ReplayMin <= 0 {
		opts.ReplayMin = time.Second
	}
	if opts.ReplayMax <= 0 {
		opts.ReplayMax = 60 * time.Second
	}
	if opts.Logger == nil {
		opts.Logger = slog.Default()
	}
	if opts.BufferDir == "" {
		return nil, errors.New("otlp: BufferDir required")
	}
	buf, err := OpenDiskBuffer(opts.BufferDir, DefaultBufferMax)
	if err != nil {
		return nil, err
	}
	client := opts.HTTPClient
	if client == nil {
		client = &http.Client{Timeout: 15 * time.Second}
	}
	r := &Relay{
		opts: opts, in: make(chan item, opts.QueueSize), out: make(chan encoded, 16),
		flush: make(chan chan struct{}), buf: buf, client: client, log: opts.Logger,
		replayNow: make(chan struct{}, 1),
	}
	r.cfg.Store(&Config{TracesRatio: 1})
	return r, nil
}

// Configure hot-swaps the configuration.
func (r *Relay) Configure(c Config) {
	if c.TracesRatio < 0 {
		c.TracesRatio = 0
	}
	if c.TracesRatio > 1 {
		c.TracesRatio = 1
	}
	c.Sites = append([]Site(nil), c.Sites...)
	r.cfg.Store(&c)
	r.buf.SetMax(c.BufferMax)
	r.kickReplay()
}

// Config returns the active configuration.
func (r *Relay) Config() Config { return *r.cfg.Load() }

// Buffer exposes the disk buffer (stats/tests).
func (r *Relay) Buffer() *DiskBuffer { return r.buf }

// Stats returns dropped (queue overflow) and exported request counts.
func (r *Relay) Stats() (dropped, exported uint64) { return r.dropped.Load(), r.exported.Load() }

func (r *Relay) submit(it item) bool {
	select {
	case r.in <- it:
		return true
	default:
		r.dropped.Add(1)
		return false
	}
}

// SubmitTraces enqueues spans (non-blocking; false when the queue is full).
func (r *Relay) SubmitTraces(rs []*tracepb.ResourceSpans) bool { return r.submit(item{spans: rs}) }

// SubmitLogs enqueues logs.
func (r *Relay) SubmitLogs(rl []*logspb.ResourceLogs) bool { return r.submit(item{logs: rl}) }

// SubmitMetrics enqueues metrics.
func (r *Relay) SubmitMetrics(rm []*metricspb.ResourceMetrics) bool {
	return r.submit(item{metrics: rm})
}

// EmitMetrics implements metrics.Emitter.
func (r *Relay) EmitMetrics(rm *metricspb.ResourceMetrics) {
	r.SubmitMetrics([]*metricspb.ResourceMetrics{rm})
}

// Flush forces pending batches out to the exporter queue and waits until they were handled
// (exported or buffered to disk).
func (r *Relay) Flush(ctx context.Context) {
	done := make(chan struct{})
	select {
	case r.flush <- done:
	case <-ctx.Done():
		return
	}
	select {
	case <-done:
	case <-ctx.Done():
	}
}

// Run processes until ctx is cancelled; pending data is written to the disk buffer on shutdown.
func (r *Relay) Run(ctx context.Context) {
	var wg sync.WaitGroup
	wg.Add(2)
	go func() { defer wg.Done(); r.exportLoop(ctx) }()
	go func() { defer wg.Done(); r.replayLoop(ctx) }()

	var (
		spans   []*tracepb.ResourceSpans
		logs    []*logspb.ResourceLogs
		metrics []*metricspb.ResourceMetrics
	)
	tick := time.NewTicker(r.opts.FlushInterval)
	defer tick.Stop()
	emit := func(sig Signal, sync chan struct{}) {
		var body []byte
		var err error
		switch sig {
		case Traces:
			if len(spans) == 0 {
				return
			}
			body, err = EncodeTraces(spans)
			spans = nil
		case Logs:
			if len(logs) == 0 {
				return
			}
			body, err = EncodeLogs(logs)
			logs = nil
		case Metrics:
			if len(metrics) == 0 {
				return
			}
			body, err = EncodeMetrics(metrics)
			metrics = nil
		}
		if err != nil {
			r.log.Warn("otlp encode failed", "signal", sig, "err", err)
			return
		}
		e := encoded{sig, body}
		if sync != nil || ctx.Err() != nil {
			// Synchronous path (Flush / shutdown).
			r.deliver(context.WithoutCancel(ctx), e)
			return
		}
		select {
		case r.out <- e:
		default:
			// Exporter is backed up: spill to disk instead of blocking intake.
			r.spill(e)
		}
	}
	emitAll := func(sync chan struct{}) {
		emit(Traces, sync)
		emit(Logs, sync)
		emit(Metrics, sync)
	}
	for {
		select {
		case <-ctx.Done():
			// Drain whatever is queued, then persist.
			for {
				select {
				case it := <-r.in:
					spans, logs, metrics = r.accept(it, spans, logs, metrics)
					continue
				default:
				}
				break
			}
			for _, sig := range []Signal{Traces, Logs, Metrics} {
				r.spillPending(sig, &spans, &logs, &metrics)
			}
			wg.Wait()
			return
		case it := <-r.in:
			spans, logs, metrics = r.accept(it, spans, logs, metrics)
			if len(spans) >= r.opts.BatchSize {
				emit(Traces, nil)
			}
			if len(logs) >= r.opts.BatchSize {
				emit(Logs, nil)
			}
			if len(metrics) >= r.opts.BatchSize {
				emit(Metrics, nil)
			}
		case <-tick.C:
			emitAll(nil)
		case done := <-r.flush:
			for {
				select {
				case it := <-r.in:
					spans, logs, metrics = r.accept(it, spans, logs, metrics)
					continue
				default:
				}
				break
			}
			// Let the async exporter finish what it holds, then deliver synchronously.
			r.drainOut(ctx)
			emitAll(done)
			close(done)
		}
	}
}

func (r *Relay) drainOut(ctx context.Context) {
	for {
		select {
		case e := <-r.out:
			r.deliver(context.WithoutCancel(ctx), e)
		default:
			return
		}
	}
}

func (r *Relay) spillPending(sig Signal, spans *[]*tracepb.ResourceSpans, logs *[]*logspb.ResourceLogs, metrics *[]*metricspb.ResourceMetrics) {
	var body []byte
	var err error
	switch sig {
	case Traces:
		if len(*spans) == 0 {
			return
		}
		body, err = EncodeTraces(*spans)
	case Logs:
		if len(*logs) == 0 {
			return
		}
		body, err = EncodeLogs(*logs)
	case Metrics:
		if len(*metrics) == 0 {
			return
		}
		body, err = EncodeMetrics(*metrics)
	}
	if err == nil {
		r.spill(encoded{sig, body})
	}
}

// accept enriches, tees and samples one intake item.
func (r *Relay) accept(it item, spans []*tracepb.ResourceSpans, logs []*logspb.ResourceLogs, metrics []*metricspb.ResourceMetrics) ([]*tracepb.ResourceSpans, []*logspb.ResourceLogs, []*metricspb.ResourceMetrics) {
	cfg := r.cfg.Load()
	if len(it.spans) > 0 {
		for _, rs := range it.spans {
			rs.Resource = Enrich(rs.Resource, cfg)
		}
		if r.opts.Observer != nil {
			r.opts.Observer.ObserveSpans(it.spans)
		}
		spans = append(spans, sample(it.spans, cfg.TracesRatio)...)
	}
	for _, rl := range it.logs {
		rl.Resource = Enrich(rl.Resource, cfg)
		logs = append(logs, rl)
	}
	for _, rm := range it.metrics {
		EnrichMetrics(rm, cfg)
		metrics = append(metrics, rm)
	}
	return spans, logs, metrics
}

func (r *Relay) exportLoop(ctx context.Context) {
	for {
		select {
		case <-ctx.Done():
			for {
				select {
				case e := <-r.out:
					r.spill(e)
				default:
					return
				}
			}
		case e := <-r.out:
			r.deliver(ctx, e)
		}
	}
}

// deliver tries a direct export (with short in-line retries); on failure the batch goes to disk.
func (r *Relay) deliver(ctx context.Context, e encoded) {
	// Preserve ordering-ish and avoid hammering a dead endpoint: when a backlog exists, append to it.
	if n, _, _ := r.buf.Stats(); n > 0 {
		r.spill(e)
		r.kickReplay()
		return
	}
	backoff := 100 * time.Millisecond
	for attempt := 0; attempt < 3; attempt++ {
		err := r.export(ctx, e.sig, e.body)
		if err == nil {
			return
		}
		var perm *permanentError
		if errors.As(err, &perm) {
			r.log.Warn("otlp export rejected; dropping batch", "signal", e.sig, "err", err)
			return
		}
		if attempt < 2 {
			select {
			case <-time.After(backoff):
			case <-ctx.Done():
				attempt = 3
			}
			backoff *= 2
		}
	}
	r.spill(e)
}

func (r *Relay) spill(e encoded) {
	if err := r.buf.Write(e.sig, e.body); err != nil {
		r.log.Warn("otlp disk buffer write failed", "err", err)
	}
}

func (r *Relay) kickReplay() {
	select {
	case r.replayNow <- struct{}{}:
	default:
	}
}

func (r *Relay) replayLoop(ctx context.Context) {
	delay := r.opts.ReplayMin
	timer := time.NewTimer(delay)
	defer timer.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-timer.C:
		case <-r.replayNow:
		}
		ok := r.replayOnce(ctx)
		if ok {
			delay = r.opts.ReplayMin
		} else {
			delay *= 2
			if delay > r.opts.ReplayMax {
				delay = r.opts.ReplayMax
			}
		}
		if !timer.Stop() {
			select {
			case <-timer.C:
			default:
			}
		}
		timer.Reset(delay)
	}
}

// replayOnce sends buffered segments oldest-first; returns false when an export failed.
func (r *Relay) replayOnce(ctx context.Context) bool {
	if r.cfg.Load().Endpoint == "" {
		return true
	}
	for ctx.Err() == nil {
		name, sig, data, ok := r.buf.Oldest()
		if !ok {
			return true
		}
		err := r.export(ctx, sig, data)
		var perm *permanentError
		if err != nil && !errors.As(err, &perm) {
			return false
		}
		r.buf.Remove(name)
	}
	return true
}

type permanentError struct{ err error }

func (p *permanentError) Error() string { return p.err.Error() }

// ReplayNow synchronously replays the disk buffer (tests / after reconfigure).
func (r *Relay) ReplayNow(ctx context.Context) bool { return r.replayOnce(ctx) }

func (r *Relay) export(ctx context.Context, sig Signal, body []byte) error {
	cfg := r.cfg.Load()
	if cfg.Endpoint == "" {
		return errors.New("no OTLP endpoint configured")
	}
	var zbuf bytes.Buffer
	zw := gzip.NewWriter(&zbuf)
	_, _ = zw.Write(body)
	_ = zw.Close()
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, SignalURL(cfg.Endpoint, sig), &zbuf)
	if err != nil {
		return &permanentError{err}
	}
	req.Header.Set("Content-Type", "application/x-protobuf")
	req.Header.Set("Content-Encoding", "gzip")
	for k, v := range cfg.Headers {
		req.Header.Set(k, v)
	}
	resp, err := r.client.Do(req)
	if err != nil {
		return err
	}
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 64<<10))
	resp.Body.Close()
	switch {
	case resp.StatusCode >= 200 && resp.StatusCode < 300:
		r.exported.Add(1)
		return nil
	case resp.StatusCode == 429 || resp.StatusCode == 502 || resp.StatusCode == 503 || resp.StatusCode == 504 || resp.StatusCode >= 500:
		return fmt.Errorf("otlp export: HTTP %d", resp.StatusCode)
	default:
		return &permanentError{fmt.Errorf("otlp export: HTTP %d", resp.StatusCode)}
	}
}

// SignalURL joins the OTLP base endpoint and the signal path. An endpoint that already ends with a
// /v1/<signal> path is used as-is for that signal.
func SignalURL(endpoint string, sig Signal) string {
	e := strings.TrimRight(endpoint, "/")
	for _, s := range []Signal{Traces, Logs, Metrics} {
		if strings.HasSuffix(e, s.Path()) {
			e = strings.TrimSuffix(e, s.Path())
			break
		}
	}
	return e + sig.Path()
}
