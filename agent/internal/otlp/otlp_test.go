package otlp

import (
	"bytes"
	"compress/gzip"
	"context"
	"encoding/hex"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/obs"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	logspb "go.opentelemetry.io/proto/otlp/logs/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
)

// collector is a fake observability box decoding exports.
type collector struct {
	mu      sync.Mutex
	spans   []*tracepb.ResourceSpans
	logs    []*logspb.ResourceLogs
	metrics []*metricspb.ResourceMetrics
	headers []http.Header
	fail    atomic.Bool
	srv     *httptest.Server
}

func newCollector(t *testing.T) *collector {
	c := &collector{}
	c.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if c.fail.Load() {
			http.Error(w, "down", http.StatusServiceUnavailable)
			return
		}
		var body io.Reader = r.Body
		if r.Header.Get("Content-Encoding") == "gzip" {
			zr, err := gzip.NewReader(r.Body)
			if err != nil {
				t.Errorf("gzip: %v", err)
				return
			}
			body = zr
		}
		data, _ := io.ReadAll(body)
		if ct := r.Header.Get("Content-Type"); ct != "application/x-protobuf" {
			t.Errorf("content-type %q", ct)
		}
		c.mu.Lock()
		defer c.mu.Unlock()
		c.headers = append(c.headers, r.Header.Clone())
		switch r.URL.Path {
		case "/v1/traces":
			rs, err := DecodeTraces(data)
			if err != nil {
				t.Errorf("decode: %v", err)
			}
			c.spans = append(c.spans, rs...)
		case "/v1/logs":
			rl, _ := DecodeLogs(data)
			c.logs = append(c.logs, rl...)
		case "/v1/metrics":
			rm, _ := DecodeMetrics(data)
			c.metrics = append(c.metrics, rm...)
		default:
			t.Errorf("unexpected path %s", r.URL.Path)
		}
	}))
	t.Cleanup(c.srv.Close)
	return c
}

func (c *collector) counts() (int, int, int) {
	c.mu.Lock()
	defer c.mu.Unlock()
	return len(c.spans), len(c.logs), len(c.metrics)
}

