package relay

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"regexp"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/coder/websocket"
)

// Limits and timings (docs/plans/CONSOLE.md, "Relay HTTP API" and "Session hub").
const (
	MaxMessage      = 1 << 20  // any message the relay reads
	MaxClientInput  = 64 << 10 // one message from a browser or CLI
	QueueSize       = 256      // frames waiting for one connection; a peer that falls this far behind is dropped
	QueueBytes      = 1 << 20  // … or this many bytes
	PendingInput    = 64 << 10 // keystrokes kept for an agent that isn't attached (more are dropped)
	DefaultPing     = 25 * time.Second
	DefaultWait     = 30 * time.Second // a client waits this long for the agent
	DefaultGrace    = 30 * time.Second // a disconnected agent has this long to come back
	DefaultIdle     = 60 * time.Second // a hub with nothing attached is dropped after this
	writeTimeout    = 10 * time.Second
	pingTimeout     = 15 * time.Second
	defaultSweep    = 10 * time.Second
	fingerprintHdr  = "X-Falak-Client-Cert-Fingerprint"
	msgNoAgent      = "The server did not attach to this session."
	msgAgentLost    = "The server lost its connection to this session."
	msgShuttingDown = "The terminal relay is restarting."
	msgKicked       = "Your access to this session ended."
)

var sidRe = regexp.MustCompile(`^[A-Za-z0-9_-]{8,64}$`)

// Options configures a Server. Zero durations take the defaults above.
type Options struct {
	// Key is DeriveKey(APP_KEY).
	Key    []byte
	Logger *slog.Logger
	Now    func() time.Time
	// OriginPatterns restricts browsers' Origin (websocket.AcceptOptions); empty = any origin (see clientAccept).
	OriginPatterns []string

	PingEvery, ClientWait, AgentGrace, IdleAfter, SweepEvery time.Duration
	// ReplaySize overrides ReplaySize (tests).
	ReplaySize int
}

// Server is the relay: one hub per session, created by whichever side connects first.
type Server struct {
	o   Options
	log *slog.Logger
	v   *Verifier

	ctx    context.Context // ends every connection on Close
	cancel context.CancelFunc

	mu   sync.Mutex
	hubs map[string]*hub
}

// New returns a Server; call Run for the sweeper and Close on shutdown.
func New(o Options) *Server {
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	def := func(d *time.Duration, v time.Duration) {
		if *d <= 0 {
			*d = v
		}
	}
	def(&o.PingEvery, DefaultPing)
	def(&o.ClientWait, DefaultWait)
	def(&o.AgentGrace, DefaultGrace)
	def(&o.IdleAfter, DefaultIdle)
	def(&o.SweepEvery, defaultSweep)
	if o.ReplaySize <= 0 {
		o.ReplaySize = ReplaySize
	}
	ctx, cancel := context.WithCancel(context.Background())
	return &Server{o: o, log: o.Logger, v: NewVerifier(o.Key, o.Now), ctx: ctx, cancel: cancel, hubs: map[string]*hub{}}
}

// Handler serves the agent and client WebSocket endpoints and /healthz.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /agent/v1/relay/sessions/{sid}", s.serveAgent)
	mux.HandleFunc("GET /terminal/v1/sessions/{sid}", s.serveClient)
	// Control plane → relay, on the compose network only: the edge routes /agent/v1/relay/* and /terminal/v1/* to the
	// relay, never /internal/*.
	mux.HandleFunc("POST /internal/v1/sessions/{sid}/kick", s.serveKick)
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/plain")
		_, _ = w.Write([]byte("ok"))
	})
	return mux
}

// Run sweeps idle hubs and expired nonces until ctx ends.
func (s *Server) Run(ctx context.Context) {
	t := time.NewTicker(s.o.SweepEvery)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			s.sweep()
		}
	}
}

// Close tells every client the relay is going away and ends every connection (agents redial, clients reconnect).
func (s *Server) Close() {
	s.mu.Lock()
	hubs := make([]*hub, 0, len(s.hubs))
	for _, h := range s.hubs {
		hubs = append(hubs, h)
	}
	s.mu.Unlock()
	for _, h := range hubs {
		h.mu.Lock()
		for c := range h.clients {
			c.closeAfter(websocket.StatusGoingAway, msgShuttingDown)
		}
		if h.agent != nil {
			h.agent.closeAfter(websocket.StatusGoingAway, "relay shutting down")
		}
		h.mu.Unlock()
	}
	// Let the writers flush their close frames, then cut what is left.
	time.Sleep(200 * time.Millisecond)
	s.cancel()
}

