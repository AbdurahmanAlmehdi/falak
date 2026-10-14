package pty

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/coder/websocket"
	cpty "github.com/creack/pty"

	"github.com/OthmanHaba/falak/agent/internal/relay"
)

// Relay link timings.
const (
	relayBackoffMin = 500 * time.Millisecond
	relayBackoffMax = 5 * time.Second
	relayWrite      = 10 * time.Second
	relayPing       = 25 * time.Second
	relayFinish     = 3 * time.Second // the end of a session waits this long to deliver its exit frame
)

// relayLink pipes one session through the terminal relay: PTY output out as binary frames, keystrokes and resizes
// in. It redials while the session lives and never fails it; the session's output events go on regardless.
type relayLink struct {
	log  *slog.Logger
	s    *session
	dial func(ctx context.Context) (*websocket.Conn, error)

	out      chan []byte   // output waiting for the relay
	overflow chan struct{} // the relay fell QueueSize chunks behind: drop the connection, redial
	ending   chan struct{} // closed by finish; exit holds the frame
	exit     []byte
	done     chan struct{}
	ctx      context.Context
	cancel   context.CancelFunc
}

// relayURL turns the agent API base into the relay's WebSocket URL for a session.
func relayURL(base, sid string) string {
	u := strings.TrimRight(base, "/") + "/relay/sessions/" + sid
	switch {
	case strings.HasPrefix(u, "https://"):
		return "wss://" + strings.TrimPrefix(u, "https://")
	case strings.HasPrefix(u, "http://"):
		return "ws://" + strings.TrimPrefix(u, "http://")
	}
	return u
}

// startRelay starts the session's link; nil (a no-op link) without a token or a relay base.
func (m *Manager) startRelay(s *session, token string) *relayLink {
	if token == "" {
		return nil
	}
	if m.opts.RelayBase == "" {
		m.log.Warn("relay token ignored: no relay configured", "session", s.id)
		return nil
	}
	u := relayURL(m.opts.RelayBase, s.id)
	hc := m.opts.RelayHTTP
	if hc == nil {
		hc = http.DefaultClient
	}
	hdr := http.Header{"Authorization": {"Bearer " + token}}
	ctx, cancel := context.WithCancel(context.Background())
	l := &relayLink{
		log: m.log.With("session", s.id), s: s,
		dial: func(ctx context.Context) (*websocket.Conn, error) {
			c, resp, err := websocket.Dial(ctx, u, &websocket.DialOptions{HTTPClient: hc, HTTPHeader: hdr, Subprotocols: []string{relay.Subprotocol}})
			if err != nil && resp != nil {
				return nil, errors.New(resp.Status)
			}
			return c, err
		},
		out: make(chan []byte, relay.QueueSize), overflow: make(chan struct{}, 1), ending: make(chan struct{}), done: make(chan struct{}),
		ctx: ctx, cancel: cancel,
	}
	go l.run()
	return l
}

// send queues a copy of b (the caller reuses its buffer) without ever blocking the PTY reader. When the relay has
// fallen QueueSize chunks behind, the queue is dropped and the connection redialled: the gap stays a gap (the
// output events still have it), the relay's replay covers what it got.
func (l *relayLink) send(b []byte) {
	if l == nil {
		return
	}
	select {
	case l.out <- append([]byte(nil), b...):
	default:
		l.discard()
		select {
		case l.overflow <- struct{}{}:
		default:
		}
	}
}

// discard empties the output queue.
func (l *relayLink) discard() {
	for {
		select {
		case <-l.out:
		default:
			return
		}
	}
}

// finish delivers the exit frame after the queued output and closes normally (bounded by relayFinish).
func (l *relayLink) finish(reason string, code *int) {
	if l == nil {
		return
	}
	l.exit = relay.ExitFrame(reason, code)
	close(l.ending)
	select {
	case <-l.done:
	case <-time.After(relayFinish):
	}
	l.cancel()
	<-l.done
}

func (l *relayLink) run() {
	defer close(l.done)
	backoff := relayBackoffMin
	first := true
	for {
		ending := false
		select {
		case <-l.ending:
			ending = true
		default:
		}
		conn, err := l.dial(l.ctx)
		if err == nil {
			backoff = relayBackoffMin
			// A new connection starts clean: no overflow from the last one, and no output queued while
			// disconnected (stale; it would also arrive after what the relay's clients saw meanwhile). The first one
			// gets what was queued while it dialled (the shell's first prompt).
			select {
			case <-l.overflow:
			default:
			}
			if !first {
				l.discard()
			}
			first = false
			if l.serve(conn) {
				return
			}
			continue
		}
		if ending || l.ctx.Err() != nil {
			return // the session is over and the relay can't be reached: its clients see the hub end
		}
		l.log.Debug("relay dial failed", "err", err, "retry_in", backoff)
		select {
		case <-l.ctx.Done():
			return
		case <-l.ending: // one last try to deliver the exit
		case <-time.After(backoff):
		}
		backoff = min(backoff*2, relayBackoffMax)
	}
}

// serve runs one connection; true once the exit frame was delivered (or the session is over).
func (l *relayLink) serve(conn *websocket.Conn) bool {
	defer conn.CloseNow()
	conn.SetReadLimit(relay.MaxMessage)
	ctx, cancel := context.WithCancel(l.ctx)
	defer cancel()
	go func() {
		defer cancel()
		for {
			typ, b, err := conn.Read(ctx)
			if err != nil {
				return
			}
			l.input(typ, b)
		}
	}()
	go func() {
		t := time.NewTicker(relayPing)
		defer t.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-t.C:
				pctx, pcancel := context.WithTimeout(ctx, relayWrite)
				err := conn.Ping(pctx)
				pcancel()
				if err != nil {
					cancel()
					return
				}
			}
		}
	}()
	write := func(typ websocket.MessageType, b []byte) bool {
		wctx, wcancel := context.WithTimeout(ctx, relayWrite)
		defer wcancel()
		return conn.Write(wctx, typ, b) == nil
	}
	for {
		select {
		case <-ctx.Done():
			return l.ctx.Err() != nil
		case <-l.overflow:
			l.log.Info("relay too slow, reconnecting")
			return false
		case b := <-l.out:
			if !write(websocket.MessageBinary, b) {
				return false
			}
		case <-l.ending:
		drain:
			for {
				select {
				case b := <-l.out:
					if !write(websocket.MessageBinary, b) {
						return false
					}
				default:
					break drain
				}
			}
			if !write(websocket.MessageText, l.exit) {
				return false
			}
			_ = conn.Close(websocket.StatusNormalClosure, "session ended")
			return true
		}
	}
}

// input applies a frame from the relay: keystrokes are written to the PTY, resizes resize it.
func (l *relayLink) input(typ websocket.MessageType, b []byte) {
	if typ == websocket.MessageBinary {
		l.s.touch()
		_, _ = l.s.f.Write(b)
		return
	}
	if f, ok := relay.ParseFrame(b); ok && f.T == relay.TResize && relay.ValidSize(f.Cols, f.Rows) {
		l.s.touch()
		_ = cpty.Setsize(l.s.f, &cpty.Winsize{Cols: uint16(f.Cols), Rows: uint16(f.Rows)})
	}
}
