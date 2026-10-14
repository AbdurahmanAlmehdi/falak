package nettool

import (
	"bytes"
	"context"
	"crypto/x509"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"os"
	"strings"
	"testing"
)

func writeExec(path, content string) error { return os.WriteFile(path, []byte(content), 0o755) }

// fakeResolver answers from maps.
type fakeResolver struct {
	ips map[string][]netip.Addr
	mx  []*net.MX
	txt []string
	ptr []string
	err error
}

func (f *fakeResolver) LookupNetIP(_ context.Context, network, host string) ([]netip.Addr, error) {
	if f.err != nil {
		return nil, f.err
	}
	var out []netip.Addr
	for _, a := range f.ips[host] {
		if network == "ip" || (network == "ip4") == a.Is4() {
			out = append(out, a)
		}
	}
	if len(out) == 0 {
		return nil, &net.DNSError{Err: "no such host", Name: host, IsNotFound: true}
	}
	return out, nil
}
func (f *fakeResolver) LookupCNAME(context.Context, string) (string, error) {
	return "target.example.", nil
}
func (f *fakeResolver) LookupMX(context.Context, string) ([]*net.MX, error) { return f.mx, f.err }
func (f *fakeResolver) LookupTXT(context.Context, string) ([]string, error) { return f.txt, f.err }
func (f *fakeResolver) LookupNS(context.Context, string) ([]*net.NS, error) {
	return []*net.NS{{Host: "ns1.example."}}, nil
}
func (f *fakeResolver) LookupSRV(context.Context, string, string, string) (string, []*net.SRV, error) {
	return "", []*net.SRV{{Target: "sip.example.", Port: 5060, Priority: 10, Weight: 5}}, nil
}
func (f *fakeResolver) LookupAddr(context.Context, string) ([]string, error) { return f.ptr, f.err }

func fakeNames(r Resolver) *names {
	return &names{r: r, hosts: map[string][]netip.Addr{"local.test": {netip.MustParseAddr("127.0.0.1")}}, source: "test"}
}

func TestDNS(t *testing.T) {
	r := &fakeResolver{
		ips: map[string][]netip.Addr{"example.com": {netip.MustParseAddr("93.184.216.34"), netip.MustParseAddr("2606:2800::1")}},
		mx:  []*net.MX{{Host: "mx.example.", Pref: 10}}, txt: []string{"v=spf1 -all"}, ptr: []string{"b.example.", "a.example."},
	}
	n := fakeNames(r)
	cases := map[string]string{
		"A":     "example.com.\tA\t93.184.216.34",
		"AAAA":  "example.com.\tAAAA\t2606:2800::1",
		"MX":    "example.com.\tMX\t10 mx.example.",
		"TXT":   "example.com.\tTXT\t\"v=spf1 -all\"",
		"CNAME": "example.com.\tCNAME\ttarget.example.",
		"NS":    "example.com.\tNS\tns1.example.",
		"SRV":   "SRV\t10 5 5060 sip.example.",
	}
	for typ, want := range cases {
		var out bytes.Buffer
		code := runDNS(context.Background(), HelperSpec{Spec: Spec{Tool: ToolDNS, Target: "example.com", RecordType: typ}}, n, &out)
		if code != 0 || !strings.Contains(out.String(), want) {
			t.Errorf("%s: code %d\n%s", typ, code, out.String())
		}
		if typ == "A" && strings.Contains(out.String(), "2606") {
			t.Errorf("A lists an IPv6 address: %s", out.String())
		}
	}
	var out bytes.Buffer
	if runDNS(context.Background(), HelperSpec{Spec: Spec{Tool: ToolDNS, Target: "1.2.3.4", RecordType: "PTR"}}, n, &out); !strings.Contains(out.String(), "1.2.3.4\tPTR\ta.example.\n1.2.3.4\tPTR\tb.example.") {
		t.Errorf("PTR sorted: %s", out.String())
	}
	out.Reset()
	if code := runDNS(context.Background(), HelperSpec{Spec: Spec{Tool: ToolDNS, Target: "local.test", RecordType: "A"}}, n, &out); code != 0 || !strings.Contains(out.String(), "127.0.0.1\t; hosts file") {
		t.Errorf("hosts file: %s", out.String())
	}
	out.Reset()
	if code := runDNS(context.Background(), HelperSpec{Spec: Spec{Tool: ToolDNS, Target: "nope.example", RecordType: "A"}}, n, &out); code != 1 || !strings.Contains(out.String(), "NXDOMAIN") {
		t.Errorf("nxdomain: %d %s", code, out.String())
	}
}

