package relay

import (
	"bytes"
	"context"
	"encoding/hex"
	"errors"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/coder/websocket"
)

const (
	vectorKey   = "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
	vectorHex   = "0f39093fd3813fb62ac265d353c61a32221cded696810b5a38c87671d252bc9e"
	vectorToken = "eyJ2IjoxLCJzaWQiOiIwMUpURVNUU0VTU0lPTjAwMDAwMDAwMDAwMDAiLCJhdWQiOiJjbGllbnQiLCJzdWIiOiIwMUpURVNUVVNFUjAwMDAwMDAwMDAwMDAwMDAwIiwidyI6dHJ1ZSwiZXhwIjoxOTAwMDAwMDAwLCJuIjoiYWJjMTIzIn0.PtCiVt0KkqNOxHVpYCgRJumDkqjyxeKI2TIuZEm8x7E"
	vectorSID   = "01JTESTSESSION0000000000000"
)

func TestTokenVector(t *testing.T) {
	key, err := DeriveKey(vectorKey)
	if err != nil || hex.EncodeToString(key) != vectorHex {
		t.Fatalf("key %x %v", key, err)
	}
	v := NewVerifier(key, func() time.Time { return time.Unix(1_800_000_000, 0) })
	c, err := v.Verify(vectorToken, AudClient, vectorSID)
	if err != nil {
		t.Fatal(err)
	}
	if c.Sub != "01JTESTUSER00000000000000000" || !c.W || c.Exp != 1900000000 || c.N != "abc123" {
		t.Fatalf("%+v", c)
	}
	// Our own signer agrees with the vector (same bytes, same key order).
	if got := Sign(key, c); got != vectorToken {
		t.Fatalf("sign = %s", got)
	}
	if _, err := v.Verify(vectorToken, AudClient, vectorSID); !errors.Is(err, ErrToken) || !strings.Contains(err.Error(), "already used") {
		t.Fatalf("reused ticket: %v", err)
	}
	// A raw APP_KEY (no base64: prefix) is used as is.
	if k, _ := DeriveKey("plain-secret"); bytes.Equal(k, key) || len(k) != 32 {
		t.Fatalf("raw key %x", k)
	}
	if _, err := DeriveKey("base64:%%%"); err == nil {
		t.Fatal("bad base64 accepted")
	}
}

func TestTokenRefusals(t *testing.T) {
	key, _ := DeriveKey(vectorKey)
	now := time.Unix(1_800_000_000, 0)
	v := NewVerifier(key, func() time.Time { return now })
	ok := Claims{V: 1, SID: vectorSID, Aud: AudAgent, Sub: "srv", W: true, Exp: now.Unix() + 60}
	if _, err := v.Verify(Sign(key, ok), AudAgent, vectorSID); err != nil {
		t.Fatal(err)
	}
	// Agent tokens are reusable.
	if _, err := v.Verify(Sign(key, ok), AudAgent, vectorSID); err != nil {
		t.Fatal(err)
	}
	mod := func(f func(*Claims)) string { c := ok; f(&c); return Sign(key, c) }
	other, _ := DeriveKey("base64:" + strings.Repeat("A", 42) + "E=")
	for name, tc := range map[string]struct{ tok, aud, sid string }{
		"wrong audience":  {Sign(key, ok), AudClient, vectorSID},
		"other session":   {Sign(key, ok), AudAgent, "01JOTHERSESSION00000000000"},
		"expired":         {mod(func(c *Claims) { c.Exp = now.Unix() }), AudAgent, vectorSID},
		"version":         {mod(func(c *Claims) { c.V = 2 }), AudAgent, vectorSID},
		"other key":       {Sign(other, ok), AudAgent, vectorSID},
		"tampered":        {strings.Replace(Sign(key, ok), ".", "x.", 1), AudAgent, vectorSID},
		"malformed":       {"nodot", AudAgent, vectorSID},
		"empty signature": {"abc.", AudAgent, vectorSID},
		"client no nonce": {mod(func(c *Claims) { c.Aud = AudClient }), AudClient, vectorSID},
	} {
		if _, err := v.Verify(tc.tok, tc.aud, tc.sid); !errors.Is(err, ErrToken) {
			t.Errorf("%s: %v", name, err)
		}
	}
	// Nonces are forgotten once their ticket expired.
	tk := mod(func(c *Claims) { c.Aud, c.N, c.Exp = AudClient, "n1", now.Unix()+5 })
	if _, err := v.Verify(tk, AudClient, vectorSID); err != nil {
		t.Fatal(err)
	}
	now = now.Add(10 * time.Second)
	v.Sweep()
	if len(v.nonces) != 0 {
		t.Fatalf("nonces %v", v.nonces)
	}
}