func (s *Server) sweep() {
	s.v.Sweep()
	now := s.o.Now()
	s.mu.Lock()
	defer s.mu.Unlock()
	for sid, h := range s.hubs {
		h.mu.Lock()
		if h.agent == nil && len(h.clients) == 0 && now.Sub(h.idleSince) >= s.o.IdleAfter {
			h.ended = true
			delete(s.hubs, sid)
			s.log.Debug("hub swept", "session", sid)
		}
		h.mu.Unlock()
	}
}

// hub returns the session's hub, creating it.
func (s *Server) hub(sid string) *hub {
	s.mu.Lock()
	defer s.mu.Unlock()
	h, ok := s.hubs[sid]
	if !ok {
		h = &hub{s: s, sid: sid, clients: map[*peer]struct{}{}, replay: newRing(s.o.ReplaySize), idleSince: s.o.Now()}
		s.hubs[sid] = h
	}
	return h
}

func (s *Server) drop(h *hub) {
	s.mu.Lock()
	if s.hubs[h.sid] == h {
		delete(s.hubs, h.sid)
	}
	s.mu.Unlock()
}

func (s *Server) serveAgent(w http.ResponseWriter, r *http.Request) {
	sid := r.PathValue("sid")
	if !sidRe.MatchString(sid) {
		http.Error(w, "invalid session id", http.StatusNotFound)
		return
	}
	// The edge sets the fingerprint only on the mTLS agent host, after it verified the client certificate
	// (lowercase hex SHA-256 of the DER; normalised like the control plane's AuthenticateAgent).
	fp := strings.ToLower(strings.TrimSpace(r.Header.Get(fingerprintHdr)))
	if fp == "" {
		http.Error(w, "client certificate required", http.StatusForbidden)
		return
	}
	tok, ok := bearer(r)
	if !ok {
		http.Error(w, "missing bearer token", http.StatusUnauthorized)
		return
	}
	claims, err := s.v.Verify(tok, AudAgent, sid)
	if err != nil {
		s.log.Warn("agent refused", "session", sid, "err", err)
		http.Error(w, "invalid token", http.StatusUnauthorized)
		return
	}
	// A token bound to a certificate (fp) only works with that certificate.
	if claims.Fp != "" && strings.ToLower(strings.TrimSpace(claims.Fp)) != fp {
		s.log.Warn("agent refused: token bound to another certificate", "session", sid)
		http.Error(w, "token bound to another certificate", http.StatusForbidden)
		return
	}
	conn, err := websocket.Accept(w, r, &websocket.AcceptOptions{Subprotocols: []string{Subprotocol}})
	if err != nil {
		s.log.Debug("agent accept", "session", sid, "err", err)
		return
	}
	conn.SetReadLimit(MaxMessage)
	p := s.newPeer(conn, true, claims.Sub)
	defer p.stop()
	var h *hub
	for h = s.hub(sid); !h.attachAgent(p); h = s.hub(sid) {
	}
	s.log.Info("agent attached", "session", sid, "server", claims.Sub)
	defer func() {
		h.detachAgent(p)
		s.log.Info("agent detached", "session", sid, "server", claims.Sub)
	}()
	for {
		typ, data, err := conn.Read(p.ctx)
		if err != nil {
			return
		}
		if typ == websocket.MessageBinary {
			h.output(data)
			continue
		}
		if f, ok := ParseFrame(data); ok && f.T == TExit {
			h.end(ExitFrame(f.Reason, f.Code), websocket.StatusNormalClosure, "session ended")
			return
		}
	}
}

