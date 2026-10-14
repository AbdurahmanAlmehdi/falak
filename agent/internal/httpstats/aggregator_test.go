package httpstats

import (
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/logs"
	"github.com/OthmanHaba/falak/agent/internal/obs"
	"github.com/OthmanHaba/falak/agent/internal/otlp"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

func access(site, line string) obs.LogRecord {
	rec := logs.ParseAccessLine([]byte(line))
	rec.Site = site
	return rec
}

// points flattens a metric's number points into attribute value → value.
func points(t *testing.T, rm *metricspb.ResourceMetrics, name string) map[string]int64 {
	t.Helper()
	out := map[string]int64{}
	for _, m := range rm.ScopeMetrics[0].Metrics {
		if m.Name != name {
			continue
		}
		sum := m.GetSum()
		if sum == nil || !sum.IsMonotonic || sum.AggregationTemporality != metricspb.AggregationTemporality_AGGREGATION_TEMPORALITY_CUMULATIVE {
			t.Fatalf("%s is not a cumulative monotonic sum", name)
		}
		for _, p := range sum.DataPoints {
			key := ""
			for _, kv := range p.Attributes {
				key = kv.Value.GetStringValue()
			}
			out[key] = p.GetAsInt()
		}
	}
	return out
}

func TestAggregatorCountsPerSite(t *testing.T) {
	a := New(nil)
	now := time.Unix(1_760_000_000, 0)
	a.now = func() time.Time { return now }
	h2tls := `"proto":"HTTP/2.0","tls":{"version":772,"cipher_suite":4865}`
	lines := []string{
		`{"msg":"handled request","request":{` + h2tls + `,"method":"GET","uri":"/","headers":{"User-Agent":["Mozilla"]}},"bytes_read":10,"size":1000,"duration":0.004,"status":200}`,
		`{"msg":"handled request","request":{` + h2tls + `,"method":"POST","uri":"/orders/1","headers":{}},"bytes_read":500,"size":20,"duration":0.3,"status":201}`,
		`{"msg":"handled request","request":{"proto":"HTTP/1.1","method":"GET","uri":"/.env","headers":{}},"size":0,"duration":0.001,"status":404}`,
		`{"msg":"handled request","request":{"proto":"HTTP/3.0","tls":{"version":771},"method":"GET","uri":"/x","headers":{}},"size":5,"duration":12,"status":502}`,
		// The control plane's health checks never count.
		`{"msg":"handled request","request":{"proto":"HTTP/1.1","method":"GET","uri":"/up","headers":{"User-Agent":["Falak-HealthCheck/1"]}},"size":2,"duration":0.001,"status":200}`,
	}
	for _, l := range lines {
		a.ObserveAccess(access("shop", l))
	}
	a.ObserveAccess(access("blog", lines[0]))
	a.ObserveAccess(obs.LogRecord{Attrs: map[string]string{"http.response.status_code": "200"}}) // no site: ignored

	rms := a.Collect()
	if len(rms) != 2 {
		t.Fatalf("got %d resources, want 2 (blog, shop)", len(rms))
	}
	if svc, _ := otlp.Lookup(rms[1].Resource.Attributes, "service.name"); svc != "shop" {
		t.Fatalf("second resource = %q", svc)
	}
	if rms[1].ScopeMetrics[0].Scope.Name != otlp.HTTPScope {
		t.Fatalf("scope = %q", rms[1].ScopeMetrics[0].Scope.Name)
	}
	shop := rms[1]
	expect := func(name string, want map[string]int64) {
		t.Helper()
		got := points(t, shop, name)
		if len(got) != len(want) {
			t.Errorf("%s = %v, want %v", name, got, want)
		}
		for k, v := range want {
			if got[k] != v {
				t.Errorf("%s[%s] = %d, want %d", name, k, got[k], v)
			}
		}
	}
	expect("falak.net.http.requests", map[string]int64{"2xx": 2, "4xx": 1, "5xx": 1})
	expect("falak.net.http.io", map[string]int64{"receive": 510, "transmit": 1025})
	expect("falak.net.http.protocol", map[string]int64{"1.1": 1, "2": 2, "3": 1})
	expect("falak.net.http.tls", map[string]int64{"1.2": 1, "1.3": 2, "none": 1})
	expect("falak.net.http.threats", map[string]int64{"probe": 1})

	var hist *metricspb.HistogramDataPoint
	for _, m := range shop.ScopeMetrics[0].Metrics {
		if m.Name == "falak.net.http.server.duration" {
			if m.Unit != "ms" {
				t.Errorf("unit = %q", m.Unit)
			}
			hist = m.GetHistogram().DataPoints[0]
		}
	}
	if hist == nil || hist.Count != 4 || len(hist.BucketCounts) != len(Buckets)+1 {
		t.Fatalf("histogram = %v", hist)
	}
	// 4 ms → le 5, 300 ms → le 500, 1 ms → le 5, 12 s → +Inf.
	want := make([]uint64, len(Buckets)+1)
	want[0], want[6], want[len(Buckets)] = 2, 1, 1
	for i := range want {
		if hist.BucketCounts[i] != want[i] {
			t.Fatalf("buckets = %v, want %v", hist.BucketCounts, want)
		}
	}
	if *hist.Sum < 12305 || *hist.Sum > 12306 {
		t.Errorf("sum = %v", *hist.Sum)
	}

	// Counters are cumulative across collections.
	a.ObserveAccess(access("shop", lines[0]))
	shop = a.Collect()[1]
	expect("falak.net.http.requests", map[string]int64{"2xx": 3, "4xx": 1, "5xx": 1})

	// A site idle for an hour is forgotten (its series end).
	now = now.Add(IdleAfter + time.Minute)
	if rms := a.Collect(); len(rms) != 0 {
		t.Fatalf("idle sites still reported: %d", len(rms))
	}
}

// The metrics as the control plane queries them (modules/Telemetry/tests/Unit/HttpQueriesTest.php pins the same list
// and derives their Prometheus names): name, unit and type must not change without the queries.
func TestAggregatorMetricNames(t *testing.T) {
	a := New(nil)
	a.ObserveAccess(access("shop", `{"msg":"handled request","request":{"proto":"HTTP/1.1","method":"GET","uri":"/.env","headers":{}},"size":1,"duration":0.001,"status":404}`))
	var got []string
	for _, m := range a.Collect()[0].ScopeMetrics[0].Metrics {
		kind := "counter"
		if m.GetHistogram() != nil {
			kind = "histogram"
		}
		got = append(got, m.Name+" "+m.Unit+" "+kind)
	}
	want := []string{
		"falak.net.http.requests {request} counter",
		"falak.net.http.io By counter",
		"falak.net.http.server.duration ms histogram",
		"falak.net.http.protocol {request} counter",
		"falak.net.http.tls {request} counter",
		"falak.net.http.threats {request} counter",
	}
	if strings.Join(got, "\n") != strings.Join(want, "\n") {
		t.Fatalf("metrics:\n%s", strings.Join(got, "\n"))
	}
}

func TestAggregatorIgnoresRecordsWithoutStatus(t *testing.T) {
	a := New(nil)
	a.ObserveAccess(obs.LogRecord{Site: "x", Attrs: map[string]string{"http.request.method": "GET"}})
	a.ObserveAccess(obs.LogRecord{Site: "x", Attrs: map[string]string{"http.response.status_code": "0"}})
	if rms := a.Collect(); len(rms) != 0 {
		t.Fatalf("got %d resources", len(rms))
	}
}
