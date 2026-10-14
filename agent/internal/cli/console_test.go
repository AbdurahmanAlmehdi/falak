package cli

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/coder/websocket"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
	"github.com/OthmanHaba/falak/agent/internal/relay"
)

// fakeTerminal records raw mode and serves a fixed size; resize() fires a window change.
type fakeTerminal struct {
	mu         sync.Mutex
	raw, reset int
	cols, rows int
	ch         chan struct{}
}

func (f *fakeTerminal) MakeRaw() (func(), error) {
	f.mu.Lock()
	f.raw++
	f.mu.Unlock()
	return func() { f.mu.Lock(); f.reset++; f.mu.Unlock() }, nil
}

func (f *fakeTerminal) Size() (int, int, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.cols, f.rows, nil
}

func (f *fakeTerminal) Resized() (<-chan struct{}, func()) { return f.ch, func() {} }

func (f *fakeTerminal) resize(cols, rows int) {
	f.mu.Lock()
	f.cols, f.rows = cols, rows
	f.mu.Unlock()
	f.ch <- struct{}{}
}

// consoleFixture is the console API plus a scripted relay: every connection runs the next script.
type consoleFixture struct {
	t       *testing.T
	api     *httptest.Server
	relay   *httptest.Server
	mu      sync.Mutex
	opened  []string       // "POST <path> <body>"
	tickets int            // reconnects
	closed  int            // DELETEs
	status  int            // POST console answer (0 = 201)
	errBody map[string]any // body for status
	scripts []func(*websocket.Conn)
	hang    bool     // the relay never answers the handshake
	frames  []string // what the relay read: "b:<data>" or "t:<json>"
}

func newConsoleFixture(t *testing.T) *consoleFixture {
	f := &consoleFixture{t: t}
	f.relay = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		hang := f.hang
		f.mu.Unlock()
		if hang {
			<-r.Context().Done()
			return
		}
		c, err := websocket.Accept(w, r, &websocket.AcceptOptions{Subprotocols: []string{relay.Subprotocol}})
		if err != nil {
			return
		}
		defer c.CloseNow()
		f.mu.Lock()
		if len(f.scripts) == 0 {
			f.mu.Unlock()
			return
		}
		script := f.scripts[0]
		f.scripts = f.scripts[1:]
		f.mu.Unlock()
		script(c)
	}))
	reply := func(w http.ResponseWriter, code int, v any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		_ = json.NewEncoder(w).Encode(v)
	}
	ctr := "compose:web"
	f.api = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		if r.Header.Get("Authorization") != "Bearer "+token {
			reply(w, 401, map[string]string{"message": "Unauthenticated."})
			return
		}
		b, _ := io.ReadAll(r.Body)
		p := r.URL.Path
		wsURL := "ws" + strings.TrimPrefix(f.relay.URL, "http") + "/terminal/v1/sessions/01JSESS?ticket=t"
		switch {
		case p == api.PathServers:
			reply(w, 200, map[string]any{"data": servers})
		case strings.HasPrefix(p, "/api/v1/servers/") && r.Method == http.MethodGet:
			reply(w, 404, map[string]string{"message": "Server not found."})
		case p == api.PathDatabaseInstances:
			reply(w, 200, map[string]any{"data": []api.DatabaseInstance{{ID: dbID, Name: "shop"}}})
		case p == api.Path(api.PathDatabaseInstance, "instance", "shop"):
			reply(w, 404, map[string]string{"message": "Not Found"})
		case p == api.Path(api.PathSiteConsole, "site", "acme") && r.Method == http.MethodGet:
			reply(w, 200, map[string]any{"data": api.ConsoleOptions{Servers: []api.ConsoleServer{{ID: "s1", Name: "web-1", Leader: true}}, Services: []string{"web", "worker"}, Runtime: "compose"}})
		case r.Method == http.MethodPost && strings.HasSuffix(p, "/console"):
			f.opened = append(f.opened, "POST "+p+" "+strings.TrimSpace(string(b)))
			if f.status != 0 {
				reply(w, f.status, f.errBody)
				return
			}
			s := api.ConsoleSession{SessionID: "01JSESS", URL: wsURL, Writable: true, ServerID: servers[0].ID, ServerName: "web-1", TargetType: "site", TargetName: "acme", Container: &ctr}
			if strings.Contains(p, "/servers/") {
				s.TargetType, s.TargetName, s.Container = "server", "web-1", nil
			}
			reply(w, 201, map[string]any{"data": s})
		case p == api.Path(api.PathTerminalSessionTicket, "session", "01JSESS"):
			f.tickets++
			reply(w, 200, map[string]any{"data": api.ConsoleTicket{URL: wsURL, Writable: true}})
		case p == api.Path(api.PathTerminalSession, "session", "01JSESS") && r.Method == http.MethodDelete:
			f.closed++
			w.WriteHeader(204)
		default:
			reply(w, 404, map[string]string{"message": "Not Found"})
		}
	}))
	t.Cleanup(func() { f.api.Close(); f.relay.Close() })
	return f
}