func TestRing(t *testing.T) {
	r := newRing(8)
	r.write([]byte("abc"))
	if string(r.bytes()) != "abc" {
		t.Fatal(string(r.bytes()))
	}
	r.write([]byte("defgh"))
	r.write([]byte("ij"))
	if string(r.bytes()) != "cdefghij" {
		t.Fatal(string(r.bytes()))
	}
	r.write([]byte("0123456789"))
	if string(r.bytes()) != "23456789" {
		t.Fatal(string(r.bytes()))
	}
	r.write([]byte("x"))
	if string(r.bytes()) != "3456789x" {
		t.Fatal(string(r.bytes()))
	}
}

// harness runs a relay on httptest with short timings and mints tokens.
type harness struct {
	t     *testing.T
	key   []byte
	s     *Server
	srv   *httptest.Server
	nonce atomic.Int64
}

func newHarness(t *testing.T, o Options) *harness {
	key, _ := DeriveKey(vectorKey)
	o.Key = key
	h := &harness{t: t, key: key}
	h.s = New(o)
	h.srv = httptest.NewServer(h.s.Handler())
	t.Cleanup(func() { h.s.Close(); h.srv.Close() })
	return h
}

func (h *harness) ws(path string) string { return "ws" + strings.TrimPrefix(h.srv.URL, "http") + path }

func (h *harness) agent(sid string) *websocket.Conn {
	h.t.Helper()
	c, resp, err := h.dialAgent(sid, true)
	if err != nil {
		h.t.Fatalf("agent dial: %v %v", err, resp)
	}
	return c
}

func (h *harness) dialAgent(sid string, fingerprint bool) (*websocket.Conn, *http.Response, error) {
	tok := Sign(h.key, Claims{V: 1, SID: sid, Aud: AudAgent, Sub: "srv-1", W: true, Exp: time.Now().Unix() + 600})
	hdr := http.Header{"Authorization": {"Bearer " + tok}}
	if fingerprint {
		hdr.Set(fingerprintHdr, "ab12")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	c, resp, err := websocket.Dial(ctx, h.ws("/agent/v1/relay/sessions/"+sid), &websocket.DialOptions{HTTPHeader: hdr, Subprotocols: []string{Subprotocol}})
	if c != nil {
		c.SetReadLimit(MaxMessage)
	}
	return c, resp, err
}

func (h *harness) ticket(sid string, writer bool) string {
	return Sign(h.key, Claims{V: 1, SID: sid, Aud: AudClient, Sub: "user-1", W: writer, Exp: time.Now().Unix() + 60, N: "n" + strconv.FormatInt(h.nonce.Add(1), 10)})
}

func (h *harness) dialClient(ticket, sid string) (*websocket.Conn, *http.Response, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	c, resp, err := websocket.Dial(ctx, h.ws("/terminal/v1/sessions/"+sid+"?ticket="+ticket), nil)
	if c != nil {
		c.SetReadLimit(MaxMessage)
	}
	return c, resp, err
}

func (h *harness) client(sid string, writer bool) *websocket.Conn {
	h.t.Helper()
	c, resp, err := h.dialClient(h.ticket(sid, writer), sid)
	if err != nil {
		h.t.Fatalf("client dial: %v %v", err, resp)
	}
	return c
}

func read(t *testing.T, c *websocket.Conn) (websocket.MessageType, string) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	typ, b, err := c.Read(ctx)
	if err != nil {
		t.Fatalf("read: %v", err)
	}
	return typ, string(b)
}

func expect(t *testing.T, c *websocket.Conn, typ websocket.MessageType, want string) {
	t.Helper()
	gt, got := read(t, c)
	if gt != typ || got != want {
		t.Fatalf("got %v %q, want %v %q", gt, got, typ, want)
	}
}

func write(t *testing.T, c *websocket.Conn, typ websocket.MessageType, s string) {
	t.Helper()
	if err := c.Write(context.Background(), typ, []byte(s)); err != nil {
		t.Fatal(err)
	}
}

const (
	sid     = "01JSESSION0000000000000000"
	bin     = websocket.MessageBinary
	text    = websocket.MessageText
	waiting = `{"t":"waiting"}`
	ready   = `{"t":"ready"}`
)

