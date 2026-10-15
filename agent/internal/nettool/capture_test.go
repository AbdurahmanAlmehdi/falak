package nettool

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"errors"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/transport"
)

const testCaptureID = "01JA2B3C4D5E6F7G8H9J0K1M2N"

func TestCaptureSpecValidate(t *testing.T) {
	ok := []CaptureSpec{
		{CaptureID: testCaptureID},
		{CaptureID: testCaptureID, Interface: "wireguard", DurationS: 60, MaxBytes: MaxCaptureBytes, Snaplen: FullSnaplen},
		{CaptureID: testCaptureID, Container: &Container{Name: "falak-db-1"}, Filter: &CaptureFilter{Host: "10.0.0.0/8", Port: 5432, Protocol: "tcp", Direction: "in"}},
		{CaptureID: testCaptureID, Filter: &CaptureFilter{Host: "2001:db8::1", Protocol: "icmp"}},
	}
	for _, s := range ok {
		if err := s.Validate(); err != nil {
			t.Errorf("%+v: %v", s, err)
		}
	}
	s := CaptureSpec{CaptureID: testCaptureID}
	_ = s.Validate()
	if s.Interface != "default" || s.DurationS != 30 || s.MaxBytes != 20<<20 || s.Snaplen != 96 {
		t.Errorf("defaults: %+v", s)
	}
	bad := []CaptureSpec{
		{CaptureID: "../../etc"},
		{CaptureID: testCaptureID, Interface: "eth0"},
		{CaptureID: testCaptureID, Interface: "-w"},
		{CaptureID: testCaptureID, Interface: "default", Container: &Container{Name: "web"}},
		{CaptureID: testCaptureID, DurationS: 61},
		{CaptureID: testCaptureID, MaxBytes: MaxCaptureBytes + 1},
		{CaptureID: testCaptureID, MaxBytes: 1000},
		{CaptureID: testCaptureID, Snaplen: 10},
		{CaptureID: testCaptureID, Container: &Container{Name: "-web"}},
		{CaptureID: testCaptureID, Filter: &CaptureFilter{Host: "example.com"}},
		{CaptureID: testCaptureID, Filter: &CaptureFilter{Host: "-w /tmp/x"}},
		{CaptureID: testCaptureID, Filter: &CaptureFilter{Host: "10.0.0.1 or port 22"}},
		{CaptureID: testCaptureID, Filter: &CaptureFilter{Host: "fe80::1%eth0"}},
		{CaptureID: testCaptureID, Filter: &CaptureFilter{Port: 70000}},
		{CaptureID: testCaptureID, Filter: &CaptureFilter{Protocol: "icmp", Port: 1}},
		{CaptureID: testCaptureID, Filter: &CaptureFilter{Protocol: "arp"}},
		{CaptureID: testCaptureID, Filter: &CaptureFilter{Direction: "inout"}},
	}
	for _, s := range bad {
		if err := s.Validate(); err == nil {
			t.Errorf("%+v: accepted", s)
		}
	}
}

func TestCaptureExpressionAndArgv(t *testing.T) {
	cases := map[string]*CaptureFilter{
		"":                                     nil,
		"host 10.0.0.5":                        {Host: "10.0.0.5"},
		"net 10.0.0.0/24 and tcp and port 443": {Host: "10.0.0.7/24", Protocol: "tcp", Port: 443},
		"(icmp or icmp6)":                      {Protocol: "icmp"},
		"udp and port 53":                      {Protocol: "udp", Port: 53},
		"host 2001:db8::1":                     {Host: "2001:db8::1/128"},
	}
	for want, f := range cases {
		if got := f.Expression(); got != want {
			t.Errorf("%+v: %q, want %q", f, got, want)
		}
	}
	s := CaptureSpec{CaptureID: testCaptureID, Filter: &CaptureFilter{Host: "10.0.0.5", Port: 80, Direction: "out"}}
	_ = s.Validate()
	got := strings.Join(tcpdumpArgv("/usr/bin/tcpdump", "eth0", s, "tcpdump"), "|")
	if want := "/usr/bin/tcpdump|-n|-p|-U|-i|eth0|-s|96|-w|-|-Z|tcpdump|-Q|out|--|host 10.0.0.5 and port 80"; got != want {
		t.Errorf("argv %s", got)
	}
	s = CaptureSpec{CaptureID: testCaptureID}
	_ = s.Validate()
	if got := strings.Join(tcpdumpArgv("tcpdump", "any", s, "nobody"), " "); got != "tcpdump -n -p -U -i any -s 96 -w - -Z nobody" {
		t.Errorf("argv without a filter: %s", got)
	}
}

