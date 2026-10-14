package pty

import (
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/coder/websocket"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/relay"
)

func TestRelayURL(t *testing.T) {
	for base, want := range map[string]string{
		"https://agents.example/agent/v1":  "wss://agents.example/agent/v1/relay/sessions/s1",
		"https://agents.example/agent/v1/": "wss://agents.example/agent/v1/relay/sessions/s1",
		"http://127.0.0.1:8080/agent/v1":   "ws://127.0.0.1:8080/agent/v1/relay/sessions/s1",
	} {
		if got := relayURL(base, "s1"); got != want {
			t.Errorf("%s → %s", base, got)
		}
	}
}

// relayFixture is a real relay behind httptest; the edge's fingerprint header is added like Caddy does.
type relayFixture struct {
	key []byte
	srv *httptest.Server
}

func newRelayFixture(t *testing.T) *relayFixture {
	key, _ := relay.DeriveKey("base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=")
	rs := relay.New(relay.Options{Key: key})
	h := rs.Handler()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasPrefix(r.URL.Path, "/agent/") {
			r.Header.Set("X-Falak-Client-Cert-Fingerprint", "ab12")
		}
		h.ServeHTTP(w, r)
	}))
	t.Cleanup(func() { rs.Close(); srv.Close() })
	return &relayFixture{key: key, srv: srv}
}

func (f *relayFixture) agentToken(sid string) string {
	return relay.Sign(f.key, relay.Claims{V: 1, SID: sid, Aud: relay.AudAgent, Sub: "srv", W: true, Exp: time.Now().Unix() + 600})
}

func (f *relayFixture) client(t *testing.T, sid string) *websocket.Conn {
	t.Helper()
	tk := relay.Sign(f.key, relay.Claims{V: 1, SID: sid, Aud: relay.AudClient, Sub: "u", W: true, Exp: time.Now().Unix() + 60, N: sid})
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	c, _, err := websocket.Dial(ctx, "ws"+strings.TrimPrefix(f.srv.URL, "http")+"/terminal/v1/sessions/"+sid+"?ticket="+tk, nil)
	if err != nil {
		t.Fatal(err)
	}
	c.SetReadLimit(relay.MaxMessage)
	return c
}

// readUntil reads frames, collecting output, until a text frame of type stop (returned) arrives.
func readUntil(t *testing.T, c *websocket.Conn, out *strings.Builder, stop func(relay.Frame, string) bool) relay.Frame {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	for {
		typ, b, err := c.Read(ctx)
		if err != nil {
			t.Fatalf("read: %v (output so far %q)", err, out.String())
		}
		if typ == websocket.MessageBinary {
			out.Write(b)
			if stop(relay.Frame{}, out.String()) {
				return relay.Frame{}
			}
			continue
		}
		f, _ := relay.ParseFrame(b)
		if stop(f, out.String()) {
			return f
		}
	}
}

func TestRelaySession(t *testing.T) {
	fx := newRelayFixture(t)
	const sid = "sess-relay1"
	m := New(Options{RelayBase: fx.srv.URL + "/agent/v1"})
	col := &commands.Collector{}
	st := commands.NewTestStream("r1", col)
	type result struct {
		res any
		err error
	}
	done := make(chan result, 1)
	go func() {
		res, err := m.Open(context.Background(), OpenPayload{SessionID: sid, Shell: "/bin/sh", Env: map[string]string{"PS1": "$ "}, RelayToken: fx.agentToken(sid)}, st)
		done <- result{res, err}
	}()
	cl := fx.client(t, sid)
	var out strings.Builder
	readUntil(t, cl, &out, func(f relay.Frame, _ string) bool { return f.T == relay.TReady })

	ctx := context.Background()
	if err := cl.Write(ctx, websocket.MessageBinary, []byte("echo hi-$((40+2))\n")); err != nil {
		t.Fatal(err)
	}
	readUntil(t, cl, &out, func(_ relay.Frame, s string) bool { return strings.Contains(s, "hi-42") })
	if err := cl.Write(ctx, websocket.MessageText, relay.ResizeFrame(132, 43)); err != nil {
		t.Fatal(err)
	}
	// The resize and the next keystrokes travel the same connection, in order.
	if err := cl.Write(ctx, websocket.MessageBinary, []byte("stty size\n")); err != nil {
		t.Fatal(err)
	}
	readUntil(t, cl, &out, func(_ relay.Frame, s string) bool { return strings.Contains(s, "43 132") })
	if err := cl.Write(ctx, websocket.MessageBinary, []byte("exit 3\n")); err != nil {
		t.Fatal(err)
	}
	f := readUntil(t, cl, &out, func(f relay.Frame, _ string) bool { return f.T == relay.TExit })
	if f.Reason != ReasonExited || f.Code == nil || *f.Code != 3 {
		t.Fatalf("exit frame %+v", f)
	}
	if _, _, err := cl.Read(ctx); websocket.CloseStatus(err) != websocket.StatusNormalClosure {
		t.Fatalf("close: %v", err)
	}
	r := <-done
	if r.err != nil || r.res.(OpenResult).Reason != ReasonExited {
		t.Fatalf("%+v", r)
	}
	// The output events go on as before (recording, older clients).
	st.Flush()
	if !strings.Contains(decoded(col, "r1"), "hi-42") {
		t.Fatalf("events %q", decoded(col, "r1"))
	}
}

