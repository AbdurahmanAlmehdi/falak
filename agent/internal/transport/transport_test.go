package transport

import (
	"bufio"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// fakePlane emulates the Fleet endpoints.
type fakePlane struct {
	mu         sync.Mutex
	queue      []commands.Envelope
	events     map[string]map[int64]commands.Event // dedupe on (command_id, seq)
	posts      int
	failPosts  int32 // first N event posts fail with 503
	heartbeats []map[string]any
	notify     chan struct{}
}

func newFakePlane() *fakePlane {
	return &fakePlane{events: map[string]map[int64]commands.Event{}, notify: make(chan struct{}, 16)}
}

func (f *fakePlane) enqueue(e commands.Envelope) {
	f.mu.Lock()
	f.queue = append(f.queue, e)
	f.mu.Unlock()
	f.notify <- struct{}{}
}

func (f *fakePlane) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	switch {
	case r.Method == http.MethodGet && r.URL.Path == "/agent/v1/commands":
		if r.URL.Query().Get("wait") == "" {
			http.Error(w, "wait required", 400)
			return
		}
		deadline := time.After(300 * time.Millisecond)
		for {
			f.mu.Lock()
			if len(f.queue) > 0 {
				q := f.queue
				f.queue = nil
				f.mu.Unlock()
				_ = json.NewEncoder(w).Encode(map[string]any{"commands": q})
				return
			}
			f.mu.Unlock()
			select {
			case <-f.notify:
			case <-deadline:
				_ = json.NewEncoder(w).Encode(map[string]any{"commands": []any{}})
				return
			case <-r.Context().Done():
				return
			}
		}
	case r.Method == http.MethodPost && strings.HasPrefix(r.URL.Path, "/agent/v1/commands/") && strings.HasSuffix(r.URL.Path, "/events"):
		if r.Header.Get("Content-Type") != "application/x-ndjson" {
			http.Error(w, "ndjson required", 415)
			return
		}
		if atomic.AddInt32(&f.failPosts, -1) >= 0 {
			http.Error(w, "try later", 503)
			return
		}
		id := strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/agent/v1/commands/"), "/events")
		sc := bufio.NewScanner(r.Body)
		sc.Buffer(make([]byte, 1<<20), 1<<20)
		f.mu.Lock()
		defer f.mu.Unlock()
		f.posts++
		for sc.Scan() {
			var ev commands.Event
			if err := json.Unmarshal(sc.Bytes(), &ev); err != nil {
				http.Error(w, err.Error(), 400)
				return
			}
			if ev.CommandID != id {
				http.Error(w, "command id mismatch", 400)
				return
			}
			if f.events[id] == nil {
				f.events[id] = map[int64]commands.Event{}
			}
			f.events[id][ev.Seq] = ev
		}
		w.WriteHeader(204)
	case r.Method == http.MethodPost && r.URL.Path == "/agent/v1/heartbeat":
		var hb map[string]any
		_ = json.NewDecoder(r.Body).Decode(&hb)
		f.mu.Lock()
		f.heartbeats = append(f.heartbeats, hb)
		f.mu.Unlock()
		w.WriteHeader(204)
	default:
		http.NotFound(w, r)
	}
}

func (f *fakePlane) finished(id string) (commands.Event, bool, []int64) {
	f.mu.Lock()
	defer f.mu.Unlock()
	var seqs []int64
	var fin commands.Event
	ok := false
	for s, e := range f.events[id] {
		seqs = append(seqs, s)
		if e.Kind == commands.KindFinished {
			fin, ok = e, true
		}
	}
	return fin, ok, seqs
}

const cmdA = "01J9Z8Y7X6W5V4T3S2R1Q0P9NA"