func TestClientWaitsThenAgentAttaches(t *testing.T) {
	h := newHarness(t, Options{})
	cl := h.client(sid, true)
	expect(t, cl, text, waiting)
	ag := h.agent(sid)
	expect(t, cl, text, ready)

	write(t, ag, bin, "hello")
	expect(t, cl, bin, "hello")
	write(t, cl, bin, "ls\r")
	expect(t, ag, bin, "ls\r")
	write(t, cl, text, `{"t":"resize","cols":120,"rows":40,"extra":1}`)
	expect(t, ag, text, `{"t":"resize","cols":120,"rows":40}`)
	write(t, cl, text, `{"t":"resize","cols":0,"rows":40}`) // out of range: dropped

	// A watcher gets the replay and output, but can't type.
	rd := h.client(sid, false)
	expect(t, rd, bin, "hello")
	expect(t, rd, text, ready)
	write(t, rd, bin, "rm -rf /\r")
	write(t, rd, text, `{"t":"resize","cols":10,"rows":10}`)
	write(t, cl, bin, "x")
	expect(t, ag, bin, "x") // the watcher's input never arrived first

	write(t, ag, text, `{"t":"exit","reason":"exited","code":3}`)
	for _, c := range []*websocket.Conn{cl, rd} {
		expect(t, c, text, `{"t":"exit","reason":"exited","code":3}`)
		_, _, err := c.Read(context.Background())
		if websocket.CloseStatus(err) != websocket.StatusNormalClosure {
			t.Fatalf("close: %v", err)
		}
	}
	h.s.mu.Lock()
	n := len(h.s.hubs)
	h.s.mu.Unlock()
	if n != 0 {
		t.Fatalf("hubs left: %d", n)
	}
}

func TestReplayCapsAtSize(t *testing.T) {
	h := newHarness(t, Options{ReplaySize: 10})
	ag := h.agent(sid)
	write(t, ag, bin, "0123456789")
	write(t, ag, bin, "abcde")
	// The writes above are processed in order; one more client proves the hub saw them.
	time.Sleep(50 * time.Millisecond)
	cl := h.client(sid, true)
	expect(t, cl, bin, "56789abcde")
	expect(t, cl, text, ready)
	write(t, ag, bin, "live")
	expect(t, cl, bin, "live")
}

func TestAgentReplacement(t *testing.T) {
	h := newHarness(t, Options{})
	a1 := h.agent(sid)
	cl := h.client(sid, true)
	expect(t, cl, text, ready)
	a2 := h.agent(sid)
	if _, _, err := a1.Read(context.Background()); websocket.CloseStatus(err) != websocket.StatusNormalClosure {
		t.Fatalf("old agent: %v", err)
	}
	write(t, cl, bin, "k")
	expect(t, a2, bin, "k")
	write(t, a2, bin, "out")
	expect(t, cl, bin, "out")
}

func TestClientGivesUpWithoutAgent(t *testing.T) {
	h := newHarness(t, Options{ClientWait: 100 * time.Millisecond})
	cl := h.client(sid, true)
	expect(t, cl, text, waiting)
	expect(t, cl, text, `{"t":"error","message":"The server did not attach to this session."}`)
	if _, _, err := cl.Read(context.Background()); websocket.CloseStatus(err) == -1 {
		t.Fatalf("not closed: %v", err)
	}
}

func TestAgentGrace(t *testing.T) {
	h := newHarness(t, Options{AgentGrace: 150 * time.Millisecond})
	ag := h.agent(sid)
	cl := h.client(sid, true)
	expect(t, cl, text, ready)
	// Back within the grace period: the session goes on.
	ag.CloseNow()
	expect(t, cl, text, waiting)
	ag = h.agent(sid)
	expect(t, cl, text, ready)
	write(t, ag, bin, "still here")
	expect(t, cl, bin, "still here")
	// Gone for good.
	ag.CloseNow()
	expect(t, cl, text, waiting)
	expect(t, cl, text, `{"t":"error","message":"The server lost its connection to this session."}`)
}

func TestRefusedConnections(t *testing.T) {
	h := newHarness(t, Options{})
	if _, resp, err := h.dialAgent(sid, false); err == nil || resp == nil || resp.StatusCode != http.StatusForbidden {
		t.Fatalf("agent without fingerprint: %v %v", err, resp)
	}
	if _, resp, err := h.dialClient("bogus.token", sid); err == nil || resp == nil || resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("bad ticket: %v %v", err, resp)
	}
	tk := h.ticket(sid, true)
	if _, resp, err := h.dialClient(tk, "01JOTHERSESSION00000000000"); err == nil || resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("other session: %v", err)
	}
	c, _, err := h.dialClient(tk, sid)
	if err != nil {
		t.Fatal(err)
	}
	c.CloseNow()
	if _, resp, err := h.dialClient(tk, sid); err == nil || resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("reused ticket: %v", err)
	}
	resp, err := http.Get(h.srv.URL + "/healthz")
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("healthz: %v %v", err, resp)
	}
	resp.Body.Close()
}