func (s *Server) serveClient(w http.ResponseWriter, r *http.Request) {
	sid := r.PathValue("sid")
	if !sidRe.MatchString(sid) {
		http.Error(w, "invalid session id", http.StatusNotFound)
		return
	}
	claims, err := s.v.Verify(r.URL.Query().Get("ticket"), AudClient, sid)
	if err != nil {
		s.log.Info("client refused", "session", sid, "err", err)
		http.Error(w, "invalid or expired ticket", http.StatusUnauthorized)
		return
	}
	conn, err := websocket.Accept(w, r, s.clientAccept())
	if err != nil {
		s.log.Debug("client accept", "session", sid, "err", err)
		return
	}
	conn.SetReadLimit(MaxClientInput)
	p := s.newPeer(conn, claims.W, claims.Sub)
	defer p.stop()
	var h *hub
	for h = s.hub(sid); !h.attachClient(p); h = s.hub(sid) {
	}
	s.log.Debug("client attached", "session", sid, "user", claims.Sub, "writer", claims.W)
	defer h.detachClient(p)
	for {
		typ, data, err := conn.Read(p.ctx)
		if err != nil {
			return
		}
		if !p.writer {
			continue // watchers' keystrokes are dropped silently
		}
		if typ == websocket.MessageBinary {
			h.input(websocket.MessageBinary, data)
			continue
		}
		if f, ok := ParseFrame(data); ok && f.T == TResize && ValidSize(f.Cols, f.Rows) {
			h.input(websocket.MessageText, ResizeFrame(f.Cols, f.Rows))
		}
	}
}

// serveKick ends clients' access to a session (their permission was revoked, or the session was shared with fewer
// people): every client whose ticket's sub isn't the token's keep gets an error and is closed; the agent stays. The
// token has aud "control" and the session's sid. An unknown session is fine (204): nothing to kick.
func (s *Server) serveKick(w http.ResponseWriter, r *http.Request) {
	sid := r.PathValue("sid")
	if !sidRe.MatchString(sid) {
		http.Error(w, "invalid session id", http.StatusNotFound)
		return
	}
	tok, ok := bearer(r)
	if !ok {
		http.Error(w, "missing bearer token", http.StatusUnauthorized)
		return
	}
	claims, err := s.v.Verify(tok, AudControl, sid)
	if err != nil {
		s.log.Warn("kick refused", "session", sid, "err", err)
		http.Error(w, "invalid token", http.StatusUnauthorized)
		return
	}
	s.mu.Lock()
	h := s.hubs[sid]
	s.mu.Unlock()
	if h != nil {
		n := h.kick(claims.Keep)
		s.log.Info("clients kicked", "session", sid, "count", n, "kept", claims.Keep)
	}
	w.WriteHeader(http.StatusNoContent)
}

// clientAccept: browsers reach the relay through the panel host (same origin). With no FALAK_RELAY_ORIGINS the
// Origin isn't checked: a client is authenticated by its single-use ticket, which only the panel hands out (an
// authenticated, CSRF-protected POST whose answer a cross-origin page can't read), so an origin check would add
// nothing, and it would break a relay URL on another host.
func (s *Server) clientAccept() *websocket.AcceptOptions {
	o := &websocket.AcceptOptions{Subprotocols: []string{Subprotocol}}
	if len(s.o.OriginPatterns) > 0 {
		o.OriginPatterns = s.o.OriginPatterns
	} else {
		o.InsecureSkipVerify = true
	}
	return o
}

func bearer(r *http.Request) (string, bool) {
	const prefix = "Bearer "
	v := r.Header.Get("Authorization")
	if len(v) <= len(prefix) || v[:len(prefix)] != prefix {
		return "", false
	}
	return v[len(prefix):], true
}

// hub is one session: at most one agent, any number of clients, and the latest output.
type hub struct {
	s   *Server
	sid string

	mu        sync.Mutex
	agent     *peer
	clients   map[*peer]struct{}
	replay    *ring
	ended     bool
	idleSince time.Time   // when the last connection left
	grace     *time.Timer // the disconnected agent's deadline
	attaches  int         // agent attachments so far: a grace timer only ends the hub for the detach it was set for
	pending   []frame     // writers' input while no agent is attached, flushed when one attaches
	pendingN  int
}