func TestLongPollDispatchAndEventDelivery(t *testing.T) {
	plane := newFakePlane()
	plane.failPosts = 2 // transient failures must be retried
	srv := httptest.NewServer(plane)
	defer srv.Close()

	var runs int32
	reg := commands.NewRegistry()
	reg.Register("system.exec", commands.Func(func(ctx context.Context, env commands.Envelope, s commands.Stream) (any, error) {
		atomic.AddInt32(&runs, 1)
		for i := 0; i < 3; i++ {
			fmt.Fprintf(s.Stdout(), "line %d\n", i)
		}
		s.Progress(0.5)
		time.Sleep(50 * time.Millisecond)
		return map[string]int{"exit_code": 0}, nil
	}))
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	client := NewWithHTTPClient(srv.URL+"/agent/v1", srv.Client())
	ob := NewOutbox(client, nil)
	ob.FlushInterval = 20 * time.Millisecond
	go ob.Run(ctx)
	d := commands.NewDispatcher(ctx, reg, ob, nil)
	p := &Poller{Client: client, Submit: d.Submit, Wait: 1}
	go p.Run(ctx)

	env := commands.Envelope{ID: cmdA, Type: "system.exec", TimeoutS: 10, IdempotencyKey: "k1", Payload: json.RawMessage(`{"script":"true"}`)}
	plane.enqueue(env)
	plane.enqueue(env) // redelivery while running / after completion must not re-execute

	deadline := time.Now().Add(5 * time.Second)
	for {
		if fin, ok, _ := plane.finished(cmdA); ok {
			if fin.ExitCode == nil || *fin.ExitCode != 0 {
				t.Fatalf("finished %+v", fin)
			}
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("finished event never delivered")
		}
		time.Sleep(20 * time.Millisecond)
	}
	d.Wait()
	time.Sleep(100 * time.Millisecond) // allow re-emitted duplicate events to flush
	if n := atomic.LoadInt32(&runs); n != 1 {
		t.Fatalf("executor ran %d times, want 1", n)
	}
	_, _, seqs := plane.finished(cmdA)
	max := int64(-1)
	for _, s := range seqs {
		if s > max {
			max = s
		}
	}
	if int64(len(seqs)) != max+1 {
		t.Fatalf("seq numbers not contiguous from 0: %v", seqs)
	}
	plane.mu.Lock()
	evs := plane.events[cmdA]
	plane.mu.Unlock()
	if evs[0].Kind != commands.KindStarted {
		t.Fatalf("seq 0 = %s, want started", evs[0].Kind)
	}
	var out string
	for i := int64(0); i <= max; i++ {
		if evs[i].Kind == commands.KindOutput {
			out += evs[i].Data
		}
	}
	if out != "line 0\nline 1\nline 2\n" {
		t.Fatalf("output %q", out)
	}
	if ob.Pending() != 0 {
		t.Fatalf("outbox still has %d events", ob.Pending())
	}
}

type recordingPoster struct {
	mu    sync.Mutex
	fail  bool
	code  int
	got   []commands.Event
	calls int
}

func (r *recordingPoster) PostEvents(ctx context.Context, id string, evs []commands.Event) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.calls++
	if r.fail {
		return &StatusError{Code: r.code}
	}
	r.got = append(r.got, evs...)
	return nil
}

func TestOutboxRetriesAndDrops(t *testing.T) {
	p := &recordingPoster{fail: true, code: 503}
	ob := NewOutbox(p, nil)
	ob.MaxBatch = 2
	for i := 0; i < 5; i++ {
		ob.Emit(commands.Event{CommandID: "c", Seq: int64(i), Kind: commands.KindOutput})
	}
	if err := ob.Flush(context.Background()); err == nil {
		t.Fatal("expected error")
	}
	if ob.Pending() != 5 {
		t.Fatalf("events lost on retryable failure: %d", ob.Pending())
	}
	p.fail = false
	if err := ob.Flush(context.Background()); err != nil {
		t.Fatal(err)
	}
	if len(p.got) != 5 || p.got[4].Seq != 4 {
		t.Fatalf("got %+v", p.got)
	}
	// Non-retryable 404 drops the command's events.
	p.fail, p.code = true, 404
	ob.Emit(commands.Event{CommandID: "gone", Kind: commands.KindFinished})
	_ = ob.Flush(context.Background())
	if ob.Pending() != 0 {
		t.Fatal("4xx events should be dropped")
	}
	// Overflow drops output but keeps lifecycle events.
	ob2 := NewOutbox(p, nil)
	ob2.MaxPending = 10
	ob2.Emit(commands.Event{CommandID: "x", Seq: 0, Kind: commands.KindStarted})
	for i := 1; i < 30; i++ {
		ob2.Emit(commands.Event{CommandID: "x", Seq: int64(i), Kind: commands.KindOutput})
	}
	ob2.Emit(commands.Event{CommandID: "x", Seq: 30, Kind: commands.KindFinished})
	ob2.mu.Lock()
	q := ob2.queues["x"]
	ob2.mu.Unlock()
	if q[0].Kind != commands.KindStarted || q[len(q)-1].Kind != commands.KindFinished || len(q) > 12 {
		t.Fatalf("overflow handling wrong: len=%d", len(q))
	}
}

