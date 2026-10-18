package edge

import (
	"bufio"
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/localedge"
)

var localCfg = localedge.Config{Enabled: true, Addr: "172.17.0.1", HTTPPort: 8080, HTTPSPort: 8443, Trust: "172.30.0.0/16", WorkloadSlice: "falak.slice"}

type renderedServer struct {
	Listen           []string         `json:"listen"`
	ListenerWrappers []map[string]any `json:"listener_wrappers"`
	Protocols        []string         `json:"protocols"`
	Routes           []any            `json:"routes"`
}

type renderedHTTP struct {
	HTTPPort  int                       `json:"http_port"`
	HTTPSPort int                       `json:"https_port"`
	Servers   map[string]renderedServer `json:"servers"`
}

func renderHTTP(t *testing.T, p Payload) renderedHTTP {
	t.Helper()
	cfg, err := Render(p, "/etc/falak/certs")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(cfg)
	var got struct {
		Apps struct {
			HTTP renderedHTTP `json:"http"`
		} `json:"apps"`
	}
	if err := json.Unmarshal(b, &got); err != nil {
		t.Fatal(err)
	}
	return got.Apps.HTTP
}

func TestRenderLocalEdge(t *testing.T) {
	p := payload
	p.LocalEdge = localCfg
	h := renderHTTP(t, p)
	if h.HTTPPort != 8080 || h.HTTPSPort != 8443 {
		t.Fatalf("ports %d/%d", h.HTTPPort, h.HTTPSPort)
	}
	tls, plain := h.Servers["falak"], h.Servers["falak_http"]
	if mustJSON(tls.Listen) != `["172.17.0.1:8443"]` || mustJSON(plain.Listen) != `["172.17.0.1:8080"]` {
		t.Fatalf("listen %v %v", tls.Listen, plain.Listen)
	}
	pp := `{"allow":["172.30.0.0/16"],"fallback_policy":"reject","wrapper":"proxy_protocol"}`
	if mustJSON(tls.ListenerWrappers) != `[`+pp+`,{"wrapper":"tls"}]` || mustJSON(plain.ListenerWrappers) != `[`+pp+`]` {
		t.Fatalf("wrappers %s / %s", mustJSON(tls.ListenerWrappers), mustJSON(plain.ListenerWrappers))
	}
	if mustJSON(tls.Protocols) != `["h1","h2"]` || mustJSON(plain.Protocols) != `["h1","h2"]` {
		t.Fatalf("protocols %v %v", tls.Protocols, plain.Protocols)
	}

	// Only TLS sites: the plain server still exists (empty), so Caddy puts its redirects behind the PROXY wrapper.
	p.Sites = p.Sites[:1]
	h = renderHTTP(t, p)
	if s, ok := h.Servers["falak_http"]; !ok || s.Routes == nil || len(s.Routes) != 0 || s.ListenerWrappers == nil {
		t.Fatalf("plain server: %+v", h.Servers)
	}

	// Without the file nothing changes.
	h = renderHTTP(t, payload)
	if h.HTTPPort != 0 || h.HTTPSPort != 0 || mustJSON(h.Servers["falak"].Listen) != `[":443"]` ||
		h.Servers["falak"].ListenerWrappers != nil || h.Servers["falak"].Protocols != nil || mustJSON(h.Servers["falak_http"].Listen) != `[":80"]` {
		t.Fatalf("regular server changed: %+v", h)
	}
	if h = renderHTTP(t, Payload{Sites: payload.Sites[:1]}); len(h.Servers) != 1 {
		t.Fatalf("regular server got a plain server: %+v", h.Servers)
	}
}