func TestTCP(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			c.Close()
		}
	}()
	port := ln.Addr().(*net.TCPAddr).Port
	n := fakeNames(&fakeResolver{})
	var out bytes.Buffer
	if code := runTCP(context.Background(), HelperSpec{Spec: Spec{Tool: ToolTCP, Target: "local.test", Port: port, Count: 1}}, n, &out); code != 0 ||
		!strings.Contains(out.String(), "local.test resolves to 127.0.0.1") || !strings.Contains(out.String(), "attempt 1: connected to 127.0.0.1:"+portString(port)) {
		t.Fatalf("open port: %d\n%s", code, out.String())
	}
	ln.Close()
	out.Reset()
	if code := runTCP(context.Background(), HelperSpec{Spec: Spec{Tool: ToolTCP, Target: "127.0.0.1", Port: port, Count: 1}}, n, &out); code != 1 || !strings.Contains(out.String(), "connection refused") || !strings.Contains(out.String(), "0 of 1") {
		t.Fatalf("closed port: %d\n%s", code, out.String())
	}
	out.Reset()
	if code := runTCP(context.Background(), HelperSpec{Spec: Spec{Tool: ToolTCP, Target: "169.254.169.254", Port: 80, Count: 1}}, n, &out); code != 1 || !strings.Contains(out.String(), "link-local") {
		t.Fatalf("metadata: %d\n%s", code, out.String())
	}
}

func TestHTTPTimingAndHeaders(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-Test", "yes")
		_, _ = w.Write([]byte("hello"))
	}))
	defer srv.Close()
	n := fakeNames(&fakeResolver{})
	u := strings.Replace(srv.URL, "127.0.0.1", "local.test", 1) + "/health?x=1"
	var out bytes.Buffer
	code := runHTTP(context.Background(), HelperSpec{Spec: Spec{Tool: ToolHTTP, URL: u, Method: "GET"}}, n, &out, nil)
	s := out.String()
	for _, want := range []string{"* Resolved local.test to 127.0.0.1", "> GET /health?x=1", "> User-Agent: falak-nettool/1", "< HTTP/1.1 200 OK", "< x-test: yes", "* body: 5 bytes", "timing: dns ", "first byte ", "total "} {
		if !strings.Contains(s, want) {
			t.Errorf("missing %q in\n%s", want, s)
		}
	}
	if code != 0 {
		t.Errorf("code %d", code)
	}
}

func TestHTTPTLSAndStatus(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, "/login", http.StatusFound)
	}))
	defer srv.Close()
	n := fakeNames(&fakeResolver{})
	roots := x509.NewCertPool()
	roots.AddCert(srv.Certificate())
	host := strings.TrimPrefix(srv.URL, "https://")
	_, port, _ := net.SplitHostPort(host)

	// connect_to: the URL names example.com, the connection goes to 127.0.0.1 (the test cert covers example.com).
	var out bytes.Buffer
	code := runHTTP(context.Background(), HelperSpec{Spec: Spec{Tool: ToolHTTP, URL: "https://example.com:" + port + "/", Method: "HEAD", ConnectTo: "127.0.0.1"}}, n, &out, roots)
	s := out.String()
	for _, want := range []string{"instead of resolving example.com", "* TLS 1.3", "certificate: ", "< HTTP/1.1 302 Found", "redirect to /login (not followed)", "tls "} {
		if !strings.Contains(s, want) {
			t.Errorf("missing %q in\n%s", want, s)
		}
	}
	if code != 0 {
		t.Errorf("a redirect is not a failure: %d", code)
	}

	// Without the test CA the certificate fails, with a hint; insecure goes through and says so.
	out.Reset()
	if code := runHTTP(context.Background(), HelperSpec{Spec: Spec{Tool: ToolHTTP, URL: srv.URL + "/", Method: "GET"}}, n, &out, nil); code != 1 || !strings.Contains(out.String(), "certificate verification failed") {
		t.Errorf("untrusted: %d\n%s", code, out.String())
	}
	out.Reset()
	if code := runHTTP(context.Background(), HelperSpec{Spec: Spec{Tool: ToolHTTP, URL: srv.URL + "/", Method: "GET", Insecure: true}}, n, &out, nil); code != 0 || !strings.Contains(out.String(), "verification skipped") {
		t.Errorf("insecure: %d\n%s", code, out.String())
	}
}