func waitFor(t *testing.T, what string, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if cond() {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatalf("timed out waiting for %s", what)
}

func testRelay(t *testing.T, obsv SpanObserver) (*Relay, context.CancelFunc) {
	t.Helper()
	r, err := NewRelay(Options{BufferDir: filepath.Join(t.TempDir(), "buf"), FlushInterval: 20 * time.Millisecond, Observer: obsv,
		ReplayMin: 20 * time.Millisecond, ReplayMax: 50 * time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { r.Run(ctx); close(done) }()
	t.Cleanup(func() { cancel(); <-done })
	return r, cancel
}

var (
	traceID = mustHex("5b8efff798038103d269b633813fc60c")
	spanID  = mustHex("eee19b7ec3c1b174")
)

func mustHex(s string) []byte {
	b, err := hex.DecodeString(s)
	if err != nil {
		panic(err)
	}
	return b
}

func sampleSpans(service string) []*tracepb.ResourceSpans {
	return []*tracepb.ResourceSpans{{
		Resource: &resourcepb.Resource{Attributes: []*commonpb.KeyValue{Str("service.name", service)}},
		ScopeSpans: []*tracepb.ScopeSpans{{Spans: []*tracepb.Span{{
			TraceId: traceID, SpanId: spanID, Name: "GET /orders", Kind: tracepb.Span_SPAN_KIND_SERVER,
			StartTimeUnixNano: 1, EndTimeUnixNano: 2,
			Attributes: []*commonpb.KeyValue{Str("falak.event.type", "request")},
		}}}},
	}}
}

const jsonTraces = `{"resourceSpans":[{"resource":{"attributes":[{"key":"service.name","value":{"stringValue":"shop"}}]},
 "scopeSpans":[{"scope":{"name":"falak/apm-laravel"},"spans":[{"traceId":"5b8efff798038103d269b633813fc60c","spanId":"eee19b7ec3c1b174",
 "parentSpanId":"","name":"GET /orders","kind":2,"startTimeUnixNano":"1700000000000000000","endTimeUnixNano":"1700000000100000000",
 "attributes":[{"key":"http.response.status_code","value":{"intValue":"200"}}],"status":{"code":"STATUS_CODE_OK"}}]}]}]}`

const jsonLogs = `{"resourceLogs":[{"resource":{"attributes":[{"key":"service.name","value":{"stringValue":"shop"}}]},
 "scopeLogs":[{"logRecords":[{"timeUnixNano":"1700000000000000000","severityNumber":17,"severityText":"ERROR","body":{"stringValue":"boom"},
 "traceId":"5b8efff798038103d269b633813fc60c","spanId":"eee19b7ec3c1b174"}]}]}]}`

const jsonMetrics = `{"resourceMetrics":[{"resource":{"attributes":[{"key":"service.name","value":{"stringValue":"shop"}}]},
 "scopeMetrics":[{"metrics":[{"name":"queue.depth","gauge":{"dataPoints":[{"asInt":"7","timeUnixNano":"1700000000000000000"}]}}]}]}]}`

func unixClient(sock string) *http.Client {
	return &http.Client{Transport: &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
		var d net.Dialer
		return d.DialContext(ctx, "unix", sock)
	}}}
}

func TestReceiverExportRoundtrip(t *testing.T) {
	col := newCollector(t)
	r, _ := testRelay(t, nil)
	r.Configure(Config{Endpoint: col.srv.URL, Headers: map[string]string{"Authorization": "Bearer x"}, ServerID: "01SERVER", HostName: "web-1",
		Environment: "production", TracesRatio: 1,
		Sites: []Site{{Slug: "shop", SiteID: "01SITE", DeploymentID: "01DEP", ReleaseID: "01REL"}}})

	sock := filepath.Join(os.TempDir(), fmt.Sprintf("falak-otlp-%d.sock", time.Now().UnixNano()))
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	ls, err := r.Serve(ctx, sock, "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	pbTraces, _ := EncodeTraces(sampleSpans("shop"))

	cases := []struct {
		name, transport, path, ct string
		body                      []byte
		gzip                      bool
	}{
		{"traces pb unix", "unix", "/v1/traces", "application/x-protobuf", pbTraces, false},
		{"traces pb tcp gzip", "tcp", "/v1/traces", "application/x-protobuf", pbTraces, true},
		{"traces json unix", "unix", "/v1/traces", "application/json", []byte(jsonTraces), false},
		{"traces json tcp", "tcp", "/v1/traces", "application/json", []byte(jsonTraces), false},
		{"logs json tcp", "tcp", "/v1/logs", "application/json", []byte(jsonLogs), false},
		{"metrics json unix", "unix", "/v1/metrics", "application/json", []byte(jsonMetrics), false},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			client, base := http.DefaultClient, "http://"+ls.TCP
			if tc.transport == "unix" {
				client, base = unixClient(ls.Unix), "http://unix"
			}
			body := tc.body
			req, _ := http.NewRequest("POST", base+tc.path, nil)
			if tc.gzip {
				var b bytes.Buffer
				zw := gzip.NewWriter(&b)
				zw.Write(body)
				zw.Close()
				body = b.Bytes()
				req.Header.Set("Content-Encoding", "gzip")
			}
			req.Body = io.NopCloser(bytes.NewReader(body))
			req.Header.Set("Content-Type", tc.ct)
			resp, err := client.Do(req)
			if err != nil {
				t.Fatal(err)
			}
			rb, _ := io.ReadAll(resp.Body)
			resp.Body.Close()
			if resp.StatusCode != 200 {
				t.Fatalf("status %d: %s", resp.StatusCode, rb)
			}
			if tc.ct == "application/json" && string(rb) != "{}" {
				t.Fatalf("json response %q", rb)
			}
		})
	}
	waitFor(t, "export", func() bool { s, l, m := col.counts(); return s == 4 && l == 1 && m == 1 })

	col.mu.Lock()
	defer col.mu.Unlock()
	for _, rs := range col.spans {
		sp := rs.ScopeSpans[0].Spans[0]
		if !bytes.Equal(sp.TraceId, traceID) || !bytes.Equal(sp.SpanId, spanID) {
			t.Fatalf("ids not preserved: %x %x", sp.TraceId, sp.SpanId)
		}
		want := map[string]string{"service.name": "shop", "falak.site.id": "01SITE", "falak.server.id": "01SERVER", "host.name": "web-1",
			"falak.deployment.id": "01DEP", "falak.release.id": "01REL", "deployment.environment.name": "production"}
		for k, v := range want {
			if got, _ := Lookup(rs.Resource.Attributes, k); got != v {
				t.Errorf("resource %s = %q want %q", k, got, v)
			}
		}
	}
	lr := col.logs[0].ScopeLogs[0].LogRecords[0]
	if !bytes.Equal(lr.TraceId, traceID) || lr.Body.GetStringValue() != "boom" || lr.SeverityNumber != logspb.SeverityNumber_SEVERITY_NUMBER_ERROR {
		t.Fatalf("log record mangled: %v", lr)
	}
	if got := col.metrics[0].ScopeMetrics[0].Metrics[0].GetGauge().DataPoints[0].GetAsInt(); got != 7 {
		t.Fatalf("metric value %d", got)
	}
	if col.headers[0].Get("Authorization") != "Bearer x" {
		t.Fatal("export headers not applied")
	}
}

