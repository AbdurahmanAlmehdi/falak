package cli

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/coder/websocket"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
	"github.com/OthmanHaba/falak/agent/internal/relay"
)

// Terminal is the local terminal `falak console` drives. App.Terminal is nil when stdin isn't a terminal: no raw
// mode and no resizes (piped keystrokes still work).
type Terminal interface {
	// MakeRaw puts the terminal into raw mode (Ctrl-C and friends go to the remote shell) and returns its undo.
	MakeRaw() (restore func(), err error)
	Size() (cols, rows int, err error)
	// Resized signals every window size change until stop is called.
	Resized() (changes <-chan struct{}, stop func())
}

const (
	consoleSynopsis = "<site> [--server NAME|ID] [--service NAME] | --db <instance> | --host <server> [--user NAME]"
	// consoleRetries is how many times in a row a dropped console reconnects (with a new ticket).
	consoleRetries = 5
)

// consoleBackoff × the failures in a row is the pause before a reconnect.
var consoleBackoff = 300 * time.Millisecond

func cmdConsole(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("console")
	server := fs.String("server", "", "site: the server to open it on (name or id; default: the site's leader)")
	service := fs.String("service", "", "compose site: the service to open it in (required when the stack has several)")
	db := fs.String("db", "", "a database container's shell (instance name or id)")
	host := fs.String("host", "", "a shell on a server (name or id)")
	user := fs.String("user", "", "--host: the unix user (default root)")
	pos, err := a.parse(fs, args, 0, 1, consoleSynopsis)
	if err != nil {
		return err
	}
	targets := 0
	for _, set := range []bool{len(pos) == 1, *db != "", *host != ""} {
		if set {
			targets++
		}
	}
	switch {
	case targets != 1:
		return usagef("usage: falak console %s", consoleSynopsis)
	case (*server != "" || *service != "") && len(pos) == 0:
		return usagef("console: --server and --service apply to a site")
	case *user != "" && *host == "":
		return usagef("console: --user applies to --host")
	}
	c, err := a.api()
	if err != nil {
		return err
	}
	req := api.ConsoleRequest{Service: *service, User: *user}
	if a.Terminal != nil {
		if cols, rows, err := a.Terminal.Size(); err == nil && relay.ValidSize(cols, rows) {
			req.Cols, req.Rows = cols, rows
		}
	}
	var sess api.ConsoleSession
	switch {
	case *db != "":
		d, err := resolveDatabaseInstance(ctx, c, *db)
		if err != nil {
			return err
		}
		sess, err = c.OpenDatabaseConsole(ctx, d.ID, req)
		if err != nil {
			return consoleErr(err)
		}
	case *host != "":
		s, err := resolveServer(ctx, c, *host)
		if err != nil {
			return err
		}
		sess, err = c.OpenServerConsole(ctx, s.ID, req)
		if err != nil {
			return consoleErr(err)
		}
	default:
		if *server != "" {
			s, err := resolveServer(ctx, c, *server)
			if err != nil {
				return err
			}
			req.ServerID = s.ID
		}
		sess, err = c.OpenSiteConsole(ctx, pos[0], req)
		if err != nil {
			return siteConsoleErr(ctx, c, pos[0], err)
		}
	}
	return a.runConsole(ctx, c, sess)
}

// consoleErr: a 409 (the relay is off on this installation) says what to do as is.
func consoleErr(err error) error {
	var e *api.Error
	if errors.As(err, &e) && e.Status == http.StatusConflict && e.Message != "" {
		return errors.New(e.Message)
	}
	return err
}

// siteConsoleErr adds the choices when the API asks for a server or a service.
func siteConsoleErr(ctx context.Context, c api.API, site string, err error) error {
	var e *api.Error
	if !errors.As(err, &e) || e.Status != http.StatusUnprocessableEntity {
		return consoleErr(err)
	}
	_, svc := e.Errors["service"]
	_, srv := e.Errors["server_id"]
	if !svc && !srv {
		return err
	}
	opts, oerr := c.SiteConsoleOptions(ctx, site)
	if oerr != nil {
		return err
	}
	var hints []string
	if svc && len(opts.Services) > 0 {
		hints = append(hints, "--service "+strings.Join(opts.Services, ", "))
	}
	if srv && len(opts.Servers) > 0 {
		names := make([]string, 0, len(opts.Servers))
		for _, s := range opts.Servers {
			names = append(names, s.Name)
		}
		sort.Strings(names)
		hints = append(hints, "--server "+strings.Join(names, ", "))
	}
	if len(hints) == 0 {
		return err
	}
	return fmt.Errorf("%w; choose one: %s", err, strings.Join(hints, "; "))
}