func TestHeartbeatSendsFactsOnlyWhenChanged(t *testing.T) {
	plane := newFakePlane()
	srv := httptest.NewServer(plane)
	defer srv.Close()
	facts := map[string]any{"hostname": "a"}
	h := &Heartbeater{
		Client:     NewWithHTTPClient(srv.URL+"/agent/v1", srv.Client()),
		Summary:    func() Heartbeat { return Heartbeat{UptimeS: 10, Load: [3]float64{0.1, 0.2, 0.3}} },
		Facts:      func(context.Context) (any, error) { return facts, nil },
		Running:    func() []string { return []string{cmdA} },
		FactsEvery: time.Nanosecond,
	}
	h.Beat(context.Background())
	h.Beat(context.Background())
	facts = map[string]any{"hostname": "b"}
	h.Beat(context.Background())
	plane.mu.Lock()
	defer plane.mu.Unlock()
	if len(plane.heartbeats) != 3 {
		t.Fatalf("got %d heartbeats", len(plane.heartbeats))
	}
	has := func(i int) bool { _, ok := plane.heartbeats[i]["facts"]; return ok }
	if !has(0) || has(1) || !has(2) {
		t.Fatalf("facts presence wrong: %v %v %v", has(0), has(1), has(2))
	}
	hb := plane.heartbeats[0]
	for _, k := range []string{"at", "uptime_s", "load", "memory_used_bytes", "disk_used_bytes", "running_commands"} {
		if _, ok := hb[k]; !ok {
			t.Errorf("heartbeat missing %s", k)
		}
	}
}

// Service events are dropped only after a heartbeat carrying them was delivered.
func TestHeartbeatServiceEventsDroppedOnlyWhenDelivered(t *testing.T) {
	plane := newFakePlane()
	srv := httptest.NewServer(plane)
	pending := []string{"oom"}
	h := &Heartbeater{
		Client:  NewWithHTTPClient(srv.URL+"/agent/v1", srv.Client()),
		Summary: func() Heartbeat { return Heartbeat{UptimeS: 10} },
		ServiceEvents: func() (any, func()) {
			if len(pending) == 0 {
				return nil, nil
			}
			return append([]string(nil), pending...), func() { pending = nil }
		},
	}
	h.Beat(context.Background())
	h.Beat(context.Background())
	plane.mu.Lock()
	_, first := plane.heartbeats[0]["service_events"]
	_, second := plane.heartbeats[1]["service_events"]
	plane.mu.Unlock()
	if !first || second || len(pending) != 0 {
		t.Fatalf("first=%v second=%v pending=%v", first, second, pending)
	}
	pending = []string{"restart"}
	srv.Close()
	h.Beat(context.Background())
	if len(pending) != 1 {
		t.Fatal("events dropped although the heartbeat failed")
	}
}

// --- mTLS ---

func mkCert(t *testing.T, tpl *x509.Certificate, parent *x509.Certificate, parentKey *ecdsa.PrivateKey) (*x509.Certificate, *ecdsa.PrivateKey) {
	t.Helper()
	k, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if parent == nil {
		parent, parentKey = tpl, k
	}
	der, err := x509.CreateCertificate(rand.Reader, tpl, parent, &k.PublicKey, parentKey)
	if err != nil {
		t.Fatal(err)
	}
	c, _ := x509.ParseCertificate(der)
	return c, k
}