func TestReceiverRejects(t *testing.T) {
	r, _ := testRelay(t, nil)
	h := r.Handler()
	for _, tc := range []struct {
		method, ct string
		body       string
		want       int
	}{
		{"GET", "application/json", "", 405},
		{"POST", "text/plain", "x", 415},
		{"POST", "application/json", "{not json", 400},
		{"POST", "application/x-protobuf", "\xff\xff", 400},
	} {
		req := httptest.NewRequest(tc.method, "/v1/traces", bytes.NewBufferString(tc.body))
		req.Header.Set("Content-Type", tc.ct)
		rec := httptest.NewRecorder()
		h.ServeHTTP(rec, req)
		if rec.Code != tc.want {
			t.Errorf("%s %s: got %d want %d", tc.method, tc.ct, rec.Code, tc.want)
		}
	}
}

func TestEnrichNeverOverwrites(t *testing.T) {
	cfg := &Config{ServerID: "S", HostName: "h", Environment: "production", OrgID: "O",
		Sites: []Site{{Slug: "shop", SiteID: "SITE1", Environment: "staging"}}}
	res := &resourcepb.Resource{Attributes: []*commonpb.KeyValue{Str("falak.site.id", "SITE1"), Str("host.name", "custom")}}
	Enrich(res, cfg)
	get := func(k string) string { v, _ := Lookup(res.Attributes, k); return v }
	if get("service.name") != "shop" || get("host.name") != "custom" || get("deployment.environment.name") != "staging" || get("falak.org.id") != "O" {
		t.Fatalf("bad enrichment: %v", res.Attributes)
	}
	// Unknown site: only host-level attributes are added.
	res2 := Enrich(nil, cfg)
	if v, _ := Lookup(res2.Attributes, "falak.site.id"); v != "" {
		t.Fatal("site id invented")
	}
}

func TestEnrichMetricsLeavesReleaseOffWorkloads(t *testing.T) {
	cfg := &Config{ServerID: "S", Sites: []Site{{Slug: "shop", SiteID: "SITE1", DeploymentID: "D1", ReleaseID: "R1"}}}
	mk := func(scope string) *metricspb.ResourceMetrics {
		return &metricspb.ResourceMetrics{Resource: &resourcepb.Resource{Attributes: []*commonpb.KeyValue{Str("service.name", "shop")}},
			ScopeMetrics: []*metricspb.ScopeMetrics{{Scope: &commonpb.InstrumentationScope{Name: scope}}}}
	}
	workloads, app := mk(WorkloadsScope), mk("laravel")
	EnrichMetrics(workloads, cfg)
	EnrichMetrics(app, cfg)
	if v, _ := Lookup(workloads.Resource.Attributes, "falak.site.id"); v != "SITE1" {
		t.Fatal("workloads are still attributed to the site")
	}
	for _, k := range []string{"falak.deployment.id", "falak.release.id"} {
		if _, ok := Lookup(workloads.Resource.Attributes, k); ok {
			t.Fatalf("workload metrics carry %s: a deployment would split their series", k)
		}
		if _, ok := Lookup(app.Resource.Attributes, k); !ok {
			t.Fatalf("an app's own metrics keep %s", k)
		}
	}
}