func (f *consoleFixture) script(s ...func(*websocket.Conn)) {
	f.mu.Lock()
	f.scripts = append(f.scripts, s...)
	f.mu.Unlock()
}

// read reads one frame and records it.
func (f *consoleFixture) read(c *websocket.Conn) string {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	typ, b, err := c.Read(ctx)
	if err != nil {
		f.t.Errorf("relay read: %v", err)
		return ""
	}
	s := "t:" + string(b)
	if typ == websocket.MessageBinary {
		s = "b:" + string(b)
	}
	f.mu.Lock()
	f.frames = append(f.frames, s)
	f.mu.Unlock()
	return s
}

func send(c *websocket.Conn, typ websocket.MessageType, s string) {
	_ = c.Write(context.Background(), typ, []byte(s))
}

func (f *consoleFixture) app(stdin io.Reader, term Terminal) (*App, *bytes.Buffer, *bytes.Buffer) {
	out, errb := &bytes.Buffer{}, &bytes.Buffer{}
	env := map[string]string{"FALAK_URL": f.api.URL, "FALAK_TOKEN": token, "FALAK_CONFIG_DIR": f.t.TempDir()}
	return &App{Stdout: out, Stderr: errb, Stdin: stdin, Getenv: func(k string) string { return env[k] }, Version: "test", Terminal: term}, out, errb
}

func TestConsoleRelayFramesAndReconnect(t *testing.T) {
	f := newConsoleFixture(t)
	ft := &fakeTerminal{cols: 100, rows: 30, ch: make(chan struct{}, 1)}
	stdin, typing := io.Pipe()
	defer typing.Close()
	f.script(
		// First connection: replay, ready; gets the size and a keystroke, then drops.
		func(c *websocket.Conn) {
			send(c, websocket.MessageBinary, "replay$ ")
			send(c, websocket.MessageText, `{"t":"ready"}`)
			f.read(c) // the size on ready
			go func() { _, _ = typing.Write([]byte("ls\r")) }()
			f.read(c)
			send(c, websocket.MessageBinary, "ls\r\nfile\r\n")
			// A window change while attached.
			ft.resize(120, 40)
			f.read(c)
		},
		// Second connection (new ticket): ready, then the shell exits with 7.
		func(c *websocket.Conn) {
			send(c, websocket.MessageText, `{"t":"waiting"}`)
			send(c, websocket.MessageText, `{"t":"ready"}`)
			f.read(c)
			send(c, websocket.MessageBinary, "bye\r\n")
			send(c, websocket.MessageText, `{"t":"exit","reason":"exited","code":7}`)
			_, _, _ = c.Read(context.Background()) // until the CLI closes
		},
	)
	a, out, errb := f.app(stdin, ft)
	code := a.Run(context.Background(), []string{"console", "acme", "--service", "web"})
	if code != 7 {
		t.Fatalf("exit %d, stderr %s", code, errb.String())
	}
	if got := out.String(); got != "replay$ ls\r\nfile\r\nbye\r\n" {
		t.Fatalf("stdout %q", got)
	}
	if !strings.Contains(errb.String(), "Connected to acme (container web) on web-1. Exit the shell to close the session.") || strings.Contains(errb.String(), "falak:") {
		t.Fatalf("stderr %q", errb.String())
	}
	want := []string{`t:{"t":"resize","cols":100,"rows":30}`, "b:ls\r", `t:{"t":"resize","cols":120,"rows":40}`, `t:{"t":"resize","cols":120,"rows":40}`}
	if strings.Join(f.frames, "|") != strings.Join(want, "|") {
		t.Fatalf("relay read %q", f.frames)
	}
	if len(f.opened) != 1 || f.opened[0] != `POST /api/v1/sites/acme/console {"service":"web","cols":100,"rows":30}` {
		t.Fatalf("opened %q", f.opened)
	}
	if f.tickets != 1 || f.closed != 0 || ft.raw != 1 || ft.reset != 1 {
		t.Fatalf("tickets %d closed %d raw %d reset %d", f.tickets, f.closed, ft.raw, ft.reset)
	}
}