func TestResolveInterface(t *testing.T) {
	root := t.TempDir()
	proc, sys := filepath.Join(root, "proc"), filepath.Join(root, "sys")
	write(t, filepath.Join(proc, "net", "route"), "Iface\tDestination\tGateway\tFlags\tRefCnt\tUse\tMetric\tMask\n"+
		"docker0\t000011AC\t00000000\t0001\t0\t0\t0\t0000FFFF\nens3\t00000000\t0100000A\t0003\t0\t0\t100\t00000000\n")
	write(t, filepath.Join(sys, "wg-b", "uevent"), "DEVTYPE=wireguard\nINTERFACE=wg-b\n")
	write(t, filepath.Join(sys, "wg-a", "uevent"), "DEVTYPE=wireguard\nINTERFACE=wg-a\n")
	write(t, filepath.Join(sys, "ens3", "uevent"), "INTERFACE=ens3\n")
	m := New(Options{ProcRoot: proc, SysClassNet: sys})
	for kind, want := range map[string]string{"default": "ens3", "wireguard": "wg-a"} {
		if got, err := m.resolveInterface(kind); err != nil || got != want {
			t.Errorf("%s: %q %v", kind, got, err)
		}
	}
	if _, err := m.resolveInterface("docker"); err == nil {
		t.Error("docker0 missing: accepted")
	}
	write(t, filepath.Join(sys, "docker0", "uevent"), "DEVTYPE=bridge\n")
	if got, err := m.resolveInterface("docker"); err != nil || got != "docker0" {
		t.Errorf("docker: %q %v", got, err)
	}
	empty := New(Options{ProcRoot: t.TempDir(), SysClassNet: t.TempDir()})
	if _, err := empty.resolveInterface("wireguard"); err == nil {
		t.Error("no WireGuard: accepted")
	}
	if _, err := empty.resolveInterface("default"); err == nil {
		t.Error("no route table: accepted")
	}
}

// pcapBytes is a little-endian pcap with n records of size bytes each.
func pcapBytes(n, size int) []byte {
	var b bytes.Buffer
	gh := make([]byte, 24)
	binary.LittleEndian.PutUint32(gh, 0xa1b2c3d4)
	binary.LittleEndian.PutUint16(gh[4:], 2)
	binary.LittleEndian.PutUint16(gh[6:], 4)
	binary.LittleEndian.PutUint32(gh[16:], 262144)
	binary.LittleEndian.PutUint32(gh[20:], 1)
	b.Write(gh)
	for i := 0; i < n; i++ {
		rh := make([]byte, 16)
		binary.LittleEndian.PutUint32(rh, uint32(i))
		binary.LittleEndian.PutUint32(rh[8:], uint32(size))
		binary.LittleEndian.PutUint32(rh[12:], uint32(size))
		b.Write(rh)
		b.Write(bytes.Repeat([]byte{byte(i)}, size))
	}
	return b.Bytes()
}

func TestCopyPcap(t *testing.T) {
	src := pcapBytes(10, 100)
	var out bytes.Buffer
	var c pcapCounts
	stopped := false
	if err := copyPcap(bytes.NewReader(src), &out, 1<<20, &c, func() { stopped = true }); err != nil || stopped {
		t.Fatal(err, stopped)
	}
	if !bytes.Equal(out.Bytes(), src) || c.packets.Load() != 10 || c.bytes.Load() != int64(len(src)) {
		t.Fatalf("copied %d bytes, %d packets", c.bytes.Load(), c.packets.Load())
	}

	// The cap ends at a record boundary: 24 + 3×116 = 372 ≤ 400 < 488.
	out.Reset()
	c = pcapCounts{}
	if err := copyPcap(bytes.NewReader(src), &out, 400, &c, func() { stopped = true }); err != nil || !stopped {
		t.Fatal(err, stopped)
	}
	if out.Len() != 372 || c.packets.Load() != 3 || !bytes.Equal(out.Bytes(), src[:372]) {
		t.Fatalf("capped at %d bytes, %d packets", out.Len(), c.packets.Load())
	}

	// A record cut short by the end of the stream is dropped.
	out.Reset()
	c = pcapCounts{}
	if err := copyPcap(bytes.NewReader(src[:24+116+50]), &out, 1<<20, &c, func() {}); err != nil || out.Len() != 140 || c.packets.Load() != 1 {
		t.Fatalf("truncated: %v %d", err, out.Len())
	}

	if err := copyPcap(strings.NewReader(strings.Repeat("x", 40)), io.Discard, 1<<20, &pcapCounts{}, func() {}); err == nil {
		t.Error("bad magic accepted")
	}
	huge := pcapBytes(1, 10)
	binary.LittleEndian.PutUint32(huge[24+8:], FullSnaplen+1)
	if err := copyPcap(bytes.NewReader(huge), io.Discard, 1<<20, &pcapCounts{}, func() {}); err == nil {
		t.Error("oversized record accepted")
	}
	if err := copyPcap(strings.NewReader(""), io.Discard, 1<<20, &pcapCounts{}, func() {}); err != nil {
		t.Errorf("empty stream: %v", err)
	}
}