// attachAgent makes p the session's agent, replacing (and closing) an earlier one. false: the hub has ended.
func (h *hub) attachAgent(p *peer) bool {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.ended {
		return false
	}
	old := h.agent
	h.agent = p
	if h.grace != nil {
		h.grace.Stop()
		h.grace = nil
	}
	h.attaches++
	if old != nil {
		old.closeAfter(websocket.StatusNormalClosure, "replaced by a new connection")
	}
	for _, f := range h.pending {
		p.send(f.typ, f.data)
	}
	h.pending, h.pendingN = nil, 0
	for c := range h.clients {
		if !c.ready {
			c.ready, c.everReady = true, true
			h.sendClient(c, websocket.MessageText, StatusFrame(TReady))
		}
	}
	return true
}

// detachAgent: the agent's connection ended. Clients wait for it to come back for AgentGrace.
func (h *hub) detachAgent(p *peer) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.agent != p || h.ended {
		return
	}
	h.agent = nil
	for c := range h.clients {
		c.ready = false
		h.sendClient(c, websocket.MessageText, StatusFrame(TWaiting))
	}
	if len(h.clients) == 0 {
		h.idleSince = h.s.o.Now()
	}
	gen := h.attaches
	h.grace = time.AfterFunc(h.s.o.AgentGrace, func() {
		h.mu.Lock()
		// Checked under the lock that ends the hub: an agent that came back meanwhile is never cut off.
		if h.agent != nil || h.ended || h.attaches != gen {
			h.mu.Unlock()
			return
		}
		h.endLocked(ErrorFrame(msgAgentLost), websocket.StatusTryAgainLater, "agent gone")
		h.mu.Unlock()
		h.s.drop(h)
	})
}

// attachClient adds p: the replay, then ready (or waiting, with a deadline for the agent). false: the hub has ended.
func (h *hub) attachClient(p *peer) bool {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.ended {
		return false
	}
	h.clients[p] = struct{}{}
	if h.replay.n > 0 {
		p.send(websocket.MessageBinary, h.replay.bytes())
	}
	if h.agent != nil {
		p.ready, p.everReady = true, true
		p.send(websocket.MessageText, StatusFrame(TReady))
		return true
	}
	p.send(websocket.MessageText, StatusFrame(TWaiting))
	time.AfterFunc(h.s.o.ClientWait, func() {
		h.mu.Lock()
		defer h.mu.Unlock()
		if _, ok := h.clients[p]; ok && !p.everReady {
			delete(h.clients, p)
			p.send(websocket.MessageText, ErrorFrame(msgNoAgent))
			p.closeAfter(websocket.StatusTryAgainLater, "no agent")
		}
	})
	return true
}

func (h *hub) detachClient(p *peer) {
	h.mu.Lock()
	defer h.mu.Unlock()
	delete(h.clients, p)
	if len(h.clients) == 0 && h.agent == nil {
		h.idleSince = h.s.o.Now()
	}
}

// output is the agent's PTY output: kept for replay, sent to every client.
func (h *hub) output(b []byte) {
	h.mu.Lock()
	defer h.mu.Unlock()
	h.replay.write(b)
	for c := range h.clients {
		h.sendClient(c, websocket.MessageBinary, b)
	}
}

// input forwards a writer's keystrokes or resize to the agent. While none is attached, up to PendingInput bytes
// wait for it (in order); more are dropped.
func (h *hub) input(typ websocket.MessageType, b []byte) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.agent == nil {
		if h.pendingN+len(b) <= PendingInput {
			h.pending = append(h.pending, frame{typ: typ, data: b})
			h.pendingN += len(b)
		}
		return
	}
	if !h.agent.send(typ, b) {
		// Its handler detaches it; the agent redials.
		h.s.log.Warn("agent too slow, dropping its connection", "session", h.sid)
		h.agent.kill()
	}
}

// sendClient queues b for c; a client too slow to keep up is dropped (it reconnects and gets the replay).
func (h *hub) sendClient(c *peer, typ websocket.MessageType, b []byte) {
	if !c.send(typ, b) {
		h.s.log.Info("client too slow, dropping it", "session", h.sid)
		delete(h.clients, c)
		c.kill()
	}
}