// A relay that is down never fails or delays the session.
func TestRelayDownIsHarmless(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	addr := ln.Addr().String()
	ln.Close()
	m := New(Options{RelayBase: "http://" + addr + "/agent/v1"})
	col := &commands.Collector{}
	go func() {
		for i := 0; i < 200 && m.count() == 0; i++ {
			time.Sleep(5 * time.Millisecond)
		}
		// A dial or two failed meanwhile; then "exit 4\n".
		time.Sleep(700 * time.Millisecond)
		_, _ = m.Input(InputPayload{SessionID: "sess-down1", Data: "ZXhpdCA0Cg=="})
	}()
	start := time.Now()
	res, err := m.Open(context.Background(), OpenPayload{SessionID: "sess-down1", Shell: "/bin/sh", RelayToken: strings.Repeat("t", 40)}, commands.NewTestStream("d1", col))
	if err != nil {
		t.Fatal(err)
	}
	if r := res.(OpenResult); r.Reason != ReasonExited || *r.ExitCode != 4 {
		t.Fatalf("%+v", r)
	}
	if d := time.Since(start); d > 5*time.Second {
		t.Fatalf("took %s", d)
	}
}

// Without a relay base, a token is ignored.
func TestRelayTokenWithoutBase(t *testing.T) {
	m := New(Options{})
	if l := m.startRelay(&session{id: "x"}, "token"); l != nil {
		t.Fatal("link without a base")
	}
	var l *relayLink
	l.send([]byte("x")) // nil links are no-ops
	l.finish(ReasonClosed, nil)
}

func TestRelayOverflowClearsQueue(t *testing.T) {
	l := &relayLink{out: make(chan []byte, relay.QueueSize), overflow: make(chan struct{}, 1)}
	for i := 0; i < relay.QueueSize; i++ {
		l.send([]byte("x"))
	}
	if len(l.overflow) != 0 {
		t.Fatal("overflow before full")
	}
	l.send([]byte("one too many"))
	if len(l.out) != 0 || len(l.overflow) != 1 {
		t.Fatalf("queue %d overflow %d", len(l.out), len(l.overflow))
	}
}

// After a reconnect, output queued while disconnected is dropped and a stale overflow doesn't cut the new
// connection; the first connection gets what was queued while it dialled.
func TestRelayReconnectStartsClean(t *testing.T) {
	type got struct {
		conn int
		data string
	}
	frames := make(chan got, 16)
	conns := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, err := websocket.Accept(w, r, nil)
		if err != nil {
			return
		}
		defer c.CloseNow()
		conns++
		n := conns
		for {
			_, b, err := c.Read(context.Background())
			if err != nil {
				return
			}
			frames <- got{n, string(b)}
			if n == 1 {
				return // drop the first connection after one frame
			}
		}
	}))
	defer srv.Close()
	second := make(chan struct{})
	dials := 0
	ctx, cancel := context.WithCancel(context.Background())
	l := &relayLink{log: New(Options{}).log, out: make(chan []byte, relay.QueueSize), overflow: make(chan struct{}, 1),
		ending: make(chan struct{}), done: make(chan struct{}), ctx: ctx, cancel: cancel}
	l.dial = func(ctx context.Context) (*websocket.Conn, error) {
		dials++
		if dials == 2 {
			<-second
		}
		c, _, err := websocket.Dial(ctx, "ws"+strings.TrimPrefix(srv.URL, "http"), nil)
		return c, err
	}
	l.send([]byte("prompt")) // queued before the first connection
	go l.run()
	if g := <-frames; g != (got{1, "prompt"}) {
		t.Fatalf("%+v", g)
	}
	// Disconnected now (or soon): this output is stale by the time the relay is back.
	time.Sleep(100 * time.Millisecond)
	l.send([]byte("stale"))
	l.overflow <- struct{}{}
	close(second)
	time.Sleep(200 * time.Millisecond) // the second connection is up
	l.send([]byte("fresh"))
	if g := <-frames; g != (got{2, "fresh"}) {
		t.Fatalf("%+v", g)
	}
	cancel()
	<-l.done
}
