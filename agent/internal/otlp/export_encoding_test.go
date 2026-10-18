package otlp

import (
	"bytes"
	"compress/gzip"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"

	"google.golang.org/protobuf/encoding/protowire"
	"google.golang.org/protobuf/proto"

	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

// decodeExportMetricsServiceRequest strictly decodes the OTLP ExportMetricsServiceRequest wire format:
// the message has exactly one field, `repeated ResourceMetrics resource_metrics = 1`. Any other field
// number/wire type (e.g. a JSON body, whose '{' is 0x7b = field 15/start-group) is rejected.
func decodeExportMetricsServiceRequest(b []byte) ([]*metricspb.ResourceMetrics, error) {
	var out []*metricspb.ResourceMetrics
	if len(b) == 0 {
		return nil, io.ErrUnexpectedEOF
	}
	for len(b) > 0 {
		num, typ, n := protowire.ConsumeTag(b)
		if n < 0 {
			return nil, protowire.ParseError(n)
		}
		if num != 1 || typ != protowire.BytesType {
			return nil, &unexpectedField{num, typ}
		}
		b = b[n:]
		v, n := protowire.ConsumeBytes(b)
		if n < 0 {
			return nil, protowire.ParseError(n)
		}
		rm := &metricspb.ResourceMetrics{}
		if err := proto.Unmarshal(v, rm); err != nil {
			return nil, err
		}
		out = append(out, rm)
		b = b[n:]
	}
	return out, nil
}

type unexpectedField struct {
	num protowire.Number
	typ protowire.Type
}

func (e *unexpectedField) Error() string { return "unexpected field in ExportMetricsServiceRequest" }

// VictoriaMetrics only accepts OTLP metrics as protobuf: every metrics export — whether the input
// arrived as JSON or protobuf, live or replayed from the disk buffer — must be application/x-protobuf.
func TestMetricsAlwaysExportedAsProtobuf(t *testing.T) {
	var (
		mu      sync.Mutex
		names   []string
		down    atomic.Bool
		badReqs atomic.Int32
	)
	vm := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if down.Load() {
			http.Error(w, "down", 503)
			return
		}
		if r.URL.Path != "/v1/metrics" {
			w.WriteHeader(200) // traces/logs not under test here
			return
		}
		if ct := r.Header.Get("Content-Type"); ct != "application/x-protobuf" {
			badReqs.Add(1)
			http.Error(w, "json encoding isn't supported for opentelemetry format", 400)
			return
		}
		var body io.Reader = r.Body
		if r.Header.Get("Content-Encoding") == "gzip" {
			zr, err := gzip.NewReader(r.Body)
			if err != nil {
				badReqs.Add(1)
				http.Error(w, err.Error(), 400)
				return
			}
			body = zr
		}
		raw, _ := io.ReadAll(body)
		rms, err := decodeExportMetricsServiceRequest(raw)
		if err != nil {
			badReqs.Add(1)
			http.Error(w, err.Error(), 400)
			return
		}
		mu.Lock()
		for _, rm := range rms {
			for _, sm := range rm.ScopeMetrics {
				for _, m := range sm.Metrics {
					names = append(names, m.Name)
				}
			}
		}
		mu.Unlock()
	}))
	defer vm.Close()

	r, _ := testRelay(t, nil)
	r.Configure(Config{Endpoint: vm.URL, TracesRatio: 1})
	h := r.Handler()
	post := func(ct, body string) {
		req := httptest.NewRequest("POST", "/v1/metrics", strings.NewReader(body))
		req.Header.Set("Content-Type", ct)
		rec := httptest.NewRecorder()
		h.ServeHTTP(rec, req)
		if rec.Code != 200 {
			t.Fatalf("receiver %s: %d %s", ct, rec.Code, rec.Body)
		}
	}
	has := func(name string) bool {
		mu.Lock()
		defer mu.Unlock()
		for _, n := range names {
			if n == name {
				return true
			}
		}
		return false
	}

	// 1. JSON in (e.g. @othmanhaba/apm-node) → protobuf out.
	post("application/json", jsonMetrics)
	waitFor(t, "JSON-ingested metric exported", func() bool { return has("queue.depth") })

	// 2. Protobuf in, exported while the backend is down → spilled to disk → replayed as protobuf.
	down.Store(true)
	pb, err := EncodeMetrics([]*metricspb.ResourceMetrics{{ScopeMetrics: []*metricspb.ScopeMetrics{{Metrics: []*metricspb.Metric{{
		Name: "replayed.metric", Data: &metricspb.Metric_Gauge{Gauge: &metricspb.Gauge{DataPoints: []*metricspb.NumberDataPoint{{Value: &metricspb.NumberDataPoint_AsInt{AsInt: 1}}}}},
	}}}}}})
	if err != nil {
		t.Fatal(err)
	}
	req := httptest.NewRequest("POST", "/v1/metrics", bytes.NewReader(pb))
	req.Header.Set("Content-Type", "application/x-protobuf")
	h.ServeHTTP(httptest.NewRecorder(), req)
	waitFor(t, "spill to disk buffer", func() bool { n, _, _ := r.Buffer().Stats(); return n > 0 })
	down.Store(false)
	waitFor(t, "replayed metric exported", func() bool { return has("replayed.metric") })

	if n := badReqs.Load(); n != 0 {
		t.Fatalf("%d metrics exports were not valid protobuf ExportMetricsServiceRequests", n)
	}
	// The strict decoder really rejects JSON bodies.
	if _, err := decodeExportMetricsServiceRequest([]byte(jsonMetrics)); err == nil {
		t.Fatal("test decoder accepted JSON")
	}
}
