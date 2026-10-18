package builder

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// stuckRunner blocks every command until its context ends (a build that runs until it is stopped).
type stuckRunner struct{ started chan struct{} }

func (r stuckRunner) Run(ctx context.Context, _ runner.Cmd) (runner.Result, error) {
	select {
	case r.started <- struct{}{}:
	default:
	}
	<-ctx.Done()
	return runner.Result{ExitCode: -1}, ctx.Err()
}

// syncBuffer is a log destination read while the server writes to it.
type syncBuffer struct {
	mu sync.Mutex
	b  bytes.Buffer
}

func (s *syncBuffer) Write(p []byte) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.b.Write(p)
}
func (s *syncBuffer) String() string { s.mu.Lock(); defer s.mu.Unlock(); return s.b.String() }

func shortDiskPolls(t *testing.T) {
	oldPause, oldPoll := diskPause, diskJobPoll
	diskPause, diskJobPoll = 5*time.Millisecond, 5*time.Millisecond
	t.Cleanup(func() { diskPause, diskJobPoll = oldPause, oldPoll })
}

// buildServer serves one native job b1 and collects its events.
func buildServer(t *testing.T) (*httptest.Server, *atomic.Int32, func() []byte) {
	var mu sync.Mutex
	var events bytes.Buffer
	polls := &atomic.Int32{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case PathNextBuild:
			polls.Add(1)
			json.NewEncoder(w).Encode(map[string]any{"id": "b1", "mode": "native", "repo": map[string]any{"url": "https://x/y.git"}})
		case EventsPath("b1"):
			b, _ := io.ReadAll(r.Body)
			mu.Lock()
			events.Write(b)
			mu.Unlock()
		default:
			w.WriteHeader(http.StatusNoContent)
		}
	}))
	t.Cleanup(srv.Close)
	return srv, polls, func() []byte { mu.Lock(); defer mu.Unlock(); return append([]byte(nil), events.Bytes()...) }
}

// No job is claimed while free space is under max(FALAK_BUILD_MIN_FREE_GB, 10 %); the state change is logged once.
func TestDiskGuardPausesPolling(t *testing.T) {
	shortDiskPolls(t)
	t.Setenv("FALAK_BUILD_MIN_FREE_GB", "8")
	srv, polls, _ := buildServer(t)
	var free atomic.Uint64
	free.Store(7 << 30) // 7 GiB of 50: above 10 %, below the 8 GiB floor
	checks := &atomic.Int32{}
	var logs syncBuffer
	b := newBuilder(t, stuckRunner{started: make(chan struct{}, 1)})
	s := &Server{URL: srv.URL, Token: "t", Once: true, Builder: b, HTTP: srv.Client(), Log: slog.New(slog.NewTextHandler(&logs, nil)),
		DiskSpace: func(string) (uint64, uint64, error) { checks.Add(1); return free.Load(), 50 << 30, nil }}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- s.Run(ctx) }()
	for checks.Load() < 10 {
		time.Sleep(time.Millisecond)
	}
	if polls.Load() != 0 {
		t.Fatal("a job was claimed with too little disk space")
	}
	if n := strings.Count(logs.String(), "not taking builds"); n != 1 {
		t.Fatalf("logged %d times:\n%s", n, logs.String())
	}
	free.Store(30 << 30)
	for polls.Load() == 0 {
		time.Sleep(time.Millisecond)
	}
	cancel()
	<-done
	if !strings.Contains(logs.String(), "disk space recovered") {
		t.Fatalf("recovery not logged:\n%s", logs.String())
	}
	// 10 % of a large disk is the floor when it is above the configured one.
	s.Builder.WorkDir, s.Builder.CacheDir = t.TempDir(), t.TempDir()
	s.DiskSpace = func(string) (uint64, uint64, error) { return 9 << 30, 100 << 30, nil }
	if s.diskShort(5, true) == "" || s.diskShort(5, false) != "" {
		t.Fatal("10 % floor")
	}
}

// A running build is stopped with a clear error once free space drops under FALAK_BUILD_ABORT_FREE_GB.
func TestDiskGuardStopsTheBuild(t *testing.T) {
	shortDiskPolls(t)
	srv, _, events := buildServer(t)
	var free atomic.Uint64
	free.Store(30 << 30)
	r := stuckRunner{started: make(chan struct{}, 1)}
	s := &Server{URL: srv.URL, Token: "t", Once: true, Builder: newBuilder(t, r), HTTP: srv.Client(),
		DiskSpace: func(string) (uint64, uint64, error) { return free.Load(), 50 << 30, nil }}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- s.Run(ctx) }()
	select {
	case <-r.started:
	case <-ctx.Done():
		t.Fatal("the build never started")
	}
	free.Store(1 << 30)
	if err := <-done; err != nil || ctx.Err() != nil {
		t.Fatalf("run: %v (ctx %v)", err, ctx.Err())
	}
	evs := validateNDJSON(t, events())
	last := evs[len(evs)-1]
	if *last.ExitCode != 1 || last.Error != "Build stopped: the disk has less than 2 GB free" {
		t.Fatalf("finished %+v", last)
	}
}
