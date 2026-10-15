package nettool

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"
)

// recordSignals replaces signalGroup with one that records what it sends (and still sends it) and shortens the SIGKILL
// grace; both are restored when the test ends.
func recordSignals(t *testing.T, grace time.Duration) func() []syscall.Signal {
	t.Helper()
	var mu sync.Mutex
	var sent []syscall.Signal
	origSignal, origGrace := signalGroup, captureKillGrace
	signalGroup = func(pgid int, sig syscall.Signal) error {
		mu.Lock()
		sent = append(sent, sig)
		mu.Unlock()
		return syscall.Kill(-pgid, sig)
	}
	captureKillGrace = grace
	t.Cleanup(func() { signalGroup, captureKillGrace = origSignal, origGrace })
	return func() []syscall.Signal {
		mu.Lock()
		defer mu.Unlock()
		return append([]syscall.Signal(nil), sent...)
	}
}

func TestCaptureNeverKillsAfterTcpdumpExited(t *testing.T) {
	sent := recordSignals(t, 100*time.Millisecond)
	e := newCaptureEnv(t, waitForInt, pcapBytes(2, 60))
	if _, err := e.run(t, CaptureSpec{CaptureID: testCaptureID, DurationS: 1}); err != nil {
		t.Fatal(err)
	}
	time.Sleep(300 * time.Millisecond) // past the grace: the SIGKILL fallback must have been disarmed
	if got := sent(); len(got) != 1 || got[0] != syscall.SIGINT {
		t.Fatalf("signals %v, want only SIGINT", got)
	}
}

func TestCaptureKillsATcpdumpIgnoringSIGINT(t *testing.T) {
	sent := recordSignals(t, 100*time.Millisecond)
	e := newCaptureEnv(t, "trap '' INT\ncat \"$FAKE_PCAP\"\nwhile :; do sleep 0.05; done\n", pcapBytes(2, 60))
	res, err := e.run(t, CaptureSpec{CaptureID: testCaptureID, DurationS: 1})
	if err != nil {
		t.Fatal(err)
	}
	if got := sent(); len(got) != 2 || got[1] != syscall.SIGKILL || res.(CaptureResult).Packets != 2 {
		t.Fatalf("signals %v, result %+v", got, res)
	}
}

func TestUploadCaptureShorterThanRecorded(t *testing.T) {
	data := pcapBytes(1, 10)
	path := filepath.Join(t.TempDir(), "c.pcap")
	write(t, path, string(data))
	cp := &fakeCP{}
	err := New(Options{CP: cp, ChunkBytes: 1000}).uploadCapture(context.Background(), testCaptureID, path, int64(len(data))+1000, "x")
	if err == nil || !strings.Contains(err.Error(), "shorter than recorded") || len(cp.paths) != 1 {
		t.Fatalf("err %v after %d uploads", err, len(cp.paths))
	}
}

// Both sides validate host filters alike (contracts/agent-protocol/testdata/capture-host-filters.json, also read by
// the control plane's NetCaptureTest).
func TestCaptureHostFilterCorpus(t *testing.T) {
	raw, err := os.ReadFile("../../../contracts/agent-protocol/testdata/capture-host-filters.json")
	if err != nil {
		t.Fatal(err)
	}
	var corpus struct{ Valid, Invalid []string }
	if err := json.Unmarshal(raw, &corpus); err != nil || len(corpus.Valid) == 0 || len(corpus.Invalid) == 0 {
		t.Fatalf("corpus: %v", err)
	}
	for _, h := range corpus.Valid {
		if _, err := parseHostFilter(h); err != nil {
			t.Errorf("%q refused: %v", h, err)
		}
	}
	for _, h := range corpus.Invalid {
		if _, err := parseHostFilter(h); err == nil {
			t.Errorf("%q accepted", h)
		}
	}
}