func TestSweepDropsIdleHubs(t *testing.T) {
	now := time.Now()
	h := newHarness(t, Options{Now: func() time.Time { return now }})
	ag := h.agent(sid)
	ag.Close(websocket.StatusNormalClosure, "")
	time.Sleep(50 * time.Millisecond)
	h.s.sweep()
	if len(h.s.hubs) != 1 {
		t.Fatal("hub swept too early")
	}
	now = now.Add(DefaultIdle)
	h.s.sweep()
	if len(h.s.hubs) != 0 {
		t.Fatal("idle hub kept")
	}
}

func TestSlowClientIsDropped(t *testing.T) {
	h := newHarness(t, Options{})
	ag := h.agent(sid)
	slow := h.client(sid, false) // never reads
	fast := h.client(sid, false)
	expect(t, fast, text, ready)
	chunk := strings.Repeat("x", 32<<10)
	done := make(chan struct{})
	go func() {
		defer close(done)
		for {
			ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
			_, _, err := fast.Read(ctx)
			cancel()
			if err != nil {
				return
			}
		}
	}()
	for i := 0; i < 2000; i++ {
		write(t, ag, bin, chunk)
		h.s.mu.Lock()
		hb := h.s.hubs[sid]
		h.s.mu.Unlock()
		hb.mu.Lock()
		n := len(hb.clients)
		hb.mu.Unlock()
		if n == 1 {
			slow.CloseNow()
			fast.CloseNow()
			<-done
			return
		}
	}
	t.Fatal("slow client never dropped")
}

func TestRingGrowsLazily(t *testing.T) {
	r := newRing(ReplaySize)
	if cap(r.buf) != 0 {
		t.Fatal("allocated up front")
	}
	r.write([]byte("hello"))
	if cap(r.buf) > 4096 || string(r.bytes()) != "hello" {
		t.Fatalf("cap %d %q", cap(r.buf), r.bytes())
	}
	// Growing past the cap keeps the last max bytes, then turns circular.
	r = newRing(8)
	r.write([]byte("abcdef"))
	r.write([]byte("ghij"))
	if string(r.bytes()) != "cdefghij" || len(r.buf) != 8 {
		t.Fatalf("%q len %d", r.bytes(), len(r.buf))
	}
	r.write([]byte("kl"))
	if string(r.bytes()) != "efghijkl" {
		t.Fatalf("%q", r.bytes())
	}
	r = newRing(4)
	r.write([]byte("ab"))
	r.write([]byte("0123456789"))
	if string(r.bytes()) != "6789" {
		t.Fatalf("%q", r.bytes())
	}
}

// Keystrokes typed before the agent attaches reach it, in order, up to PendingInput bytes.
func TestInputWaitsForAgent(t *testing.T) {
	h := newHarness(t, Options{})
	cl := h.client(sid, true)
	expect(t, cl, text, waiting)
	write(t, cl, bin, "first")
	write(t, cl, text, `{"t":"resize","cols":90,"rows":20}`)
	write(t, cl, bin, "second")
	write(t, cl, bin, strings.Repeat("x", PendingInput)) // over the cap: dropped
	time.Sleep(50 * time.Millisecond)
	ag := h.agent(sid)
	expect(t, ag, bin, "first")
	expect(t, ag, text, `{"t":"resize","cols":90,"rows":20}`)
	expect(t, ag, bin, "second")
	expect(t, cl, text, ready)
	write(t, cl, bin, "live")
	expect(t, ag, bin, "live") // the oversized chunk never arrived
}

// A grace timer set for an earlier disconnect never ends a hub whose agent came back (and left again later).
func TestGraceTimerOfEarlierDetach(t *testing.T) {
	h := newHarness(t, Options{AgentGrace: time.Hour})
	p1 := &peer{out: make(chan frame, QueueSize)}
	hb := h.s.hub(sid)
	hb.attachAgent(p1)
	hb.detachAgent(p1)
	first := hb.grace
	p2 := &peer{out: make(chan frame, QueueSize)}
	hb.attachAgent(p2)
	hb.detachAgent(p2)
	// Simulate the first timer firing late, after the second detach.
	first.Reset(time.Millisecond)
	time.Sleep(50 * time.Millisecond)
	hb.mu.Lock()
	ended := hb.ended
	hb.mu.Unlock()
	if ended {
		t.Fatal("stale grace timer ended the hub")
	}
}

