package nettool

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptrace"
	"net/netip"
	"net/url"
	"sort"
	"strings"
	"sync"
	"time"
)

// maxBody bounds how much of the response body is read (counted, never printed).
const maxBody = 10 << 20

// httpTimings are the phases of one check, measured from the start.
type httpTimings struct {
	mu                                  sync.Mutex
	start                               time.Time
	dns, connect, tls, wrote, firstByte time.Duration
}

func (t *httpTimings) mark(p *time.Duration) {
	t.mu.Lock()
	*p = time.Since(t.start)
	t.mu.Unlock()
}

// runHTTP requests the URL once, curl -v style: resolution, connection, TLS, request and response headers, a timing
// line. Redirects are shown, not followed. Exit 0 for a status below 400.
func runHTTP(ctx context.Context, s HelperSpec, n *names, w io.Writer, roots *x509.CertPool) int {
	u, _ := url.Parse(s.URL) // validated
	host := u.Hostname()
	port := u.Port()
	if port == "" {
		port = map[string]string{"http": "80", "https": "443"}[u.Scheme]
	}
	t := &httpTimings{start: time.Now()}
	var out sync.Mutex
	say := func(format string, a ...any) {
		out.Lock()
		fmt.Fprintf(w, format+"\n", a...)
		out.Unlock()
	}

	dial := func(ctx context.Context, network, _ string) (net.Conn, error) {
		var addrs []netip.Addr
		if s.ConnectTo != "" {
			addrs = []netip.Addr{netip.MustParseAddr(s.ConnectTo)}
			say("* Connecting to %s instead of resolving %s (connect_to)", s.ConnectTo, host)
		} else {
			var err error
			addrs, err = n.lookupIP(ctx, host)
			if err != nil {
				return nil, fmt.Errorf("resolve %s: %s", host, dnsError(err))
			}
			t.mark(&t.dns)
			if _, ipErr := netip.ParseAddr(host); ipErr != nil {
				say("* Resolved %s to %s (%s) in %s", host, joinAddrs(addrs), n.serverLabel(), ms(t.dns))
			}
		}
		var last error
		for _, a := range addrs {
			addr := net.JoinHostPort(a.String(), port)
			say("*   Trying %s...", addr)
			c, err := guardedDialer(10*time.Second).DialContext(ctx, network, addr)
			if err == nil {
				t.mark(&t.connect)
				say("* Connected to %s (%s) in %s", host, addr, ms(t.connect-t.dns))
				return c, nil
			}
			last = err
			say("*   %s: %s", addr, tcpError(err))
		}
		return nil, last
	}

	tr := &http.Transport{
		Proxy:               nil,
		DialContext:         dial,
		ForceAttemptHTTP2:   true,
		TLSHandshakeTimeout: 15 * time.Second,
		DisableKeepAlives:   true,
		DisableCompression:  true,
		TLSClientConfig:     &tls.Config{ServerName: host, InsecureSkipVerify: s.Insecure, RootCAs: roots}, //nolint:gosec // the user's explicit choice, flagged in the output
	}
	defer tr.CloseIdleConnections()
	client := &http.Client{Transport: tr, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}

	trace := &httptrace.ClientTrace{
		TLSHandshakeStart: func() { say("* TLS handshake with %s", host) },
		TLSHandshakeDone: func(cs tls.ConnectionState, err error) {
			t.mark(&t.tls)
			if err != nil {
				return
			}
			alpn := cs.NegotiatedProtocol
			if alpn == "" {
				alpn = "http/1.1"
			}
			say("* %s, %s, ALPN %s (handshake %s)", tls.VersionName(cs.Version), tls.CipherSuiteName(cs.CipherSuite), alpn, ms(t.tls-t.connect))
			for i, c := range cs.PeerCertificates {
				if i >= 3 {
					break
				}
				say("*   %s", certLine(i, c))
			}
			if s.Insecure {
				say("* WARNING: certificate verification skipped (insecure)")
			}
		},
		WroteHeaderField: func(key string, values []string) {
			for _, v := range values {
				say("> %s: %s", key, v)
			}
		},
		WroteRequest:         func(httptrace.WroteRequestInfo) { t.mark(&t.wrote); say(">") },
		GotFirstResponseByte: func() { t.mark(&t.firstByte) },
	}
	req, err := http.NewRequestWithContext(httptrace.WithClientTrace(ctx, trace), s.Method, s.URL, nil)
	if err != nil {
		say("* %s", err)
		return 2
	}
	req.Header.Set("User-Agent", "falak-nettool/1")
	req.Header.Set("Accept", "*/*")
	say("> %s %s", s.Method, u.RequestURI())

	resp, err := client.Do(req)
	if err != nil {
		say("* request failed: %s", httpError(err))
		return 1
	}
	defer resp.Body.Close()
	say("< %s %s", resp.Proto, resp.Status)
	keys := make([]string, 0, len(resp.Header))
	for k := range resp.Header {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		for _, v := range resp.Header[k] {
			say("< %s: %s", strings.ToLower(k), v)
		}
	}
	say("<")
	nBody, readErr := io.Copy(io.Discard, io.LimitReader(resp.Body, maxBody))
	total := time.Since(t.start)
	switch {
	case readErr != nil:
		say("* body: %d bytes, then %s", nBody, httpError(readErr))
	case nBody == maxBody:
		say("* body: more than %d bytes (stopped reading)", maxBody)
	default:
		say("* body: %d bytes", nBody)
	}
	if loc := resp.Header.Get("Location"); loc != "" && resp.StatusCode >= 300 && resp.StatusCode < 400 {
		say("* redirect to %s (not followed)", loc)
	}
	parts := []string{}
	if t.dns > 0 {
		parts = append(parts, "dns "+ms(t.dns))
	}
	parts = append(parts, "connect "+ms(t.connect))
	if t.tls > 0 {
		parts = append(parts, "tls "+ms(t.tls))
	}
	parts = append(parts, "first byte "+ms(t.firstByte), "total "+ms(total))
	say("timing: %s", strings.Join(parts, " · "))
	if resp.StatusCode >= 400 {
		return 1
	}
	return 0
}

// certLine describes one certificate of the chain.
func certLine(i int, c *x509.Certificate) string {
	label := "certificate"
	if i > 0 {
		label = "issuer cert"
	}
	days := int(time.Until(c.NotAfter).Hours() / 24)
	line := fmt.Sprintf("%s: %s, issued by %s, expires %s (%d days)", label, c.Subject.CommonName, c.Issuer.CommonName, c.NotAfter.UTC().Format("2006-01-02"), days)
	if i == 0 && len(c.DNSNames) > 0 {
		names := c.DNSNames
		more := ""
		if len(names) > 5 {
			names, more = names[:5], fmt.Sprintf(" (+%d)", len(c.DNSNames)-5)
		}
		line += ", names " + strings.Join(names, ", ") + more
	}
	return line
}

// httpError explains certificate failures and hints at insecure.
func httpError(err error) string {
	var (
		unknown  x509.UnknownAuthorityError
		hostname x509.HostnameError
		invalid  x509.CertificateInvalidError
		verify   *tls.CertificateVerificationError
	)
	switch {
	case errors.Is(err, errLinkLocal):
		return errLinkLocal.Error()
	case errors.As(err, &unknown), errors.As(err, &hostname), errors.As(err, &invalid), errors.As(err, &verify):
		return "certificate verification failed: " + err.Error() + " (run with insecure to see the response anyway)"
	case errors.Is(err, context.DeadlineExceeded):
		return "timed out"
	}
	return err.Error()
}