// kick closes every client whose ticket's sub isn't keep (all of them when keep is empty) with msgKicked.
func (h *hub) kick(keep string) int {
	h.mu.Lock()
	defer h.mu.Unlock()
	n := 0
	for c := range h.clients {
		if keep != "" && c.sub == keep {
			continue
		}
		delete(h.clients, c)
		if c.send(websocket.MessageText, ErrorFrame(msgKicked)) {
			c.closeAfter(websocket.StatusPolicyViolation, "access ended")
		} else {
			c.kill()
		}
		n++
	}
	if len(h.clients) == 0 && h.agent == nil {
		h.idleSince = h.s.o.Now()
	}
	return n
}

// end sends last to every client, closes everything and drops the hub.
func (h *hub) end(last []byte, code websocket.StatusCode, reason string) {
	h.mu.Lock()
	h.endLocked(last, code, reason)
	h.mu.Unlock()
	h.s.drop(h)
}

// endLocked is end under h.mu; the caller drops the hub after unlocking.
func (h *hub) endLocked(last []byte, code websocket.StatusCode, reason string) {
	if h.ended {
		return
	}
	h.ended = true
	h.pending, h.pendingN = nil, 0
	if h.grace != nil {
		h.grace.Stop()
	}
	for c := range h.clients {
		if c.send(websocket.MessageText, last) {
			c.closeAfter(code, reason)
		} else {
			c.kill()
		}
	}
	clear(h.clients)
	if h.agent != nil {
		h.agent.closeAfter(websocket.StatusNormalClosure, reason)
		h.agent = nil
	}
}

// peer is one WebSocket connection with its own writer: a slow peer never blocks the others.
type peer struct {
	conn   *websocket.Conn
	writer bool   // may type (clients); the agent is always a writer
	sub    string // the token's subject (user id for clients)
	out    chan frame
	queued atomic.Int64 // bytes in out
	ctx    context.Context
	cancel context.CancelFunc
	once   sync.Once

	// hub-locked: ready = the client was told the agent is attached (everReady: at least once, which ends its wait)
	ready, everReady bool
}

type frame struct {
	typ   websocket.MessageType
	data  []byte
	close bool
	code  websocket.StatusCode
	why   string
}

func (s *Server) newPeer(conn *websocket.Conn, writer bool, sub string) *peer {
	ctx, cancel := context.WithCancel(s.ctx)
	p := &peer{conn: conn, writer: writer, sub: sub, out: make(chan frame, QueueSize), ctx: ctx, cancel: cancel}
	go p.writeLoop()
	go p.pingLoop(s.o.PingEvery)
	return p
}

// send queues a frame without blocking; false when the queue is full (QueueSize frames or QueueBytes).
func (p *peer) send(typ websocket.MessageType, b []byte) bool {
	n := int64(len(b))
	if p.queued.Add(n) > QueueBytes {
		p.queued.Add(-n)
		return false
	}
	select {
	case p.out <- frame{typ: typ, data: b}:
		return true
	default:
		p.queued.Add(-n)
		return false
	}
}

// closeAfter closes the connection normally once the frames queued before it are written.
func (p *peer) closeAfter(code websocket.StatusCode, why string) {
	select {
	case p.out <- frame{close: true, code: code, why: why}:
	default:
		p.kill()
	}
}

// kill ends the connection now.
func (p *peer) kill() {
	p.once.Do(func() {
		p.cancel()
		_ = p.conn.CloseNow()
	})
}

func (p *peer) stop() { p.kill() }

func (p *peer) writeLoop() {
	for {
		select {
		case <-p.ctx.Done():
			return
		case f := <-p.out:
			if f.close {
				_ = p.conn.Close(f.code, f.why)
				p.kill()
				return
			}
			ctx, cancel := context.WithTimeout(p.ctx, writeTimeout)
			err := p.conn.Write(ctx, f.typ, f.data)
			cancel()
			p.queued.Add(-int64(len(f.data)))
			if err != nil {
				p.kill()
				return
			}
		}
	}
}

// pingLoop keeps the connection alive through the edge and Cloudflare (both drop idle connections after ~100 s),
// and notices a peer that went away without closing.
func (p *peer) pingLoop(every time.Duration) {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		select {
		case <-p.ctx.Done():
			return
		case <-t.C:
			ctx, cancel := context.WithTimeout(p.ctx, pingTimeout)
			err := p.conn.Ping(ctx)
			cancel()
			if err != nil && !errors.Is(err, context.Canceled) {
				p.kill()
				return
			}
		}
	}
}
