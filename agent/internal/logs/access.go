package logs

import (
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"math"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/obs"
)

// caddyAccess is the part of Caddy's JSON access log entry ("handled request") that Falak keeps. Request and
// response headers other than the user agent are dropped (they may carry cookies or tokens).
type caddyAccess struct {
	TS      float64 `json:"ts"`
	Msg     string  `json:"msg"`
	Request struct {
		RemoteIP string              `json:"remote_ip"`
		ClientIP string              `json:"client_ip"`
		Proto    string              `json:"proto"`
		Method   string              `json:"method"`
		Host     string              `json:"host"`
		URI      string              `json:"uri"`
		Headers  map[string][]string `json:"headers"`
		TLS      *struct {
			Version     uint16 `json:"version"`
			CipherSuite uint16 `json:"cipher_suite"`
		} `json:"tls"`
	} `json:"request"`
	RespHeaders map[string][]string `json:"resp_headers"`
	BytesRead   int64               `json:"bytes_read"`
	Duration    float64             `json:"duration"` // seconds
	Size        int64               `json:"size"`
	Status      int                 `json:"status"`
}

// RequestIDHeader is the header the edge stamps on every request (edge.caddy.apply request_id): kept when the client
// sent a well-formed one (RequestIDPattern), else a UUID; passed upstream and echoed in the response.
const RequestIDHeader = "X-Request-Id"

// RequestIDPattern is what an incoming X-Request-Id must look like to be kept (and recorded).
const RequestIDPattern = `^[A-Za-z0-9._:-]{8,128}$`

var requestIDRE = regexp.MustCompile(RequestIDPattern)

// traceparent: version-traceid-parentid-flags (W3C Trace Context).
var traceparentRE = regexp.MustCompile(`^[0-9a-f]{2}-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$`)

// header returns the first value of a header (canonical name, as Caddy logs them).
func header(h map[string][]string, name string) string {
	if v := h[name]; len(v) > 0 {
		return v[0]
	}
	return ""
}

// protocolVersion maps Caddy's request.proto to network.protocol.version.
func protocolVersion(proto string) string {
	switch proto {
	case "HTTP/1.0":
		return "1.0"
	case "HTTP/1.1":
		return "1.1"
	case "HTTP/2.0", "HTTP/2":
		return "2"
	case "HTTP/3.0", "HTTP/3":
		return "3"
	}
	return ""
}

// tlsVersion maps the TLS version number Caddy logs to tls.protocol.version.
func tlsVersion(v uint16) string {
	switch v {
	case tls.VersionTLS10:
		return "1.0"
	case tls.VersionTLS11:
		return "1.1"
	case tls.VersionTLS12:
		return "1.2"
	case tls.VersionTLS13:
		return "1.3"
	}
	return ""
}

// ParseAccessLine converts one Caddy access log line into a record: body "GET /path 200 12.3ms", severity by
// status class (5xx ERROR, 4xx WARN), and OpenTelemetry HTTP semantic attributes (Loki structured metadata
// http_request_method, url_path, http_response_status_code, …). Lines that are not access entries fall back
// to ParseLine.
func ParseAccessLine(line []byte) obs.LogRecord {
	var e caddyAccess
	if err := json.Unmarshal(line, &e); err != nil || e.Request.Method == "" {
		return ParseLine(line, "json")
	}
	rec := obs.LogRecord{Time: time.Now(), Severity: "INFO"}
	if e.TS > 0 {
		sec, frac := math.Modf(e.TS)
		rec.Time = time.Unix(int64(sec), int64(frac*1e9))
	}
	switch {
	case e.Status >= 500:
		rec.Severity = "ERROR"
	case e.Status >= 400:
		rec.Severity = "WARN"
	}
	path, query := e.Request.URI, ""
	if u, err := url.ParseRequestURI(e.Request.URI); err == nil {
		path, query = u.Path, u.RawQuery
	}
	ms := e.Duration * 1000
	durMS := strconv.FormatFloat(ms, 'f', 3, 64)
	rec.Body = fmt.Sprintf("%s %s %d %sms", e.Request.Method, e.Request.URI, e.Status, strconv.FormatFloat(ms, 'f', 1, 64))
	client := e.Request.ClientIP
	if client == "" {
		client = e.Request.RemoteIP
	}
	rec.Attrs = map[string]string{
		"http.request.method":       e.Request.Method,
		"http.response.status_code": strconv.Itoa(e.Status),
		"http.response.body.size":   strconv.FormatInt(e.Size, 10),
		"http.request.body.size":    strconv.FormatInt(e.BytesRead, 10),
		"http.server.duration_ms":   durMS,
		"url.path":                  path,
		"server.address":            e.Request.Host,
		"client.address":            client,
	}
	if query != "" {
		rec.Attrs["url.query"] = query
	}
	if e.Request.Proto != "" {
		rec.Attrs["network.protocol.name"] = e.Request.Proto
	}
	if ua := e.Request.Headers["User-Agent"]; len(ua) > 0 {
		rec.Attrs["user_agent.original"] = ua[0]
	}
	if v := protocolVersion(e.Request.Proto); v != "" {
		rec.Attrs["network.protocol.version"] = v
	}
	if t := e.Request.TLS; t != nil {
		if v := tlsVersion(t.Version); v != "" {
			rec.Attrs["tls.protocol.version"] = v
		}
		if t.CipherSuite != 0 {
			rec.Attrs["tls.cipher"] = tls.CipherSuiteName(t.CipherSuite)
		}
	}
	rec.Attrs["url.route"] = NormalizeRoute(path)
	// The response's id first (the app's own when it sets one, else the one the edge passed to it), then the request's.
	for _, id := range []string{header(e.RespHeaders, RequestIDHeader), header(e.Request.Headers, RequestIDHeader)} {
		if requestIDRE.MatchString(id) {
			rec.Attrs["http.request.id"] = id
			break
		}
	}
	if m := traceparentRE.FindStringSubmatch(strings.ToLower(header(e.Request.Headers, "Traceparent"))); m != nil && strings.Trim(m[1], "0") != "" {
		// The trace the client started (its span is the request's parent, not a span of this record: no span id).
		rec.TraceID, _ = hex.DecodeString(m[1])
	}
	if category, rule := ClassifyThreat(e.Request.Method, e.Request.URI, e.Status); category != "" {
		rec.Attrs["falak.threat.category"] = category
		rec.Attrs["falak.threat.rule"] = rule
	}
	return rec
}