func TestConsoleTargetsAndErrors(t *testing.T) {
	f := newConsoleFixture(t)
	exit0 := func(c *websocket.Conn) {
		send(c, websocket.MessageText, `{"t":"ready"}`)
		send(c, websocket.MessageText, `{"t":"exit","reason":"exited","code":null}`)
		_, _, _ = c.Read(context.Background())
	}
	f.script(exit0, exit0)
	// Piped stdin: no terminal, no size; --db and --host resolve names to ids.
	a, _, errb := f.app(strings.NewReader("echo hi\n"), nil)
	if code := a.Run(context.Background(), []string{"console", "--db", "shop"}); code != 0 {
		t.Fatalf("db: %d %s", code, errb.String())
	}
	a, _, errb = f.app(strings.NewReader(""), nil)
	if code := a.Run(context.Background(), []string{"console", "--host", "web-1", "--user", "deploy"}); code != 0 || !strings.Contains(errb.String(), "Connected to web-1.") {
		t.Fatalf("host: %d %s", code, errb.String())
	}
	want := []string{"POST /api/v1/databases/instances/" + dbID + "/console {}", "POST /api/v1/servers/" + servers[0].ID + "/console {\"user\":\"deploy\"}"}
	if strings.Join(f.opened, "|") != strings.Join(want, "|") {
		t.Fatalf("opened %q", f.opened)
	}

	// The relay is off: the API's message, as is.
	f.status, f.errBody = 409, map[string]any{"message": "The terminal relay is off on this installation."}
	a, _, errb = f.app(nil, nil)
	if code := a.Run(context.Background(), []string{"console", "acme"}); code != 1 || errb.String() != "falak: The terminal relay is off on this installation.\n" {
		t.Fatalf("409: %d %q", code, errb.String())
	}
	// A compose stack with several services: the choices.
	f.status, f.errBody = 422, map[string]any{"message": "Choose a service.", "errors": map[string][]string{"service": {"Choose a service."}}}
	a, _, errb = f.app(nil, nil)
	if code := a.Run(context.Background(), []string{"console", "acme"}); code != 1 || !strings.Contains(errb.String(), "choose one: --service web, worker") {
		t.Fatalf("422: %d %q", code, errb.String())
	}
	f.status = 0

	// The relay gives up on the agent: no reconnect, the message, and the session is closed.
	f.script(func(c *websocket.Conn) {
		send(c, websocket.MessageText, `{"t":"waiting"}`)
		send(c, websocket.MessageText, `{"t":"error","message":"The server did not attach to this session."}`)
		_, _, _ = c.Read(context.Background())
	})
	a, _, errb = f.app(nil, nil)
	if code := a.Run(context.Background(), []string{"console", "acme"}); code != 1 || !strings.Contains(errb.String(), "falak: The server did not attach to this session.") {
		t.Fatalf("error frame: %d %q", code, errb.String())
	}
	if f.tickets != 0 || f.closed != 1 {
		t.Fatalf("tickets %d closed %d", f.tickets, f.closed)
	}

	for _, args := range [][]string{{"console"}, {"console", "acme", "--db", "x"}, {"console", "--db", "x", "--service", "web"}, {"console", "acme", "--user", "root"}} {
		a, _, _ = f.app(nil, nil)
		if code := a.Run(context.Background(), args); code != ExitUsage {
			t.Errorf("%v: exit %d", args, code)
		}
	}
}

