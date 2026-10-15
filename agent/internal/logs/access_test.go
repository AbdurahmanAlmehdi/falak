package logs

import (
	"encoding/hex"
	"os"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/obs"
)

type accessRecorder struct{ recs []obs.LogRecord }

func (a *accessRecorder) ObserveAccess(r obs.LogRecord) { a.recs = append(a.recs, r) }

// The tailer hands every access record (with its site from the file name) to the observer, and nothing else.
func TestTailerFeedsAccessObserver(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	os.MkdirAll(fs.P(AccessLogDir), 0o755)
	os.MkdirAll(fs.P("/var/log/app"), 0o755)
	sink := &memSink{}
	obsv := &accessRecorder{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.SetAccessObserver(obsv)
	tl.SetSources([]Source{AccessSource(), {Path: "/var/log/app/*.log", Site: "shop"}})
	tl.Poll()
	appendFile(t, fs.P(AccessLogDir+"/shop.log"), `{"msg":"handled request","request":{"method":"GET","uri":"/a","headers":{}},"status":200}`+"\n"+"not json\n")
	appendFile(t, fs.P("/var/log/app/app.log"), "app line\n")
	tl.Poll()
	if len(obsv.recs) != 1 || obsv.recs[0].Site != "shop" || obsv.recs[0].Attrs["url.route"] != "/a" {
		t.Fatalf("observed %+v", obsv.recs)
	}
	if n := len(sink.bodies()); n != 3 {
		t.Fatalf("shipped %d records, want 3", n)
	}
}

func TestParseAccessLineHTTPAnalyticsFields(t *testing.T) {
	line := `{"level":"info","ts":1760000000.5,"logger":"http.log.access.falak-access-shop","msg":"handled request",` +
		`"request":{"remote_ip":"203.0.113.9","client_ip":"198.51.100.7","proto":"HTTP/2.0","method":"GET","host":"shop.test",` +
		`"uri":"/orders/123?page=2","headers":{"User-Agent":["curl/8"],"X-Request-Id":["client-supplied-id"],` +
		`"Traceparent":["00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01"]},` +
		`"tls":{"resumed":false,"version":772,"cipher_suite":4865,"proto":"h2","server_name":"shop.test"}},` +
		`"bytes_read":12,"duration":0.25,"size":5120,"status":200,` +
		`"resp_headers":{"X-Request-Id":["01JABCDEF-edge-uuid-0001"],"Content-Type":["text/html"]}}`
	rec := ParseAccessLine([]byte(line))
	want := map[string]string{
		"network.protocol.name":     "HTTP/2.0",
		"network.protocol.version":  "2",
		"tls.protocol.version":      "1.3",
		"tls.cipher":                "TLS_AES_128_GCM_SHA256",
		"url.route":                 "/orders/:id",
		"url.path":                  "/orders/123",
		"url.query":                 "page=2",
		"http.request.id":           "01JABCDEF-edge-uuid-0001", // the edge's response header wins
		"client.address":            "198.51.100.7",
		"http.response.body.size":   "5120",
		"http.request.body.size":    "12",
		"http.response.status_code": "200",
	}
	for k, v := range want {
		if rec.Attrs[k] != v {
			t.Errorf("%s = %q, want %q", k, rec.Attrs[k], v)
		}
	}
	if got := hex.EncodeToString(rec.TraceID); got != "4bf92f3577b34da6a3ce929d0e0e4736" {
		t.Errorf("trace id = %q", got)
	}
	if rec.SpanID != nil {
		t.Errorf("span id = %x, want none (the client's span is the parent)", rec.SpanID)
	}
	if _, ok := rec.Attrs["falak.threat.category"]; ok {
		t.Errorf("a normal request is no threat: %v", rec.Attrs)
	}
}

func TestParseAccessLineRequestIDFallbacksAndPlainHTTP(t *testing.T) {
	// No response header (an agent before the edge stamps ids, or a client id): the request header, when well-formed.
	rec := ParseAccessLine([]byte(`{"msg":"handled request","request":{"proto":"HTTP/1.1","method":"GET","uri":"/.env",` +
		`"headers":{"X-Request-Id":["abc12345"],"Traceparent":["00-00000000000000000000000000000000-00f067aa0ba902b7-01"]}},"status":404}`))
	if rec.Attrs["http.request.id"] != "abc12345" {
		t.Errorf("request id = %q", rec.Attrs["http.request.id"])
	}
	if _, ok := rec.Attrs["tls.protocol.version"]; ok {
		t.Error("plain HTTP has no TLS version")
	}
	if rec.Attrs["network.protocol.version"] != "1.1" {
		t.Errorf("protocol = %q", rec.Attrs["network.protocol.version"])
	}
	if rec.TraceID != nil {
		t.Error("an all-zero trace id is invalid")
	}
	if rec.Attrs["falak.threat.category"] != "probe" || rec.Attrs["falak.threat.rule"] != "env" {
		t.Errorf("threat = %q / %q", rec.Attrs["falak.threat.category"], rec.Attrs["falak.threat.rule"])
	}
	// Malformed ids are never recorded.
	for _, id := range []string{"short", "has space in it", "<script>alert(1)</script>", string(make([]byte, 200))} {
		rec := ParseAccessLine([]byte(`{"msg":"handled request","request":{"method":"GET","uri":"/","headers":{"X-Request-Id":["` + id + `"]}},"status":200}`))
		if _, ok := rec.Attrs["http.request.id"]; ok {
			t.Errorf("recorded malformed id %q", id)
		}
	}
}

func TestNormalizeRoute(t *testing.T) {
	cases := map[string]string{
		"":                    "/",
		"/":                   "/",
		"/orders":             "/orders",
		"/orders/123":         "/orders/:id",
		"/orders/123/items/7": "/orders/:id/items/:id",
		"/u/550e8400-e29b-41d4-a716-446655440000":           "/u/:id",
		"/d/01JABCDE0FGHJKMNPQRSTVWXYZ":                     "/d/:id",
		"/files/d41d8cd98f00b204e9800998ecf8427e":           "/files/:id",
		"/build/assets/app-3f2a9c1b.js":                     "/build/assets/:asset.js",
		"/build/assets/vendor-react-B2x9QzLm.CSS":           "/build/assets/:asset.css",
		"/reset/aB3dE5fG7hJ9kL1mN3pQ5rS7":                   "/reset/:id",
		"/blog/how-to-deploy-laravel":                       "/blog/how-to-deploy-laravel",
		"/js/jquery.min.js":                                 "/js/jquery.min.js",
		"/api/v2/users":                                     "/api/v2/users",
		"/a/b/c/d/e/f/g/h/i/j":                              "/a/b/c/d/e/f/g/h/…",
		"/trailing/":                                        "/trailing/",
		"/authentication-requirements-for-clients":          "/authentication-requirements-for-clients",
		"/api/v1/products/75243p09010":                      "/api/v1/products/:id",
		"/api/v1/products/dk1l076m0115":                     "/api/v1/products/:id",
		"/storage/12/conversions/dk1l076m0115-1-thumb.webp": "/storage/:id/conversions/:file.webp",
		"/uploads/IMG_20240101_123456.JPG":                  "/uploads/:file.jpg",
		"/oauth2/authorize":                                 "/oauth2/authorize",
		"/api/v1/base64/utf8mb4":                            "/api/v1/base64/utf8mb4",
		"/js/jquery-3.7.1.min.js":                           "/js/jquery-3.7.1.min.js",
		"/robots.txt":                                       "/robots.txt",
		"/h264/x86_64":                                      "/h264/x86_64",
	}
	for in, want := range cases {
		if got := NormalizeRoute(in); got != want {
			t.Errorf("NormalizeRoute(%q) = %q, want %q", in, got, want)
		}
	}
	long := "/" + string(make([]byte, 0)) + "x"
	for range 300 {
		long += "y"
	}
	if got := NormalizeRoute(long); len(got) > maxRouteBytes+len("…") {
		t.Errorf("long route kept %d bytes", len(got))
	}
}

func TestClassifyThreat(t *testing.T) {
	cases := []struct{ method, uri, category, rule string }{
		{"GET", "/.env", "probe", "env"},
		{"GET", "/app/.env.backup", "probe", "env"},
		{"GET", "/.git/config", "probe", "git"},
		{"GET", "/wp-login.php", "probe", "wordpress"},
		{"POST", "/xmlrpc.php", "probe", "wordpress"},
		{"GET", "/wp-admin/install.php", "probe", "wordpress"},
		{"GET", "/phpmyadmin/index.php", "probe", "phpmyadmin"},
		{"GET", "/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php", "probe", "phpunit"},
		{"GET", "/cgi-bin/luci", "probe", "cgi"},
		{"GET", "/db.sql", "probe", "config"},
		{"GET", "/.aws/credentials", "probe", "config"},
		{"GET", "/static/../../etc/passwd", "traversal", "traversal"},
		{"GET", "/download?file=%2e%2e%2f%2e%2e%2fetc%2fpasswd", "traversal", "traversal"},
		{"GET", "/img/%252e%252e/secret", "traversal", "traversal"},
		{"GET", "/search?q=1%27%20UNION%20SELECT%20password%20FROM%20users", "injection", "sqli"},
		{"GET", "/item?id=1'+or+'1'='1", "injection", "sqli"},
		{"GET", "/search?q=%3Cscript%3Ealert(1)%3C/script%3E", "injection", "xss"},
		{"GET", "/r?next=javascript:alert(1)", "injection", "xss"},
		{"TRACE", "/", "method", "method"},
		{"PROPFIND", "/", "method", "method"},
		// Not threats.
		{"GET", "/environment", "", ""},
		{"GET", "/search?q=select+a+union+rep", "", ""},
		{"GET", "/blog/wp-guide", "", ""},
		{"OPTIONS", "/api", "", ""},
		{"GET", "/docs/onload-events", "", ""},
	}
	for _, c := range cases {
		for _, status := range []int{401, 403, 404, 405} {
			category, rule := ClassifyThreat(c.method, c.uri, status)
			if category != c.category || rule != c.rule {
				t.Errorf("ClassifyThreat(%s %s %d) = %q/%q, want %q/%q", c.method, c.uri, status, category, rule, c.category, c.rule)
			}
		}
	}

	// Answered (2xx / 3xx): the site's own WordPress admin, a real /config.json, WebDAV. Injection markers in the query
	// count whatever the answer.
	answered := []struct {
		method, uri string
		status      int
		category    string
	}{
		{"GET", "/wp-admin/", 200, ""},
		{"POST", "/wp-login.php", 302, ""},
		{"GET", "/config.json", 200, ""},
		{"PROPFIND", "/dav/", 207, ""},
		{"GET", "/.env", 301, ""},
		{"GET", "/static/../../etc/passwd", 200, ""},
		{"GET", "/search?q=1%27%20UNION%20SELECT%20password%20FROM%20users", 200, "injection"},
		{"GET", "/wp-admin/?q=%3Cscript%3E", 200, "injection"},
	}
	for _, c := range answered {
		if category, _ := ClassifyThreat(c.method, c.uri, c.status); category != c.category {
			t.Errorf("ClassifyThreat(%s %s %d) = %q, want %q", c.method, c.uri, c.status, category, c.category)
		}
	}
}