func TestCaptureTempDir(t *testing.T) {
	parent := filepath.Join(t.TempDir(), "captures")
	m := New(Options{CaptureDir: parent, MinFreeBytes: 1})
	stale := filepath.Join(parent, "cap-old")
	fresh := filepath.Join(parent, "cap-new")
	for _, d := range []string{stale, fresh} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			t.Fatal(err)
		}
	}
	old := time.Now().Add(-time.Hour)
	_ = os.Chtimes(stale, old, old)
	_ = os.Chmod(parent, 0o755)
	dir, err := m.captureTempDir(MinCaptureBytes)
	if err != nil {
		t.Fatal(err)
	}
	if fi, _ := os.Stat(parent); fi.Mode().Perm() != 0o700 {
		t.Errorf("parent mode %v", fi.Mode().Perm())
	}
	if fi, _ := os.Stat(dir); fi.Mode().Perm() != 0o700 || !strings.HasPrefix(filepath.Base(dir), "cap-") {
		t.Errorf("dir %s mode %v", dir, fi.Mode().Perm())
	}
	if _, err := os.Stat(stale); !os.IsNotExist(err) {
		t.Error("stale capture directory kept")
	}
	if _, err := os.Stat(fresh); err != nil {
		t.Error("fresh capture directory removed")
	}

	// A symlinked parent is refused.
	link := filepath.Join(t.TempDir(), "link")
	_ = os.Symlink(t.TempDir(), link)
	if _, err := New(Options{CaptureDir: link, MinFreeBytes: 1}).captureTempDir(MinCaptureBytes); err == nil {
		t.Error("symlinked capture dir accepted")
	}
	// Not enough free space.
	if _, err := New(Options{CaptureDir: parent, MinFreeBytes: 1 << 62}).captureTempDir(MinCaptureBytes); err == nil || !strings.Contains(err.Error(), "disk space") {
		t.Errorf("free space: %v", err)
	}
}

// fakeCP records progress requests and uploads.
type fakeCP struct {
	mu        sync.Mutex
	progress  int
	cancel    bool
	failFirst int // uploads answered 503 before the first success
	status    int // a non-retryable status for every upload
	body      bytes.Buffer
	paths     []string
	headers   []map[string]string
}

func (f *fakeCP) Request(_ context.Context, typ string, in, out any) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if typ != "net.capture.progress" || in.(map[string]any)["capture_id"] != testCaptureID {
		return errors.New("unexpected request " + typ)
	}
	f.progress++
	out.(*struct {
		Cancel bool `json:"cancel"`
	}).Cancel = f.cancel
	return nil
}

func (f *fakeCP) Upload(_ context.Context, path string, body []byte, headers map[string]string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.status != 0 {
		return &transport.StatusError{Code: f.status}
	}
	if f.failFirst > 0 {
		f.failFirst--
		return &transport.StatusError{Code: 503}
	}
	f.paths = append(f.paths, path)
	f.headers = append(f.headers, headers)
	f.body.Write(body)
	return nil
}