func TestApplyReadsLocalEdgeFile(t *testing.T) {
	m, _, fs := setup(t)
	if _, err := fs.WriteFile(localedge.Path, []byte("FALAK_LOCAL_EDGE=1\nFALAK_LOCAL_EDGE_ADDR=172.17.0.1\nFALAK_LOCAL_EDGE_TRUST=172.30.0.0/16\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Apply(context.Background(), payload, stream()); err != nil {
		t.Fatal(err)
	}
	b, _ := fs.ReadFile("/etc/falak/caddy/bootstrap.json")
	if !strings.Contains(string(b), `"172.17.0.1:8443"`) || !strings.Contains(string(b), `"proxy_protocol"`) {
		t.Fatalf("local edge not applied:\n%s", b)
	}
	// A broken file fails the apply instead of putting the edge on 80/443 next to the control plane's.
	fs.WriteFile(localedge.Path, []byte("FALAK_LOCAL_EDGE=1\n"), 0o644)
	if _, err := m.Apply(context.Background(), payload, stream()); err == nil {
		t.Fatal("broken local-edge.env accepted")
	}
}

// With FALAK_CADDY_BIN set (any Caddy 2.8+), the local edge config validates and, run on loopback, honours the PROXY
// header and redirects plain HTTP to https://host/ without the internal HTTPS port.
func TestLocalEdgeWithCaddy(t *testing.T) {
	bin := os.Getenv("FALAK_CADDY_BIN")
	if bin == "" {
		t.Skip("FALAK_CADDY_BIN not set")
	}
	free := func() int {
		l, err := net.Listen("tcp", "127.0.0.1:0")
		if err != nil {
			t.Fatal(err)
		}
		defer l.Close()
		return l.Addr().(*net.TCPAddr).Port
	}
	local := localedge.Config{Enabled: true, Addr: "127.0.0.1", HTTPPort: free(), HTTPSPort: free(), Trust: "127.0.0.0/8"}
	plainRoot := t.TempDir()
	os.WriteFile(filepath.Join(plainRoot, "x"), []byte("ok"), 0o644)
	p := Payload{LocalEdge: local, Sites: []Site{
		{ID: "shop", Domains: []string{"shop.example.com"}, Kind: "static", Root: t.TempDir(), TLS: &TLS{Mode: "internal"}},
		{ID: "plain", Domains: []string{"plain.example.com"}, Kind: "static", Root: plainRoot, TLS: &TLS{Mode: "off"}},
	}}
	cfg, err := Render(p, t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	cfg["admin"] = obj{"disabled": true}
	cfg["storage"] = obj{"module": "file_system", "root": t.TempDir()}
	cfg["apps"].(obj)["pki"] = obj{"certificate_authorities": obj{"local": obj{"install_trust": false}}}
	b, _ := canonical(cfg)
	f := filepath.Join(t.TempDir(), "caddy.json")
	if err := os.WriteFile(f, b, 0o600); err != nil {
		t.Fatal(err)
	}
	if out, err := exec.Command(bin, "validate", "--config", f).CombinedOutput(); err != nil {
		t.Fatalf("caddy validate: %v\n%s", err, out)
	}
	cmd := exec.Command(bin, "run", "--config", f)
	cmd.Env = append(os.Environ(), "HOME="+t.TempDir(), "XDG_DATA_HOME="+t.TempDir())
	var out strings.Builder
	cmd.Stdout, cmd.Stderr = &out, &out
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { cmd.Process.Kill(); cmd.Wait() })
	// get sends a request the way the control plane's edge does: a PROXY header, then the client's bytes.
	get := func(host string, https bool) (*http.Response, error) {
		addr := local.HTTPListen()
		if https {
			addr = local.HTTPSListen()
		}
		var c net.Conn
		var err error
		for i := 0; i < 50; i++ {
			if c, err = net.Dial("tcp", addr); err == nil {
				break
			}
			time.Sleep(100 * time.Millisecond)
		}
		if err != nil {
			return nil, fmt.Errorf("%w\n%s", err, out.String())
		}
		defer c.Close()
		c.SetDeadline(time.Now().Add(5 * time.Second))
		fmt.Fprint(c, "PROXY TCP4 203.0.113.7 127.0.0.1 50000 80\r\n")
		if https {
			c = tls.Client(c, &tls.Config{ServerName: host, InsecureSkipVerify: true})
		}
		fmt.Fprintf(c, "GET /x?y=1 HTTP/1.1\r\nHost: %s\r\nConnection: close\r\n\r\n", host)
		return http.ReadResponse(bufio.NewReader(c), nil)
	}
	r, err := get("shop.example.com", false)
	if err != nil {
		t.Fatal(err)
	}
	if r.StatusCode != http.StatusPermanentRedirect || r.Header.Get("Location") != "https://shop.example.com/x?y=1" {
		t.Fatalf("redirect: %d %q", r.StatusCode, r.Header.Get("Location"))
	}
	if r, err = get("plain.example.com", false); err != nil || r.StatusCode != http.StatusOK {
		t.Fatalf("plain site: %v %v", r, err)
	}
	// TLS after the PROXY header (an empty root: 404 from the file server, not a handshake error).
	if r, err = get("shop.example.com", true); err != nil || r.StatusCode != http.StatusNotFound || r.Header.Get("Alt-Svc") != "" {
		t.Fatalf("tls site: %v %v", r, err)
	}
}
