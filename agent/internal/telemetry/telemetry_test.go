package telemetry

import (
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/otlp"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
)

type posts struct {
	mu   sync.Mutex
	body []string
}

func (p *posts) PostInsights(_ context.Context, b []byte) error {
	p.mu.Lock()
	p.body = append(p.body, string(b))
	p.mu.Unlock()
	return nil
}

func (p *posts) all() string {
	p.mu.Lock()
	defer p.mu.Unlock()
	return strings.Join(p.body, "")
}

func newService(t *testing.T, endpoint string, ins *posts) (*Service, hostfs.FS) {
	fs := hostfs.FS{Root: t.TempDir()}
	s, err := New(Options{FS: fs, EtcDir: "/etc/falak", StateDir: "/var/lib/falak", HTTPAddr: "127.0.0.1:0",
		Endpoint: endpoint, ServerID: "01SERVER", HostName: "web-1", Insights: ins, FlushInterval: 20 * time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}
	return s, fs
}

func examplePayload(t *testing.T) json.RawMessage {
	b, err := os.ReadFile("../../testdata/command-examples/telemetry.configure.json")
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func TestConfigurePersistsAndIsIdempotent(t *testing.T) {
	s, fs := newService(t, "https://enrolled.example:4318", nil)
	reg := commands.NewRegistry()
	s.Register(reg)
	ex, _ := reg.Get("telemetry.configure")
	env := commands.Envelope{ID: "01", Type: "telemetry.configure", Payload: examplePayload(t)}
	sink := &commands.Collector{}
	st := commands.NewTestStream("01", sink)

	res, err := ex.Execute(context.Background(), env, st)
	if err != nil {
		t.Fatal(err)
	}
	if !res.(Result).Changed {
		t.Fatal("first apply should change")
	}
	info, err := os.Stat(fs.P("/etc/falak/telemetry.json"))
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("persisted file: %v %v", info, err)
	}
	res, _ = ex.Execute(context.Background(), env, st)
	if res.(Result).Changed {
		t.Fatal("second apply must be a no-op")
	}
	cfg := s.Relay().Config()
	if cfg.Endpoint != "https://otel.falak.example:4318" || cfg.TracesRatio != 0.25 || cfg.OrgID == "" || len(cfg.Sites) != 1 || cfg.BufferMax != 67108864 {
		t.Fatalf("relay config not applied: %+v", cfg)
	}
	// The workload collector gets the sites too (slice keys → slugs, native sites measured by their unix user).
	wsites := s.workloads.Sites()
	if len(wsites) != 1 || wsites[0].UnixUser != "shop" || wsites[0].Runtime != "php-fpm" {
		t.Fatalf("workload sites not applied: %+v", wsites)
	}
	// Reload from disk on a fresh service.
	s2, err := New(Options{FS: fs, EtcDir: "/etc/falak", StateDir: "/var/lib/falak", Endpoint: "https://enrolled.example:4318"})
	if err != nil {
		t.Fatal(err)
	}
	if got := s2.Relay().Config(); got.Endpoint != cfg.Endpoint || len(got.Sites) != 1 {
		t.Fatalf("not reloaded: %+v", got)
	}
	// Invalid payloads are rejected as payload errors.
	bad := commands.Envelope{ID: "02", Payload: json.RawMessage(`{"sampling":{"traces_ratio":2}}`)}
	if _, err := ex.Execute(context.Background(), bad, st); !commands.IsPayloadError(err) {
		t.Fatalf("expected payload error, got %v", err)
	}
	unknown := commands.Envelope{ID: "03", Payload: json.RawMessage(`{"nope":1}`)}
	if _, err := ex.Execute(context.Background(), unknown, st); !commands.IsPayloadError(err) {
		t.Fatalf("expected unknown-field payload error, got %v", err)
	}
}

// End to end: app → receiver → relay (+insights tee) → collector.
func TestServiceEndToEnd(t *testing.T) {
	var mu sync.Mutex
	var got []*tracepb.ResourceSpans
	col := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		zr, _ := gzip.NewReader(r.Body)
		b, _ := io.ReadAll(zr)
		if r.URL.Path == "/v1/traces" {
			rs, _ := otlp.DecodeTraces(b)
			mu.Lock()
			got = append(got, rs...)
			mu.Unlock()
		}
	}))
	defer col.Close()
	ins := &posts{}
	s, _ := newService(t, col.URL, ins)
	reg := commands.NewRegistry()
	s.Register(reg)
	ex, _ := reg.Get("telemetry.configure")
	if _, err := ex.Execute(context.Background(), commands.Envelope{Payload: json.RawMessage(`{"sites":[{"slug":"shop","site_id":"01SITE"}],"metrics":{"enabled":false}}`)}, commands.NewTestStream("x", &commands.Collector{})); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	if err := s.Start(ctx); err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	body, _ := otlp.EncodeTraces([]*tracepb.ResourceSpans{{
		Resource: &resourcepb.Resource{Attributes: []*commonpb.KeyValue{otlp.Str("service.name", "shop")}},
		ScopeSpans: []*tracepb.ScopeSpans{{Spans: []*tracepb.Span{{
			TraceId: bytes.Repeat([]byte{7}, 16), SpanId: bytes.Repeat([]byte{8}, 8), Name: "GET /",
			StartTimeUnixNano: uint64(now.Add(-time.Second).UnixNano()), EndTimeUnixNano: uint64(now.UnixNano()),
			Attributes: []*commonpb.KeyValue{otlp.Str("falak.event.type", "request")},
			Events:     []*tracepb.Span_Event{{Name: "exception", Attributes: []*commonpb.KeyValue{otlp.Str("exception.type", "RuntimeException")}}},
		}}}},
	}})
	resp, err := http.Post("http://"+s.Listeners().TCP+"/v1/traces", "application/x-protobuf", bytes.NewReader(body))
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("post: %v %v", resp, err)
	}
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		mu.Lock()
		n := len(got)
		mu.Unlock()
		if n == 1 && strings.Contains(ins.all(), `"type":"RuntimeException"`) {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(got) != 1 {
		t.Fatalf("exported %d", len(got))
	}
	if v, _ := otlp.Lookup(got[0].Resource.Attributes, "falak.site.id"); v != "01SITE" {
		t.Fatalf("not enriched: %v", got[0].Resource.Attributes)
	}
	if !strings.Contains(ins.all(), `"site_id":"01SITE"`) {
		t.Fatalf("insights: %s", ins.all())
	}
	if sum := s.Summary(); sum.CPUPercent != 0 {
		t.Fatalf("no /proc under temp root, summary should be zero: %+v", sum)
	}
}

func TestSetHostNameRefreshesSignals(t *testing.T) {
	s, _ := newService(t, "https://enrolled.example:4318", nil)
	s.SetHostName("ip-10-0-0-5")
	if got := s.Relay().Config().HostName; got != "ip-10-0-0-5" {
		t.Fatalf("host.name = %q", got)
	}
	s.SetHostName("")
	if got := s.Relay().Config().HostName; got != "ip-10-0-0-5" {
		t.Fatalf("empty hostname must be ignored, got %q", got)
	}
}
