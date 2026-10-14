package logs

import (
	"bufio"
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/url"
	"os"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/obs"
)

// Docker follows container logs via the Engine API over its unix socket (no SDK).
type Docker struct {
	socket string
	sink   obs.Sink
	log    *slog.Logger
	client *http.Client
	// Interval between container list refreshes.
	Interval time.Duration

	mu      sync.Mutex
	enabled bool
	labels  map[string]string
	active  map[string]context.CancelFunc
	since   map[string]time.Time
}

// NewDocker creates a follower (disabled until Configure(true, …)).
func NewDocker(socket string, sink obs.Sink, log *slog.Logger) *Docker {
	if log == nil {
		log = slog.Default()
	}
	return &Docker{
		socket: socket, sink: sink, log: log, Interval: 10 * time.Second,
		client: &http.Client{Transport: &http.Transport{
			DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
				var d net.Dialer
				return d.DialContext(ctx, "unix", socket)
			},
		}},
		active: map[string]context.CancelFunc{}, since: map[string]time.Time{},
	}
}

// Configure hot-reloads enablement and the label selector.
func (d *Docker) Configure(enabled bool, labels map[string]string) {
	d.mu.Lock()
	defer d.mu.Unlock()
	changed := d.enabled != enabled || fmt.Sprint(sortedKV(d.labels)) != fmt.Sprint(sortedKV(labels))
	d.enabled = enabled
	d.labels = labels
	if changed {
		for id, c := range d.active {
			c()
			delete(d.active, id)
		}
	}
}

func sortedKV(m map[string]string) []string {
	var out []string
	for k, v := range m {
		out = append(out, k+"="+v)
	}
	sort.Strings(out)
	return out
}

// Run refreshes the container set until ctx is done.
func (d *Docker) Run(ctx context.Context) {
	for {
		d.Sync(ctx)
		select {
		case <-ctx.Done():
			d.mu.Lock()
			for id, c := range d.active {
				c()
				delete(d.active, id)
			}
			d.mu.Unlock()
			return
		case <-time.After(d.Interval):
		}
	}
}

type containerSummary struct {
	ID      string            `json:"Id"`
	Names   []string          `json:"Names"`
	Labels  map[string]string `json:"Labels"`
	Created int64             `json:"Created"`
}

// freshContainer is how recent a container must be for its logs to be read from the start on first attach
// (containers created by a deploy between two container-list refreshes keep their startup lines).
const freshContainer = 2 * time.Minute

// Sync starts followers for new running containers and stops those that disappeared.
func (d *Docker) Sync(ctx context.Context) {
	d.mu.Lock()
	enabled, labels := d.enabled, d.labels
	d.mu.Unlock()
	if !enabled {
		return
	}
	if _, err := os.Stat(d.socket); err != nil {
		return
	}
	cs, err := d.list(ctx, labels)
	if err != nil {
		d.log.Debug("docker list failed", "err", err)
		return
	}
	live := map[string]bool{}
	d.mu.Lock()
	defer d.mu.Unlock()
	for _, c := range cs {
		live[c.ID] = true
		if _, ok := d.active[c.ID]; ok {
			continue
		}
		fctx, cancel := context.WithCancel(ctx)
		d.active[c.ID] = cancel
		go d.follow(fctx, c)
	}
	for id, cancel := range d.active {
		if !live[id] {
			cancel()
			delete(d.active, id)
			delete(d.since, id)
		}
	}
}

// Active returns followed container ids.
func (d *Docker) Active() []string {
	d.mu.Lock()
	defer d.mu.Unlock()
	var out []string
	for id := range d.active {
		out = append(out, id)
	}
	sort.Strings(out)
	return out
}

func (d *Docker) list(ctx context.Context, labels map[string]string) ([]containerSummary, error) {
	q := url.Values{}
	if len(labels) > 0 {
		f, _ := json.Marshal(map[string][]string{"label": sortedKV(labels)})
		q.Set("filters", string(f))
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "http://docker/containers/json?"+q.Encode(), nil)
	resp, err := d.client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("docker: GET /containers/json: HTTP %d", resp.StatusCode)
	}
	var cs []containerSummary
	return cs, json.NewDecoder(resp.Body).Decode(&cs)
}