func TestUploadCaptureChunksAndRetries(t *testing.T) {
	data := pcapBytes(30, 100) // 3504 bytes → chunks of 1000
	path := filepath.Join(t.TempDir(), "c.pcap")
	write(t, path, string(data))
	sum := sha256.Sum256(data)
	cp := &fakeCP{failFirst: 2}
	m := New(Options{CP: cp, ChunkBytes: 1000, RetryUnit: time.Millisecond})
	if err := m.uploadCapture(context.Background(), testCaptureID, path, int64(len(data)), hex.EncodeToString(sum[:])); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(cp.body.Bytes(), data) || len(cp.paths) != 4 {
		t.Fatalf("uploaded %d bytes in %d chunks", cp.body.Len(), len(cp.paths))
	}
	if cp.paths[3] != "/uploads/net.capture/"+testCaptureID+"?offset=3000" {
		t.Errorf("path %s", cp.paths[3])
	}
	last := sha256.Sum256(data[3000:])
	if h := cp.headers[3]; h["X-Falak-Chunk-Sha256"] != hex.EncodeToString(last[:]) || h["X-Falak-Upload-Size"] != "3504" || h["X-Falak-Upload-Sha256"] != hex.EncodeToString(sum[:]) {
		t.Errorf("headers %v", h)
	}
	if err := New(Options{CP: &fakeCP{status: 409}, RetryUnit: time.Millisecond}).uploadCapture(context.Background(), testCaptureID, path, int64(len(data)), "x"); err == nil {
		t.Error("409 not reported")
	}
}

// fakeTcpdump writes a script standing in for tcpdump: it prints the pcap in $FAKE_PCAP, records its argv, then
// waits for SIGINT (when it prints its counters and exits 0), like tcpdump.
func fakeTcpdump(t *testing.T, body string) string {
	t.Helper()
	p := filepath.Join(t.TempDir(), "tcpdump")
	write(t, p, "#!/bin/sh\necho \"$@\" > \"$FAKE_ARGS\"\n"+body)
	if err := os.Chmod(p, 0o755); err != nil {
		t.Fatal(err)
	}
	return p
}

const waitForInt = "trap 'echo \"packets captured\" >&2; exit 0' INT TERM\necho \"listening on $5\" >&2\ncat \"$FAKE_PCAP\"\nwhile :; do sleep 0.05; done\n"

type captureEnv struct {
	m    *Manager
	cp   *fakeCP
	args string
	out  *eventSink
}

type eventSink struct {
	mu     sync.Mutex
	stdout strings.Builder
	stderr strings.Builder
}

func (s *eventSink) Emit(ev commands.Event) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if ev.Stream == "stderr" {
		s.stderr.WriteString(ev.Data)
	} else {
		s.stdout.WriteString(ev.Data)
	}
}

func newCaptureEnv(t *testing.T, script string, pcap []byte) *captureEnv {
	t.Helper()
	root := t.TempDir()
	write(t, filepath.Join(root, "proc", "net", "route"), "Iface\tDestination\tGateway\tFlags\tRefCnt\tUse\tMetric\tMask\neth0\t00000000\t0100000A\t0003\t0\t0\t0\t00000000\n")
	write(t, filepath.Join(root, "pcap"), string(pcap))
	cp := &fakeCP{}
	e := &captureEnv{cp: cp, args: filepath.Join(root, "args"), out: &eventSink{}}
	e.m = New(Options{
		Logger: slog.New(slog.NewTextHandler(io.Discard, nil)), ProcRoot: filepath.Join(root, "proc"), CP: cp,
		CaptureDir: filepath.Join(root, "captures"), Tcpdump: fakeTcpdump(t, script), MinFreeBytes: 1,
		ProgressEvery: 50 * time.Millisecond, RetryUnit: time.Millisecond,
		Env: []string{"FAKE_PCAP=" + filepath.Join(root, "pcap"), "FAKE_ARGS=" + e.args},
	})
	return e
}

func (e *captureEnv) run(t *testing.T, s CaptureSpec) (any, error) {
	t.Helper()
	st := commands.NewTestStream("c1", e.out)
	res, err := e.m.Capture(context.Background(), s, st)
	st.Flush()
	if entries, _ := os.ReadDir(e.m.opts.CaptureDir); len(entries) != 0 {
		t.Errorf("capture files left behind: %v", entries)
	}
	return res, err
}