func TestConsoleGivesUpAfterRetries(t *testing.T) {
	defer func(d time.Duration) { consoleBackoff = d }(consoleBackoff)
	consoleBackoff = time.Millisecond
	f := newConsoleFixture(t)
	drop := func(c *websocket.Conn) { c.CloseNow() }
	f.script(drop, drop, drop, drop, drop, drop, drop)
	a, _, errb := f.app(nil, nil)
	if code := a.Run(context.Background(), []string{"console", "acme"}); code != 1 || !strings.Contains(errb.String(), "lost the connection to the console") {
		t.Fatalf("%d %q", code, errb.String())
	}
	if f.tickets != consoleRetries || f.closed != 1 {
		t.Fatalf("tickets %d closed %d", f.tickets, f.closed)
	}
}

// A reconnect doesn't print the relay's replay again.
func TestConsoleReconnectSkipsReplay(t *testing.T) {
	f := newConsoleFixture(t)
	f.script(
		func(c *websocket.Conn) {
			send(c, websocket.MessageBinary, "$ ")
			send(c, websocket.MessageText, `{"t":"ready"}`)
			send(c, websocket.MessageBinary, "out\r\n")
			time.Sleep(50 * time.Millisecond)
		},
		func(c *websocket.Conn) {
			send(c, websocket.MessageBinary, "$ out\r\n") // the replay
			send(c, websocket.MessageText, `{"t":"ready"}`)
			send(c, websocket.MessageBinary, "more\r\n")
			send(c, websocket.MessageText, `{"t":"exit","reason":"exited","code":0}`)
			_, _, _ = c.Read(context.Background())
		},
	)
	a, out, errb := f.app(nil, nil)
	if code := a.Run(context.Background(), []string{"console", "acme"}); code != 0 {
		t.Fatalf("%d %s", code, errb.String())
	}
	if out.String() != "$ out\r\nmore\r\n" {
		t.Fatalf("stdout %q", out.String())
	}
}

// Ctrl-C while the console is still connecting aborts it: the terminal is restored, the session closed, exit 130.
func TestConsoleAbortWhileConnecting(t *testing.T) {
	f := newConsoleFixture(t)
	f.hang = true
	ft := &fakeTerminal{cols: 80, rows: 24, ch: make(chan struct{}, 1)}
	stdin, typing := io.Pipe()
	defer typing.Close()
	go func() {
		time.Sleep(100 * time.Millisecond)
		_, _ = typing.Write([]byte("ls\x03"))
	}()
	a, _, errb := f.app(stdin, ft)
	start := time.Now()
	if code := a.Run(context.Background(), []string{"console", "acme"}); code != 130 {
		t.Fatalf("exit %d %s", code, errb.String())
	}
	if time.Since(start) > 5*time.Second {
		t.Fatal("abort waited for the dial")
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.closed != 1 || ft.raw != 1 || ft.reset != 1 {
		t.Fatalf("closed %d raw %d reset %d", f.closed, ft.raw, ft.reset)
	}
}