func TestSamplingKeepsErrors(t *testing.T) {
	mk := func(tid byte, errored bool, exc bool) *tracepb.Span {
		id := bytes.Repeat([]byte{tid}, 16)
		sp := &tracepb.Span{TraceId: id}
		if errored {
			sp.Status = &tracepb.Status{Code: tracepb.Status_STATUS_CODE_ERROR}
		}
		if exc {
			sp.Events = []*tracepb.Span_Event{{Name: "exception"}}
		}
		return sp
	}
	rs := []*tracepb.ResourceSpans{{ScopeSpans: []*tracepb.ScopeSpans{{Spans: []*tracepb.Span{
		mk(0x00, false, false), // low id → kept at 0.5
		mk(0xff, false, false), // high id → dropped
		mk(0xff, true, false),  // error → kept
		mk(0xff, false, true),  // exception → kept
	}}}}}
	out := sample(rs, 0.5)
	if n := len(out[0].ScopeSpans[0].Spans); n != 3 {
		t.Fatalf("kept %d spans, want 3", n)
	}
	if out := sample([]*tracepb.ResourceSpans{{ScopeSpans: []*tracepb.ScopeSpans{{Spans: []*tracepb.Span{mk(0xff, false, false)}}}}}, 0); len(out) != 0 {
		t.Fatal("ratio 0 should drop resource")
	}
}

func TestDiskBufferWhenExporterDownThenReplay(t *testing.T) {
	col := newCollector(t)
	col.fail.Store(true)
	r, _ := testRelay(t, nil)
	r.Configure(Config{Endpoint: col.srv.URL, TracesRatio: 1})
	for i := 0; i < 3; i++ {
		r.SubmitTraces(sampleSpans("shop"))
		r.Flush(context.Background())
	}
	if n, sz, _ := r.Buffer().Stats(); n == 0 || sz == 0 {
		t.Fatalf("expected buffered segments, got %d (%d bytes)", n, sz)
	}
	if s, _, _ := col.counts(); s != 0 {
		t.Fatal("collector should have received nothing")
	}
	col.fail.Store(false)
	waitFor(t, "replay", func() bool { s, _, _ := col.counts(); return s == 3 })
	waitFor(t, "buffer drained", func() bool { n, _, _ := r.Buffer().Stats(); return n == 0 })
}

func TestDiskBufferBoundAndReopen(t *testing.T) {
	dir := t.TempDir()
	b, err := OpenDiskBuffer(dir, 250)
	if err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 5; i++ {
		if err := b.Write(Logs, bytes.Repeat([]byte{byte('a' + i)}, 100)); err != nil {
			t.Fatal(err)
		}
	}
	n, sz, dropped := b.Stats()
	if n != 2 || sz != 200 || dropped != 3 {
		t.Fatalf("stats n=%d sz=%d dropped=%d", n, sz, dropped)
	}
	if err := b.Write(Logs, make([]byte, 300)); err == nil {
		t.Fatal("oversized segment should be refused")
	}
	b2, _ := OpenDiskBuffer(dir, 250)
	_, sig, data, ok := b2.Oldest()
	if !ok || sig != Logs || data[0] != 'd' {
		t.Fatalf("reopen oldest: ok=%v sig=%s first=%c", ok, sig, data[0])
	}
}

