package sandbox

import (
	"context"
	"errors"
	"io"
	"net"
	"net/http"
	"net/netip"
	"strings"
	"sync"
	"syscall"
	"time"
)

// proxy is the egress proxy of allowlist sandboxes (CONNECT and plain HTTP), listening on the bridge's gateway. A
// sandbox is known by its source address; it reaches only the hosts of its allowlist, and never a private, link-local
// or local address whatever those hosts resolve to (the proxy runs on the host, outside FALAK-SBX).
type proxy struct {
	mu    sync.RWMutex
	allow map[string][]string // sandbox address → allowed hosts
	dial  func(ctx context.Context, network, addr string) (net.Conn, error)
	tr    *http.Transport
}

func newProxy() *proxy {
	d := &net.Dialer{Timeout: 15 * time.Second, Control: refusePrivate}
	p := &proxy{allow: map[string][]string{}, dial: d.DialContext}
	p.tr = &http.Transport{Proxy: nil, DialContext: func(ctx context.Context, n, a string) (net.Conn, error) { return p.dial(ctx, n, a) },
		ResponseHeaderTimeout: time.Minute, IdleConnTimeout: 30 * time.Second}
	return p
}

func (p *proxy) set(ip string, hosts []string) {
	p.mu.Lock()
	p.allow[ip] = hosts
	p.mu.Unlock()
}

func (p *proxy) del(ip string) {
	p.mu.Lock()
	delete(p.allow, ip)
	p.mu.Unlock()
}

func (p *proxy) allowed(src, host string) bool {
	p.mu.RLock()
	defer p.mu.RUnlock()
	return hostAllowed(p.allow[src], host)
}

// hostAllowed matches host against an allowlist: exact names, and *.example.com for any name below example.com (not
// example.com itself). Case and a trailing dot don't matter.
func hostAllowed(list []string, host string) bool {
	host = strings.TrimSuffix(strings.ToLower(host), ".")
	if host == "" {
		return false
	}
	for _, a := range list {
		a = strings.TrimSuffix(strings.ToLower(a), ".")
		if suffix, ok := strings.CutPrefix(a, "*"); ok {
			if strings.HasSuffix(host, suffix) && len(host) > len(suffix) {
				return true
			}
		} else if host == a {
			return true
		}
	}
	return false
}

// refusePrivate is the dialer's Control hook: the address is already resolved here, so a name that resolves to
// cloud metadata or the LAN is refused as well.
func refusePrivate(_, address string, _ syscall.RawConn) error {
	ap, err := netip.ParseAddrPort(address)
	if err != nil {
		return err
	}
	if !publicAddr(ap.Addr()) {
		return errors.New("refused: " + ap.Addr().String() + " is not a public address")
	}
	return nil
}

var cgnat = netip.MustParsePrefix("100.64.0.0/10")

func publicAddr(a netip.Addr) bool {
	a = a.Unmap()
	if !a.IsGlobalUnicast() || a.IsPrivate() || cgnat.Contains(a) {
		return false
	}
	addrs, _ := net.InterfaceAddrs()
	for _, ia := range addrs {
		if n, ok := ia.(*net.IPNet); ok {
			if l, ok := netip.AddrFromSlice(n.IP); ok && l.Unmap() == a {
				return false // one of the server's own addresses
			}
		}
	}
	return true
}

func (p *proxy) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	src, _, _ := net.SplitHostPort(r.RemoteAddr)
	host := r.URL.Hostname()
	if r.Method == http.MethodConnect {
		host, _, _ = net.SplitHostPort(r.Host)
	}
	if !p.allowed(src, host) {
		http.Error(w, "falak sandbox: "+host+" is not in this sandbox's allowlist", http.StatusForbidden)
		return
	}
	if r.Method == http.MethodConnect {
		p.connect(w, r)
		return
	}
	if !r.URL.IsAbs() {
		http.Error(w, "falak sandbox: this is an HTTP proxy", http.StatusBadRequest)
		return
	}
	out := r.Clone(r.Context())
	out.RequestURI = ""
	out.Host = "" // the upstream gets the allowlisted URL's host, never a Host header fronting another site
	for _, h := range []string{"Proxy-Connection", "Proxy-Authorization", "Connection", "Keep-Alive", "Te", "Trailer", "Upgrade"} {
		out.Header.Del(h)
	}
	resp, err := p.tr.RoundTrip(out)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()
	for k, v := range resp.Header {
		w.Header()[k] = v
	}
	w.WriteHeader(resp.StatusCode)
	_, _ = io.Copy(w, resp.Body)
}

func (p *proxy) connect(w http.ResponseWriter, r *http.Request) {
	up, err := p.dial(r.Context(), "tcp", r.Host)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	hj, ok := w.(http.Hijacker)
	if !ok {
		up.Close()
		http.Error(w, "hijack unsupported", http.StatusInternalServerError)
		return
	}
	conn, buf, err := hj.Hijack()
	if err != nil {
		up.Close()
		return
	}
	_, _ = conn.Write([]byte("HTTP/1.1 200 Connection Established\r\n\r\n"))
	done := make(chan struct{}, 2)
	go func() { _, _ = io.Copy(up, buf); done <- struct{}{} }()
	go func() { _, _ = io.Copy(conn, up); done <- struct{}{} }()
	<-done
	conn.Close()
	up.Close()
	<-done
}