func TestMutualTLSPinnedToCA(t *testing.T) {
	now := time.Now()
	caTpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Falak CA"}, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign}
	ca, caKey := mkCert(t, caTpl, nil, nil)
	srvCert, srvKey := mkCert(t, &x509.Certificate{SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: "agents"}, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour), ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}, ca, caKey)
	cliCert, cliKey := mkCert(t, &x509.Certificate{SerialNumber: big.NewInt(3), Subject: pkix.Name{CommonName: "agent"}, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour), ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}}, ca, caKey)
	pool := x509.NewCertPool()
	pool.AddCert(ca)

	var gotCN string
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotCN = r.TLS.PeerCertificates[0].Subject.CommonName
		io.WriteString(w, `{"commands":[]}`)
	}))
	srv.TLS = &tls.Config{ClientAuth: tls.RequireAndVerifyClientCert, ClientCAs: pool, Certificates: []tls.Certificate{{Certificate: [][]byte{srvCert.Raw}, PrivateKey: srvKey}}}
	srv.StartTLS()
	defer srv.Close()

	clientTLS := &tls.Config{RootCAs: pool, GetClientCertificate: func(*tls.CertificateRequestInfo) (*tls.Certificate, error) {
		return &tls.Certificate{Certificate: [][]byte{cliCert.Raw}, PrivateKey: cliKey}, nil
	}}
	c := New(srv.URL+"/agent/v1", clientTLS)
	if _, err := c.Poll(context.Background(), 0); err != nil {
		t.Fatal(err)
	}
	if gotCN != "agent" {
		t.Fatalf("server saw client CN %q", gotCN)
	}
	// A client pinned to another CA must refuse the server.
	other, _ := mkCert(t, &x509.Certificate{SerialNumber: big.NewInt(9), Subject: pkix.Name{CommonName: "Other"}, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign}, nil, nil)
	op := x509.NewCertPool()
	op.AddCert(other)
	bad := New(srv.URL+"/agent/v1", &tls.Config{RootCAs: op, GetClientCertificate: clientTLS.GetClientCertificate})
	if _, err := bad.Poll(context.Background(), 0); err == nil {
		t.Fatal("expected TLS verification failure with a foreign CA pin")
	}
}

func TestRequestPostsJSONAndDecodesTheReply(t *testing.T) {
	var path, ctype string
	var got map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		path, ctype = r.URL.Path, r.Header.Get("Content-Type")
		got = nil
		_ = json.NewDecoder(r.Body).Decode(&got)
		if got["instance"] == "bad" {
			w.WriteHeader(http.StatusConflict)
			io.WriteString(w, `{"message":"no","error":"pitr_disabled"}`)
			return
		}
		io.WriteString(w, `{"segments":[{"name":"a"}]}`)
	}))
	defer srv.Close()
	c := NewWithHTTPClient(srv.URL+"/agent/v1", srv.Client())
	var out struct {
		Segments []struct {
			Name string `json:"name"`
		} `json:"segments"`
	}
	if err := c.Request(context.Background(), "pitr.upload_urls", map[string]string{"instance": "x"}, &out); err != nil {
		t.Fatal(err)
	}
	if path != "/agent/v1/requests/pitr.upload_urls" || ctype != "application/json" || got["instance"] != "x" || len(out.Segments) != 1 || out.Segments[0].Name != "a" {
		t.Fatalf("path %q ctype %q body %v reply %+v", path, ctype, got, out)
	}
	err := c.Request(context.Background(), "pitr.upload_urls", map[string]string{"instance": "bad"}, &out)
	var se *StatusError
	if !errors.As(err, &se) || se.Code != http.StatusConflict || se.Reason != "pitr_disabled" || Retryable(err) {
		t.Fatalf("err = %v", err)
	}
}

func TestRetryAfterParsed(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Retry-After", "30")
		http.Error(w, `{"error":"too_many_requests"}`, http.StatusTooManyRequests)
	}))
	defer srv.Close()
	c := NewWithHTTPClient(srv.URL+"/agent/v1", srv.Client())
	err := c.Heartbeat(context.Background(), map[string]any{})
	if !Retryable(err) {
		t.Fatalf("429 must be retryable: %v", err)
	}
	if d := RetryAfterDuration(err); d != 30*time.Second {
		t.Fatalf("RetryAfterDuration = %v, want 30s", d)
	}
	// No Retry-After header → duration is 0 (caller uses its own backoff).
	srv2 := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "nope", http.StatusTooManyRequests)
	}))
	defer srv2.Close()
	c2 := NewWithHTTPClient(srv2.URL+"/agent/v1", srv2.Client())
	err2 := c2.Heartbeat(context.Background(), map[string]any{})
	if d := RetryAfterDuration(err2); d != 0 {
		t.Fatalf("no Retry-After header → 0, got %v", d)
	}
}

// A dead HTTP/2 connection (the control plane moved to a new IP) must be detected by pings, not reused forever.
func TestNewPingsIdleHTTP2Connections(t *testing.T) {
	tr := New("https://agents.falak.test/agent/v1", &tls.Config{}).hc.Transport.(*http.Transport)
	if tr.HTTP2 == nil || tr.HTTP2.SendPingTimeout <= 0 || tr.HTTP2.PingTimeout <= 0 {
		t.Fatalf("HTTP/2 health checks are off: %+v", tr.HTTP2)
	}
}