// consoleTarget is how the banner names a session: "shop (container web) on main-1", or the server.
func consoleTarget(s api.ConsoleSession) string {
	if s.TargetType == "server" || s.TargetType == "" || s.TargetName == "" {
		return dash(s.ServerName)
	}
	t := s.TargetName
	if s.Container != nil && *s.Container != "" {
		_, name, _ := strings.Cut(*s.Container, ":")
		t += " (container " + name + ")"
	}
	return t + " on " + dash(s.ServerName)
}

// runConsole attaches the local terminal to the session until the remote shell exits; it exits with its code.
func (a *App) runConsole(ctx context.Context, c api.API, sess api.ConsoleSession) error {
	fmt.Fprintf(a.Stderr, "Connected to %s. Exit the shell to close the session.\n", consoleTarget(sess))
	if !sess.Writable {
		fmt.Fprintln(a.Stderr, "(read-only: your keystrokes are not sent)")
	}
	var restoreOnce sync.Once
	restoreFn := func() {}
	restore := func() { restoreOnce.Do(restoreFn) }
	defer restore() // every way out, panics included
	k := &console{a: a, c: c, sess: sess, in: make(chan []byte, 64)}
	if a.Terminal != nil {
		if r, err := a.Terminal.MakeRaw(); err == nil {
			restoreFn = r
		}
		ch, stop := a.Terminal.Resized()
		defer stop()
		k.resized = ch
	}
	go pumpStdin(a.Stdin, k.in)

	end, err := k.loop(ctx)
	restore()
	if errors.Is(err, errConsoleAbort) || (err != nil && ctx.Err() != nil) {
		err = exitError{code: 130} // Ctrl-C while not connected, or interrupted (stdin isn't a terminal)
	}
	if err != nil {
		// Best effort: don't leave a shell running for its idle timeout.
		cctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
		_ = c.CloseConsole(cctx, sess.SessionID)
		cancel()
		return err
	}
	if end.Reason != "" && end.Reason != "exited" {
		fmt.Fprintf(a.Stderr, "\r\nThe session ended (%s).\n", end.Reason)
	}
	code := 0
	if end.Code != nil {
		code = *end.Code
	}
	if code != 0 {
		return exitError{code: code}
	}
	return nil
}

// consoleDial bounds one WebSocket handshake.
const consoleDial = 10 * time.Second

// maxPendingInput is how much typing waits for a connection (more is dropped).
const maxPendingInput = 64 << 10

// errConsoleAbort: Ctrl-C or Ctrl-\ while the console wasn't connected.
var errConsoleAbort = errors.New("aborted")

// console is one `falak console` run: its keystrokes, window changes and connection attempts.
type console struct {
	a       *App
	c       api.API
	sess    api.ConsoleSession
	in      chan []byte // stdin; nil once it ended
	resized <-chan struct{}
	pending [][]byte // typed while not connected, sent on the next connection
	pendN   int
}

// idle runs fn (a dial, a pause, a ticket request) while still reading stdin: Ctrl-C or Ctrl-\ aborts (raw mode
// sends them as bytes, and nothing remote would get them now), other keystrokes wait for the connection.
func (k *console) idle(ctx context.Context, fn func(ctx context.Context) error) error {
	fctx, cancel := context.WithCancel(ctx)
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- fn(fctx) }()
	for {
		select {
		case err := <-done:
			return err
		case b, ok := <-k.in:
			if !ok {
				k.in = nil
				continue
			}
			if bytes.ContainsAny(b, "\x03\x1c") {
				return errConsoleAbort
			}
			if k.pendN+len(b) <= maxPendingInput {
				k.pending = append(k.pending, b)
				k.pendN += len(b)
			}
		}
	}
}