func TestHTTPRefusesLinkLocal(t *testing.T) {
	n := fakeNames(&fakeResolver{ips: map[string][]netip.Addr{"metadata.test": {netip.MustParseAddr("169.254.169.254")}}})
	var out bytes.Buffer
	if code := runHTTP(context.Background(), HelperSpec{Spec: Spec{Tool: ToolHTTP, URL: "http://metadata.test/latest/meta-data/", Method: "GET"}}, n, &out, nil); code != 1 || !strings.Contains(out.String(), "link-local") {
		t.Fatalf("%d\n%s", code, out.String())
	}
}

func TestSystemToolMissing(t *testing.T) {
	old := lookPath
	defer func() { lookPath = old }()
	lookPath = func(string) (string, error) { return "", os.ErrNotExist }
	n := fakeNames(&fakeResolver{})
	var out bytes.Buffer
	if code := runSystem(context.Background(), HelperSpec{Spec: Spec{Tool: ToolPing, Target: "local.test", Count: 1, TimeoutS: 5}}, n, &out); code != ExitNotInstalled || !strings.Contains(out.String(), "ping is not installed") || !strings.Contains(out.String(), "target local.test → 127.0.0.1") {
		t.Fatalf("ping: %d %s", code, out.String())
	}
	out.Reset()
	if code := runSystem(context.Background(), HelperSpec{Spec: Spec{Tool: ToolTraceroute, Target: "127.0.0.1", MaxHops: 5, TimeoutS: 5}}, n, &out); code != ExitNotInstalled || !strings.Contains(out.String(), "No traceroute") {
		t.Fatalf("traceroute: %d %s", code, out.String())
	}
}

func TestArgvTemplates(t *testing.T) {
	ip := netip.MustParseAddr("192.0.2.1")
	got := strings.Join(pingArgv("/bin/ping", HelperSpec{Spec: Spec{Count: 4, TimeoutS: 30}}, ip), " ")
	if got != "/bin/ping -n -c 4 -W 2 -w 11 192.0.2.1" {
		t.Fatalf("ping %s", got)
	}
	old := lookPath
	defer func() { lookPath = old }()
	lookPath = func(b string) (string, error) {
		if b == "mtr" {
			return "/usr/bin/mtr", nil
		}
		return "", os.ErrNotExist
	}
	if got := strings.Join(tracerouteArgv(HelperSpec{Spec: Spec{MaxHops: 7}}, ip), " "); got != "/usr/bin/mtr -n -r -w -c 3 -m 7 192.0.2.1" {
		t.Fatalf("mtr %s", got)
	}
}

func TestMainValidatesAgain(t *testing.T) {
	var out bytes.Buffer
	if code := Main(context.Background(), []string{`{"tool":"ping","target":"-oProxyCommand=x"}`}, &out); code != 2 || !strings.Contains(out.String(), "target") {
		t.Fatalf("%d %s", code, out.String())
	}
	out.Reset()
	if code := Main(context.Background(), []string{`{"tool":"ping","target":"a","shell":"x"}`}, &out); code != 2 {
		t.Fatalf("unknown field: %d %s", code, out.String())
	}
	out.Reset()
	if code := Main(context.Background(), nil, &out); code != 2 {
		t.Fatal("no args")
	}
}