func TestSinkEmitsAgentRecords(t *testing.T) {
	col := newCollector(t)
	var seen atomic.Int32
	r, _ := testRelay(t, observerFunc(func(rs []*tracepb.ResourceSpans) { seen.Add(int32(len(rs))) }))
	r.Configure(Config{Endpoint: col.srv.URL, TracesRatio: 0, Sites: []Site{{Slug: "shop", SiteID: "01SITE"}}})
	var sink obs.Sink = r
	sink.EmitLog(obs.LogRecord{Body: "worker started", Site: "shop", Severity: "warn", Attrs: map[string]string{"process.name": "queue"}})
	sink.EmitSpan(obs.Span{Name: "schedule:run", Site: "shop", Start: time.Now().Add(-time.Second), End: time.Now(),
		Attrs: map[string]any{"falak.event.type": "scheduled_task", "falak.schedule.status": "failed"}, Error: true})
	waitFor(t, "records", func() bool { s, l, _ := col.counts(); return s == 1 && l == 1 })
	if seen.Load() != 1 {
		t.Fatal("observer not called")
	}
	col.mu.Lock()
	defer col.mu.Unlock()
	if v, _ := Lookup(col.logs[0].Resource.Attributes, "falak.site.id"); v != "01SITE" {
		t.Fatalf("site not resolved for agent log: %v", col.logs[0].Resource.Attributes)
	}
	if col.logs[0].ScopeLogs[0].LogRecords[0].SeverityText != "WARN" {
		t.Fatal("severity")
	}
}

type observerFunc func([]*tracepb.ResourceSpans)

func (f observerFunc) ObserveSpans(rs []*tracepb.ResourceSpans) { f(rs) }

func TestSignalURL(t *testing.T) {
	for in, want := range map[string]string{
		"https://o.example:4318":           "https://o.example:4318/v1/logs",
		"https://o.example:4318/":          "https://o.example:4318/v1/logs",
		"https://o.example/otlp/v1/traces": "https://o.example/otlp/v1/logs",
		"https://o.example/otlp":           "https://o.example/otlp/v1/logs",
	} {
		if got := SignalURL(in, Logs); got != want {
			t.Errorf("%s → %s want %s", in, got, want)
		}
	}
}

func TestAgentRecordsGetExplicitSiteIDAndNoStaleDeployment(t *testing.T) {
	col := newCollector(t)
	r, _ := testRelay(t, nil)
	r.Configure(Config{Endpoint: col.srv.URL, TracesRatio: 1, HostName: "web-1",
		Sites: []Site{{Slug: "shop", SiteID: "01SITE", DeploymentID: "01OLDDEPLOY", ReleaseID: "01OLDRELEASE"}}})
	r.EmitLog(obs.LogRecord{Body: "deployment started", Service: AgentService, Site: "shop", SiteID: "01EXPLICIT",
		Attrs: map[string]string{"falak.deployment.id": "01NEWDEPLOY"}})
	r.EmitLog(obs.LogRecord{Body: "worker log", Site: "shop"})
	r.EmitLog(obs.LogRecord{Body: "GET / 200", Site: "shop", Kind: LogKindAccess})
	waitFor(t, "logs", func() bool { _, l, _ := col.counts(); return l == 3 })
	col.mu.Lock()
	defer col.mu.Unlock()
	byBody := map[string]*logspb.ResourceLogs{}
	for _, rl := range col.logs {
		byBody[rl.ScopeLogs[0].LogRecords[0].Body.GetStringValue()] = rl
	}
	agent := byBody["deployment started"].Resource.Attributes
	if v, _ := Lookup(agent, "falak.site.id"); v != "01EXPLICIT" {
		t.Fatalf("explicit site id not used: %v", agent)
	}
	if v, _ := Lookup(agent, "service.name"); v != AgentService {
		t.Fatalf("service.name %q", v)
	}
	if _, ok := Lookup(agent, "falak.deployment.id"); ok {
		t.Fatal("agent record must not get the site's active deployment id on its resource")
	}
	app := byBody["worker log"].Resource.Attributes
	if v, _ := Lookup(app, "falak.deployment.id"); v != "01OLDDEPLOY" {
		t.Fatalf("site workload logs should still carry the active deployment id: %v", app)
	}
	if v, _ := Lookup(app, "falak.log.kind"); v != LogKindApp {
		t.Fatalf("site records default to kind app: %v", app)
	}
	if _, ok := Lookup(agent, "falak.log.kind"); ok {
		t.Fatal("agent records have no log kind")
	}
	access := byBody["GET / 200"].Resource.Attributes
	if v, _ := Lookup(access, "falak.log.kind"); v != LogKindAccess {
		t.Fatalf("kind = %v", access)
	}
	if v, _ := Lookup(access, "service.name"); v != "shop" {
		t.Fatalf("service.name = %v", access)
	}
}