// loop connects, and reconnects with a new ticket up to consoleRetries times in a row, until the exit frame.
func (k *console) loop(ctx context.Context) (relay.Frame, error) {
	url := k.sess.URL
	failures := 0
	for attempt := 0; ; attempt++ {
		var conn *websocket.Conn
		err := k.idle(ctx, func(ctx context.Context) error {
			dctx, cancel := context.WithTimeout(ctx, consoleDial)
			defer cancel()
			c, _, err := websocket.Dial(dctx, url, &websocket.DialOptions{Subprotocols: []string{relay.Subprotocol}})
			if err != nil {
				return err
			}
			if ctx.Err() != nil { // aborted meanwhile
				c.CloseNow()
				return ctx.Err()
			}
			conn = c
			return nil
		})
		if errors.Is(err, errConsoleAbort) {
			return relay.Frame{}, err
		}
		attached := false
		if err == nil {
			var end relay.Frame
			end, attached, err = k.serve(ctx, conn, attempt > 0)
			if err == nil {
				return end, nil
			}
		}
		var fatal consoleFatal
		if errors.As(err, &fatal) || ctx.Err() != nil {
			return relay.Frame{}, err
		}
		if attached {
			failures = 0
		}
		failures++
		if failures > consoleRetries {
			return relay.Frame{}, fmt.Errorf("lost the connection to the console: %w", err)
		}
		pause := time.Duration(failures) * consoleBackoff
		if err := k.idle(ctx, func(ctx context.Context) error {
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(pause):
				return nil
			}
		}); err != nil {
			return relay.Frame{}, err
		}
		var t api.ConsoleTicket
		terr := k.idle(ctx, func(ctx context.Context) error {
			var err error
			t, err = k.c.ConsoleTicket(ctx, k.sess.SessionID)
			return err
		})
		switch {
		case errors.Is(terr, errConsoleAbort):
			return relay.Frame{}, terr
		case api.IsNotFound(terr):
			return relay.Frame{}, errors.New("the session was closed")
		case terr == nil:
			url = t.URL
		}
	}
}

// consoleFatal ends the console without reconnecting (the relay said why).
type consoleFatal struct{ msg string }

func (e consoleFatal) Error() string { return e.msg }

// serve runs one WebSocket connection. attached: the relay said ready at least once. On a reconnect the relay's
// replay (the binary frames before its first status frame) is dropped: the terminal already shows it.
func (k *console) serve(ctx context.Context, conn *websocket.Conn, reconnect bool) (end relay.Frame, attached bool, err error) {
	defer conn.CloseNow()
	conn.SetReadLimit(relay.MaxMessage)
	cctx, cancel := context.WithCancel(ctx)
	defer cancel()
	type msg struct {
		typ  websocket.MessageType
		data []byte
		err  error
	}
	frames := make(chan msg, 16)
	go func() {
		for {
			typ, b, err := conn.Read(cctx)
			select {
			case frames <- msg{typ, b, err}:
			case <-cctx.Done():
				return
			}
			if err != nil {
				return
			}
		}
	}()
	write := func(typ websocket.MessageType, b []byte) error {
		wctx, wcancel := context.WithTimeout(cctx, 10*time.Second)
		defer wcancel()
		return conn.Write(wctx, typ, b)
	}
	sendSize := func() error {
		if k.a.Terminal == nil {
			return nil
		}
		cols, rows, err := k.a.Terminal.Size()
		if err != nil || !relay.ValidSize(cols, rows) {
			return nil
		}
		return write(websocket.MessageText, relay.ResizeFrame(cols, rows))
	}
	for len(k.pending) > 0 {
		if err := write(websocket.MessageBinary, k.pending[0]); err != nil {
			return end, false, err
		}
		k.pendN -= len(k.pending[0])
		k.pending = k.pending[1:]
	}
	status := false // a ready or waiting frame arrived on this connection
	for {
		select {
		case <-ctx.Done():
			return end, attached, ctx.Err()
		case b, ok := <-k.in:
			if !ok {
				k.in = nil // stdin ended (piped input): keep showing output until the shell exits
				continue
			}
			if err := write(websocket.MessageBinary, b); err != nil {
				return end, attached, err
			}
		case <-k.resized:
			if err := sendSize(); err != nil {
				return end, attached, err
			}
		case m := <-frames:
			if m.err != nil {
				return end, attached, m.err
			}
			if m.typ == websocket.MessageBinary {
				if !reconnect || status {
					_, _ = k.a.Stdout.Write(m.data)
				}
				continue
			}
			f, ok := relay.ParseFrame(m.data)
			if !ok {
				continue
			}
			switch f.T {
			case relay.TWaiting:
				status = true
			case relay.TReady:
				status, attached = true, true
				if err := sendSize(); err != nil {
					return end, attached, err
				}
			case relay.TExit:
				_ = conn.Close(websocket.StatusNormalClosure, "")
				return f, attached, nil
			case relay.TError:
				return end, attached, consoleFatal{f.Message}
			}
		}
	}
}

// pumpStdin forwards stdin in chunks; the channel closes at EOF.
func pumpStdin(r io.Reader, out chan<- []byte) {
	defer close(out)
	if r == nil {
		return
	}
	buf := make([]byte, 4096)
	for {
		n, err := r.Read(buf)
		if n > 0 {
			out <- append([]byte(nil), buf[:n]...)
		}
		if err != nil {
			return
		}
	}
}