func TestAgentTokenBoundToCertificate(t *testing.T) {
	h := newHarness(t, Options{})
	dial := func(fp, hdr string) int {
		tok := Sign(h.key, Claims{V: 1, SID: sid, Aud: AudAgent, Sub: "srv-1", W: true, Exp: time.Now().Unix() + 600, Fp: fp})
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		c, resp, err := websocket.Dial(ctx, h.ws("/agent/v1/relay/sessions/"+sid), &websocket.DialOptions{HTTPHeader: http.Header{"Authorization": {"Bearer " + tok}, fingerprintHdr: {hdr}}})
		if err == nil {
			c.CloseNow()
			return 101
		}
		return resp.StatusCode
	}
	fp := strings.Repeat("ab", 32)
	if code := dial(fp, strings.ToUpper(fp)); code != 101 {
		t.Fatalf("matching fingerprint: %d", code)
	}
	if code := dial(fp, strings.Repeat("cd", 32)); code != http.StatusForbidden {
		t.Fatalf("other certificate: %d", code)
	}
	if code := dial("", strings.Repeat("cd", 32)); code != 101 {
		t.Fatalf("unbound token: %d", code)
	}
}

func TestKick(t *testing.T) {
	h := newHarness(t, Options{})
	kick := func(s, keep string) int {
		tok := Sign(h.key, Claims{V: 1, SID: s, Aud: AudControl, Exp: time.Now().Unix() + 60, Keep: keep})
		req, _ := http.NewRequest(http.MethodPost, h.srv.URL+"/internal/v1/sessions/"+s+"/kick", nil)
		req.Header.Set("Authorization", "Bearer "+tok)
		resp, err := http.DefaultClient.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		resp.Body.Close()
		return resp.StatusCode
	}
	ag := h.agent(sid)
	ticket := func(sub string) *websocket.Conn {
		tk := Sign(h.key, Claims{V: 1, SID: sid, Aud: AudClient, Sub: sub, W: true, Exp: time.Now().Unix() + 60, N: sub + strconv.FormatInt(h.nonce.Add(1), 10)})
		c, _, err := h.dialClient(tk, sid)
		if err != nil {
			t.Fatal(err)
		}
		for typ, b := read(t, c); typ != text || b != ready; typ, b = read(t, c) { // past the replay
		}
		return c
	}
	owner, watcher := ticket("owner"), ticket("watcher")
	if code := kick(sid, "owner"); code != http.StatusNoContent {
		t.Fatalf("kick: %d", code)
	}
	expect(t, watcher, text, `{"t":"error","message":"Your access to this session ended."}`)
	write(t, ag, bin, "still")
	expect(t, owner, bin, "still")
	// Kick everyone: the agent stays attached.
	if code := kick(sid, ""); code != http.StatusNoContent {
		t.Fatal(code)
	}
	expect(t, owner, text, `{"t":"error","message":"Your access to this session ended."}`)
	late := ticket("owner")
	write(t, ag, bin, "on")
	expect(t, late, bin, "on")
	// Unknown sessions are fine; other tokens are not.
	if code := kick("01JUNKNOWNSESSION000000000", ""); code != http.StatusNoContent {
		t.Fatal(code)
	}
	req, _ := http.NewRequest(http.MethodPost, h.srv.URL+"/internal/v1/sessions/"+sid+"/kick", nil)
	req.Header.Set("Authorization", "Bearer "+Sign(h.key, Claims{V: 1, SID: sid, Aud: AudAgent, Exp: time.Now().Unix() + 60}))
	resp, _ := http.DefaultClient.Do(req)
	resp.Body.Close()
	if resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("agent token kicked: %d", resp.StatusCode)
	}
}

func TestPeerQueueBytes(t *testing.T) {
	p := &peer{out: make(chan frame, QueueSize)}
	big := make([]byte, QueueBytes/2)
	if !p.send(bin, big) || !p.send(bin, big) {
		t.Fatal("within the byte cap refused")
	}
	if p.send(bin, []byte("x")) {
		t.Fatal("over the byte cap accepted")
	}
	if p.queued.Load() != QueueBytes {
		t.Fatalf("queued %d", p.queued.Load())
	}
}