func (d *Docker) follow(ctx context.Context, c containerSummary) {
	name := c.ID[:min(12, len(c.ID))]
	if len(c.Names) > 0 {
		name = strings.TrimPrefix(c.Names[0], "/")
	}
	site := c.Labels["falak.site"]
	service := c.Labels["falak.service"]
	// Compose sites label every service with falak.site=<slug> and falak.service=<compose service>: the
	// records belong to the site (service.name=<slug>) and carry the compose service as an attribute.
	composeService := ""
	if site != "" && service != "" {
		composeService, service = service, ""
	}
	if service == "" && site == "" {
		service = name
	}
	if created := time.Unix(c.Created, 0); c.Created > 0 && time.Since(created) < freshContainer {
		d.mu.Lock()
		if d.since[c.ID].IsZero() {
			d.since[c.ID] = created.Add(-time.Nanosecond)
		}
		d.mu.Unlock()
	}
	backoff := time.Second
	filter := dbLineFilter(c.Labels) // PostgreSQL: no SQL text or values (dbredact.go)
	for ctx.Err() == nil {
		err := d.stream(ctx, c.ID, func(stream string, ts time.Time, line string) {
			d.mu.Lock()
			if ts.After(d.since[c.ID]) {
				d.since[c.ID] = ts
			}
			d.mu.Unlock()
			if filter != nil {
				var keep bool
				if line, keep = filter(line); !keep {
					return
				}
			}
			rec := ParseLine([]byte(line), "")
			rec.Time = ts
			rec.Site, rec.Service = site, service
			if rec.Attrs == nil {
				rec.Attrs = map[string]string{}
			}
			rec.Attrs["container.id"] = c.ID
			rec.Attrs["container.name"] = name
			rec.Attrs["log.iostream"] = stream
			// Database containers (docs/plans/OBSERVABILITY.md phase 3): the engine's log, queryable per instance.
			if inst := c.Labels["falak.db.instance"]; inst != "" {
				rec.Kind, rec.Attrs["falak.db.instance.id"] = "db.engine", inst
			}
			if composeService != "" {
				rec.Attrs["falak.compose.service"] = composeService
				if r := c.Labels["falak.release"]; r != "" {
					rec.Attrs["falak.release.id"] = r
				}
			}
			d.sink.EmitLog(rec)
		})
		if ctx.Err() != nil {
			return
		}
		if err != nil && !errors.Is(err, io.EOF) {
			d.log.Debug("docker log stream ended", "container", name, "err", err)
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(backoff):
		}
		backoff = min(backoff*2, 30*time.Second)
	}
}

func (d *Docker) stream(ctx context.Context, id string, fn func(stream string, ts time.Time, line string)) error {
	tty := false
	if req, err := http.NewRequestWithContext(ctx, http.MethodGet, "http://docker/containers/"+id+"/json", nil); err == nil {
		if resp, err := d.client.Do(req); err == nil {
			var info struct {
				Config struct{ Tty bool } `json:"Config"`
			}
			_ = json.NewDecoder(resp.Body).Decode(&info)
			resp.Body.Close()
			tty = info.Config.Tty
		}
	}
	d.mu.Lock()
	since := d.since[id]
	d.mu.Unlock()
	q := url.Values{"follow": {"1"}, "stdout": {"1"}, "stderr": {"1"}, "timestamps": {"1"}}
	if since.IsZero() {
		q.Set("tail", "0") // only new lines on first attach
	} else {
		q.Set("since", fmt.Sprintf("%d.%09d", since.Unix(), since.Nanosecond()+1))
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "http://docker/containers/"+id+"/logs?"+q.Encode(), nil)
	resp, err := d.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return fmt.Errorf("docker logs: HTTP %d", resp.StatusCode)
	}
	emit := func(stream string, line []byte) {
		ts, msg := splitTimestamp(string(line))
		fn(stream, ts, msg)
	}
	if tty {
		return scanLines(resp.Body, func(l []byte) { emit("stdout", l) })
	}
	return Demux(resp.Body, emit)
}

// Demux splits Docker's multiplexed log stream: frames of [stream(1) 0 0 0 size(4, BE)] + payload.
// fn receives complete lines (a line may span frames).
func Demux(r io.Reader, fn func(stream string, line []byte)) error {
	br := bufio.NewReaderSize(r, 64<<10)
	var hdr [8]byte
	partial := map[string][]byte{}
	defer func() {
		for s, p := range partial {
			if len(p) > 0 {
				fn(s, p)
			}
		}
	}()
	for {
		if _, err := io.ReadFull(br, hdr[:]); err != nil {
			if errors.Is(err, io.ErrUnexpectedEOF) {
				return io.EOF
			}
			return err
		}
		stream := "stdout"
		switch hdr[0] {
		case 0, 1:
		case 2:
			stream = "stderr"
		default:
			return fmt.Errorf("docker: bad stream header %v", hdr)
		}
		size := binary.BigEndian.Uint32(hdr[4:])
		if size > 16<<20 {
			return fmt.Errorf("docker: frame too large (%d)", size)
		}
		payload := make([]byte, size)
		if _, err := io.ReadFull(br, payload); err != nil {
			return err
		}
		data := append(partial[stream], payload...)
		for {
			i := indexByte(data, '\n')
			if i < 0 {
				break
			}
			fn(stream, data[:i])
			data = data[i+1:]
		}
		if len(data) >= MaxLine {
			fn(stream, data)
			data = nil
		}
		partial[stream] = append([]byte(nil), data...)
	}
}

func indexByte(b []byte, c byte) int {
	for i, x := range b {
		if x == c {
			return i
		}
	}
	return -1
}

func scanLines(r io.Reader, fn func([]byte)) error {
	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 64<<10), MaxLine*2)
	for sc.Scan() {
		fn(sc.Bytes())
	}
	if err := sc.Err(); err != nil {
		return err
	}
	return io.EOF
}

// splitTimestamp separates the RFC3339Nano prefix added by timestamps=1.
func splitTimestamp(line string) (time.Time, string) {
	line = strings.TrimRight(line, "\r")
	if i := strings.IndexByte(line, ' '); i > 0 {
		if ts, err := time.Parse(time.RFC3339Nano, line[:i]); err == nil {
			return ts, line[i+1:]
		}
	}
	return time.Now(), line
}