func TestCaptureRunsUploadsAndCleansUp(t *testing.T) {
	pcap := pcapBytes(5, 60)
	e := newCaptureEnv(t, waitForInt, pcap)
	res, err := e.run(t, CaptureSpec{CaptureID: testCaptureID, DurationS: 1, MaxBytes: MinCaptureBytes, Filter: &CaptureFilter{Protocol: "tcp", Port: 443}})
	if err != nil {
		t.Fatal(err)
	}
	r := res.(CaptureResult)
	sum := sha256.Sum256(pcap)
	if r.Bytes != int64(len(pcap)) || r.Packets != 5 || r.SHA256 != hex.EncodeToString(sum[:]) || r.Ended != "duration" || r.Interface != "eth0" || r.Namespace != "host" || r.Expression != "tcp and port 443" {
		t.Errorf("result %+v", r)
	}
	if !bytes.Equal(e.cp.body.Bytes(), pcap) {
		t.Error("uploaded bytes differ")
	}
	if e.cp.progress == 0 {
		t.Error("no progress reported")
	}
	args, _ := os.ReadFile(e.args)
	if !strings.HasPrefix(string(args), "-n -p -U -i eth0 -s 96 -w - -Z ") || !strings.HasSuffix(strings.TrimSpace(string(args)), "-- tcp and port 443") {
		t.Errorf("tcpdump args %q", args)
	}
	if !strings.Contains(e.out.stderr.String(), "listening on eth0") || !strings.Contains(e.out.stdout.String(), "uploaded") {
		t.Errorf("output %q / %q", e.out.stdout.String(), e.out.stderr.String())
	}
}

func TestCaptureStopsAtTheSizeCap(t *testing.T) {
	pcap := pcapBytes(400, 4000) // 1.6 MB > 1 MiB
	e := newCaptureEnv(t, waitForInt, pcap)
	start := time.Now()
	res, err := e.run(t, CaptureSpec{CaptureID: testCaptureID, DurationS: 30, MaxBytes: MinCaptureBytes})
	if err != nil {
		t.Fatal(err)
	}
	r := res.(CaptureResult)
	if r.Ended != "size_limit" || r.Bytes > MinCaptureBytes || r.Packets != (MinCaptureBytes-24)/4016 || time.Since(start) > 10*time.Second {
		t.Errorf("result %+v after %s", r, time.Since(start))
	}
	if int64(e.cp.body.Len()) != r.Bytes {
		t.Errorf("uploaded %d bytes", e.cp.body.Len())
	}
}

func TestCaptureCancelledByTheControlPlane(t *testing.T) {
	e := newCaptureEnv(t, waitForInt, pcapBytes(1, 60))
	e.cp.cancel = true
	_, err := e.run(t, CaptureSpec{CaptureID: testCaptureID, DurationS: 30})
	var ee *commands.ExitError
	if !errors.As(err, &ee) || ee.Code != ExitCancelled {
		t.Fatalf("err %v", err)
	}
	if e.cp.body.Len() != 0 {
		t.Error("a cancelled capture was uploaded")
	}
}

func TestCaptureTcpdumpFailing(t *testing.T) {
	e := newCaptureEnv(t, "echo 'tcpdump: eth0: You don'\"'\"'t have permission to capture on that device' >&2\nexit 1\n", nil)
	_, err := e.run(t, CaptureSpec{CaptureID: testCaptureID, DurationS: 5})
	if err == nil || !strings.Contains(err.Error(), "permission to capture") {
		t.Fatalf("err %v", err)
	}
}

func TestCaptureLimitsAndPayloadErrors(t *testing.T) {
	e := newCaptureEnv(t, waitForInt, pcapBytes(1, 60))
	_, err := e.m.Capture(context.Background(), CaptureSpec{CaptureID: "x"}, commands.NewTestStream("c", e.out))
	var pe *commands.PayloadError
	if !errors.As(err, &pe) {
		t.Errorf("payload error: %v", err)
	}
	for i := 0; i < cap(e.m.capSem); i++ {
		e.m.capSem <- struct{}{}
	}
	if _, err := e.m.Capture(context.Background(), CaptureSpec{CaptureID: testCaptureID}, commands.NewTestStream("c", e.out)); err == nil || !strings.Contains(err.Error(), "too many captures") {
		t.Errorf("limit: %v", err)
	}
	if _, err := New(Options{}).Capture(context.Background(), CaptureSpec{CaptureID: testCaptureID}, commands.NewTestStream("c", e.out)); err == nil {
		t.Error("no control plane: accepted")
	}
}

func write(t *testing.T, path, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}
