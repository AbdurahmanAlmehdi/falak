//go:build live

// Live check of the sandbox agent code against real runsc, ip, iptables and Docker (docs/plans/SANDBOXES.md). Run as
// root on a disposable Linux box with Docker installed; it installs runsc, creates bridges, chains and sandboxes:
//
//	GOOS=linux GOARCH=amd64 go test -tags live -c -o sandbox.live ./internal/sandbox
//	sudo ./sandbox.live -test.v -test.timeout 30m
//
// Every check logs a "CHECK <name> PASS|FAIL <detail>" line; a FAIL also fails the test.
package sandbox

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/db"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/netcfg"
	"github.com/OthmanHaba/falak/agent/internal/pty"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

type liveStream struct {
	mu  sync.Mutex
	out strings.Builder
}

func (s *liveStream) Stdout() io.Writer { return io.Discard }
func (s *liveStream) Stderr() io.Writer { return io.Discard }
func (s *liveStream) Progress(float64)  {}
func (s *liveStream) Emit(_, data string) {
	b, _ := base64.StdEncoding.DecodeString(data)
	s.mu.Lock()
	s.out.Write(b)
	s.mu.Unlock()
}
func (s *liveStream) String() string { s.mu.Lock(); defer s.mu.Unlock(); return s.out.String() }

// captureRequests records sandbox.reaped requests and accepts them.
type captureRequests struct {
	mu   sync.Mutex
	reqs []map[string]string
}

func (c *captureRequests) Request(_ context.Context, typ string, in, out any) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	m := map[string]string{"type": typ}
	for k, v := range in.(map[string]string) {
		m[k] = v
	}
	c.reqs = append(c.reqs, m)
	return json.Unmarshal([]byte(`{"accepted":true}`), out)
}

func (c *captureRequests) find(id string) map[string]string {
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, r := range c.reqs {
		if r["id"] == id {
			return r
		}
	}
	return nil
}

type live struct {
	t   *testing.T
	ctx context.Context
	s   *Service
	req *captureRequests
}

func newLiveService(req *captureRequests) *Service {
	return New(Deps{Runner: runner.Exec{}, FS: hostfs.FS{}, Docker: docker.NewClient(""), Requests: req, Root: "/var/lib/falak/sandboxes",
		DB: db.New(db.Deps{Runner: runner.Exec{}, FS: hostfs.FS{}})})
}

func (l *live) check(name string, ok bool, format string, a ...any) bool {
	l.t.Helper()
	status := "PASS"
	if !ok {
		status = "FAIL"
		l.t.Errorf("CHECK %s FAIL %s", name, fmt.Sprintf(format, a...))
	}
	l.t.Logf("CHECK %s %s %s", name, status, fmt.Sprintf(format, a...))
	return ok
}

func newID() string {
	const al = "0123456789abcdefghijklmnopqrstuvwxyz"
	b := make([]byte, 26)
	_, _ = rand.Read(b)
	for i := range b {
		b[i] = al[int(b[i])%len(al)]
	}
	return string(b)
}

func sh(name string, args ...string) string {
	out, _ := exec.Command(name, args...).CombinedOutput()
	return strings.TrimSpace(string(out))
}

func (l *live) create(p CreatePayload) (CreateResult, time.Duration, error) {
	if p.CPUs == 0 {
		p.CPUs = 1
	}
	if p.MemoryMB == 0 {
		p.MemoryMB = 512
	}
	if p.DiskMB == 0 {
		p.DiskMB = 1024
	}
	if p.Network == "" {
		p.Network = NetInternet
	}
	if p.ExpiresAt == 0 {
		p.ExpiresAt = time.Now().Add(time.Hour).Unix()
	}
	start := time.Now()
	res, err := l.s.Create(l.ctx, p, &liveStream{})
	if err != nil {
		return CreateResult{}, time.Since(start), err
	}
	return res.(CreateResult), time.Since(start), nil
}

type execRes struct {
	ExecResult
	out, errOut string
	err         error
}

func (l *live) exec(id string, cmd []string, mod func(*ExecPayload)) execRes {
	p := ExecPayload{ID: id, Cmd: cmd, TimeoutS: 60}
	if mod != nil {
		mod(&p)
	}
	r, err := l.s.Exec(l.ctx, p, nil)
	if err != nil {
		return execRes{err: err}
	}
	er := r.(ExecResult)
	o, _ := base64.StdEncoding.DecodeString(er.Stdout)
	e, _ := base64.StdEncoding.DecodeString(er.Stderr)
	return execRes{ExecResult: er, out: string(o), errOut: string(e)}
}

func (l *live) sh(id, script string) execRes { return l.exec(id, []string{"sh", "-c", script}, nil) }

func (l *live) kill(id string) {
	if _, err := l.s.Kill(l.ctx, KillPayload{ID: id}, nil); err != nil {
		l.t.Logf("kill %s: %v", id, err)
	}
}

// probe connects from a sandbox (busybox nc) and prints what came back plus nc's exit code.
func (l *live) probe(id, host, port string) string {
	r := l.sh(id, fmt.Sprintf("timeout 6 nc -w 4 %s %s </dev/null 2>&1; echo rc=$?", host, port))
	return strings.TrimSpace(r.out + r.errOut)
}

func iptablesSave() string {
	var out []string
	re := regexp.MustCompile(`\[\d+:\d+\]`)
	for _, line := range strings.Split(sh("iptables-save"), "\n") {
		if strings.HasPrefix(line, "#") {
			continue
		}
		out = append(out, re.ReplaceAllString(line, ""))
	}
	return strings.Join(out, "\n")
}

func countLines(s, sub string) int {
	n := 0
	for _, line := range strings.Split(s, "\n") {
		if strings.Contains(line, sub) {
			n++
		}
	}
	return n
}

// perSandboxRules counts the rules for one sandbox address (accept and MASQUERADE by source).
func perSandboxRules(save string) int {
	n := 0
	for _, line := range strings.Split(save, "\n") {
		if strings.Contains(line, "-s 10.88.") && strings.Contains(line, "/32") {
			n++
		}
	}
	return n
}

func hostAddrs() (private, public string) {
	private = sh("sh", "-c", "ip -4 -o addr show scope global | grep -v -e br-sbx -e docker0 | awk '{print $4}' | cut -d/ -f1 | head -1")
	tok := sh("curl", "-s", "-X", "PUT", "-H", "X-aws-ec2-metadata-token-ttl-seconds: 60", "http://169.254.169.254/latest/api/token")
	public = sh("curl", "-s", "-H", "X-aws-ec2-metadata-token: "+tok, "http://169.254.169.254/latest/meta-data/public-ipv4")
	return
}

func TestLive(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	req := &captureRequests{}
	ctx := context.Background()
	l := &live{t: t, ctx: ctx, s: newLiveService(req), req: req}

	// Leftovers of an earlier run.
	for _, st := range l.s.states() {
		l.kill(st.ID)
	}

	// ---- prepare
	hadRunsc := sh("sh", "-c", "command -v runsc") != ""
	start := time.Now()
	pr, err := l.s.Prepare(ctx, PreparePayload{}, &liveStream{})
	if !l.check("prepare", err == nil, "had runsc=%v result=%+v err=%v in %s", hadRunsc, pr, err, time.Since(start).Round(time.Millisecond)) {
		t.FailNow()
	}
	l.check("prepare.bridge", strings.Contains(sh("ip", "-4", "addr", "show", bridge), "10.88.0.1/16"), "%s", sh("ip", "-br", "addr", "show", bridge))
	// The proxy listener only exists with allowlist sandboxes; Prepare again is a no-op.
	_, err = l.s.Prepare(ctx, PreparePayload{}, &liveStream{})
	l.check("prepare.again", err == nil, "err=%v", err)

	// ---- create
	A, B, U, N := newID(), newID(), newID(), newID()
	created := map[string]CreateResult{}
	for _, c := range []struct {
		name string
		p    CreatePayload
	}{
		{"alpine.internet.A", CreatePayload{ID: A, Image: "alpine:3.20", Env: map[string]string{"FOO": "from-create", "SECRET_X": "s3cr3t"}}},
		{"alpine.internet.B", CreatePayload{ID: B, Image: "alpine:3.20"}},
		{"ubuntu.none.U", CreatePayload{ID: U, Image: "ubuntu:24.04", Network: NetNone}},
		{"node.allowlist.N", CreatePayload{ID: N, Image: "node:22-alpine", Network: NetAllowlist,
			AllowHosts: []string{"example.com", "169.254.169.254.nip.io", "localtest.me"}}},
	} {
		res, d, err := l.create(c.p)
		created[c.p.ID] = res
		l.check("create."+c.name, err == nil && res.Status == "running", "%s ip=%s digest=%.19s err=%v", d.Round(time.Millisecond), res.IP, res.ImageDigest, err)
	}
	// A second create of the same image hits the base rootfs cache.
	X := newID()
	_, d, err := l.create(CreatePayload{ID: X, Image: "alpine:3.20", Network: NetNone})
	l.check("create.cached-image", err == nil, "%s err=%v", d.Round(time.Millisecond), err)
	l.kill(X)

	// ---- H1: base rootfs trees are root-only and stripped
	sh("useradd", "-M", "falaklive")
	nobody := sh("sh", "-c", "su -s /bin/sh falaklive -c 'ls /var/lib/falak/sandboxes/images/ 2>&1; ls "+created[U].ImageDigest[7:]+" 2>&1'; stat -c '%a %n' /var/lib/falak/sandboxes /var/lib/falak/sandboxes/images")
	ubuntuRoot := filepath.Join(l.s.d.Root, "images", strings.TrimPrefix(created[U].ImageDigest, "sha256:"), "rootfs")
	suid := sh("sh", "-c", "find "+ubuntuRoot+" -xdev -type f -perm /6000 | wc -l; find "+ubuntuRoot+" -xdev \\( -type b -o -type c \\) | wc -l")
	l.check("h1.root-only", strings.Contains(nobody, "Permission denied") && strings.Contains(nobody, "700 /var/lib/falak/sandboxes\n700 /var/lib/falak/sandboxes/images"), "%q", nobody)
	l.check("h1.no-setuid-or-devices", suid == "0\n0", "setuid files, device nodes in ubuntu rootfs: %q", suid)
	r0 := l.exec(U, []string{"sh", "-c", "ls -l /usr/bin/passwd | cut -c1-10; ls /dev | head -3 | tr '\\n' ' '"}, nil)
	l.check("h1.sandbox-still-fine", r0.err == nil && r0.ExitCode == 0, "%q", r0.out)

	// ---- exec
	r := l.exec(A, []string{"echo", "hello", "world"}, nil)
	l.check("exec.argv", r.err == nil && r.out == "hello world\n" && r.ExitCode == 0, "out=%q code=%d dur=%dms err=%v", r.out, r.ExitCode, r.DurationMS, r.err)
	r = l.exec(A, []string{"sh", "-lc", "echo $HOME $FOO; pwd; id -u"}, nil)
	l.check("exec.sh-lc", r.err == nil && r.out == "/root from-create\n/root\n0\n", "out=%q err=%v", r.out, r.err)
	r = l.exec(A, []string{"pwd"}, func(p *ExecPayload) { p.Cwd = "/tmp" })
	l.check("exec.cwd", r.out == "/tmp\n", "out=%q err=%v", r.out, r.err)
	r = l.exec(A, []string{"sh", "-c", "echo $FOO $BAR"}, func(p *ExecPayload) { p.Env = map[string]string{"FOO": "override", "BAR": "b"} })
	l.check("exec.env-override", r.out == "override b\n", "out=%q err=%v", r.out, r.err)
	r = l.exec(A, []string{"sh", "-c", "env | grep -c '^FOO='"}, func(p *ExecPayload) { p.Env = map[string]string{"FOO": "override"} })
	l.check("exec.env-dedup", r.out == "1\n", "FOO entries=%q", r.out)
	r = l.exec(A, []string{"sh", "-c", "cat | tr a-z A-Z"}, func(p *ExecPayload) { p.Stdin = base64.StdEncoding.EncodeToString([]byte("from stdin")) })
	l.check("exec.stdin", r.out == "FROM STDIN", "out=%q err=%v", r.out, r.err)
	r = l.exec(A, []string{"cat"}, nil)
	l.check("exec.no-stdin-eof", r.err == nil && r.ExitCode == 0 && !r.TimedOut, "cat without stdin: code=%d timed_out=%v dur=%dms err=%v", r.ExitCode, r.TimedOut, r.DurationMS, r.err)
	r = l.exec(A, []string{"sh", "-c", "echo started; sleep 30"}, func(p *ExecPayload) { p.TimeoutS = 2 })
	left := l.sh(A, "ps -o pid,args | grep 'sleep 30' | grep -v grep | wc -l")
	l.check("exec.timeout", r.err == nil && r.TimedOut && r.ExitCode == -1 && r.DurationMS < 4000 && strings.TrimSpace(left.out) == "0",
		"timed_out=%v code=%d dur=%dms out=%q left-running=%q err=%v", r.TimedOut, r.ExitCode, r.DurationMS, r.out, strings.TrimSpace(left.out), r.err)
	r = l.exec(A, []string{"sh", "-c", "head -c 5000 /dev/zero | tr '\\0' x; head -c 3000 /dev/zero | tr '\\0' y >&2"}, func(p *ExecPayload) { p.MaxOutput = 1000 })
	l.check("exec.output-cap", r.Truncated && len(r.out) == 1000 && len(r.errOut) == 1000, "truncated=%v stdout=%d stderr=%d", r.Truncated, len(r.out), len(r.errOut))
	r = l.exec(A, []string{"sh", "-c", "head -c 300000 /dev/zero | tr '\\0' x"}, nil)
	l.check("exec.output-default-cap", r.Truncated && len(r.out) == maxOutput, "truncated=%v stdout=%d", r.Truncated, len(r.out))
	r = l.exec(A, []string{"sh", "-c", "echo oops >&2; exit 7"}, nil)
	l.check("exec.exit-code", r.ExitCode == 7 && r.errOut == "oops\n", "code=%d stderr=%q", r.ExitCode, r.errOut)
	r = l.exec(A, []string{"no-such-binary"}, nil)
	l.check("exec.missing-binary", r.err != nil || r.ExitCode != 0, "code=%d stderr=%q err=%v", r.ExitCode, r.errOut, r.err)
	r = l.exec(newID(), []string{"true"}, nil)
	l.check("exec.unknown-sandbox", r.err != nil, "err=%v", r.err)
	var lat []int64
	for i := 0; i < 5; i++ {
		lat = append(lat, l.exec(A, []string{"true"}, nil).DurationMS)
	}
	l.check("exec.latency", true, "true x5: %v ms", lat)
	procs, _ := filepath.Glob(filepath.Join(l.s.dir(A), "exec-*"))
	l.check("exec.no-leftover-files", len(procs) == 0, "%v", procs)

	// ---- files
	write := func(id, path string, content []byte, mode string, mkdirs *bool) error {
		_, err := l.s.WriteFile(ctx, WritePayload{ID: id, Path: path, Content: base64.StdEncoding.EncodeToString(content), Mode: mode, Mkdirs: mkdirs}, nil)
		return err
	}
	read := func(id, path string) (ReadResult, []byte, error) {
		res, err := l.s.ReadFile(ctx, ReadPayload{ID: id, Path: path, MaxBytes: maxFile}, nil)
		if err != nil {
			return ReadResult{}, nil, err
		}
		rr := res.(ReadResult)
		b, _ := base64.StdEncoding.DecodeString(rr.Content)
		return rr, b, nil
	}
	err = write(A, "/tmp/d/e/run.sh", []byte("#!/bin/sh\necho ran\n"), "0755", nil)
	rr, b, rerr := read(A, "/tmp/d/e/run.sh")
	l.check("files.write-read-mode", err == nil && rerr == nil && string(b) == "#!/bin/sh\necho ran\n" && rr.Mode == "0755" && rr.Size == 19,
		"write=%v read=%v mode=%s size=%d", err, rerr, rr.Mode, rr.Size)
	r = l.exec(A, []string{"/tmp/d/e/run.sh"}, nil)
	l.check("files.executable", r.out == "ran\n", "out=%q", r.out)
	bin := make([]byte, 256*40)
	for i := range bin {
		bin[i] = byte(i)
	}
	err = write(A, "/root/bin.dat", bin, "", nil)
	rr, b, rerr = read(A, "/root/bin.dat")
	l.check("files.binary", err == nil && rerr == nil && string(b) == string(bin) && rr.Mode == "0644", "write=%v read=%v len=%d mode=%s", err, rerr, len(b), rr.Mode)
	big := make([]byte, maxFile)
	_, _ = rand.Read(big)
	start = time.Now()
	err = write(A, "/root/big.bin", big, "", nil)
	_, b, rerr = read(A, "/root/big.bin")
	l.check("files.5MB", err == nil && rerr == nil && string(b) == string(big), "write+read %s write=%v read=%v", time.Since(start).Round(time.Millisecond), err, rerr)
	res, err := l.s.ReadFile(ctx, ReadPayload{ID: A, Path: "/root/big.bin", MaxBytes: 100}, nil)
	l.check("files.read-truncated", err == nil && res.(ReadResult).Truncated && res.(ReadResult).Size == maxFile, "err=%v", err)
	_, _, err = read(A, "/nope/x")
	l.check("files.read-not_found", errors.Is(err, ErrNotFound), "err=%v", err)
	_, _, err = read(A, "/tmp")
	l.check("files.read-is_directory", errors.Is(err, ErrIsDirectory), "err=%v", err)
	no := false
	err = write(A, "/tmp/missing-dir/x", []byte("x"), "", &no)
	l.check("files.write-no-mkdirs", err != nil, "err=%v", err)
	_ = l.sh(A, "ln -s /tmp/d/e/run.sh /tmp/d/e/link; mkfifo /tmp/d/e/fifo; mkdir /tmp/d/e/sub; touch '/tmp/d/e/.hidden' '/tmp/d/e/with space'")
	lres, err := l.s.ListFiles(ctx, ListPayload{ID: A, Path: "/tmp/d/e"}, nil)
	got := map[string]string{}
	if err == nil {
		for _, e := range lres.(ListResult).Entries {
			got[e.Name] = e.Type + "/" + e.Mode
		}
	}
	l.check("files.list", err == nil && got["run.sh"] == "file/0755" && got["link"] == "link/0777" && got["sub"] == "dir/0755" &&
		got["fifo"] == "other/0644" && got[".hidden"] == "file/0644" && got["with space"] == "file/0644" && len(got) == 6, "err=%v %v", err, got)
	_, err = l.s.ListFiles(ctx, ListPayload{ID: A, Path: "/tmp/d/e/run.sh"}, nil)
	l.check("files.list-not_directory", errors.Is(err, ErrNotDirectory), "err=%v", err)
	_, err = l.s.ListFiles(ctx, ListPayload{ID: A, Path: "/nope"}, nil)
	l.check("files.list-not_found", errors.Is(err, ErrNotFound), "err=%v", err)
	_ = l.sh(A, "mkdir -p /tmp/many && cd /tmp/many && i=0; while [ $i -lt 1005 ]; do : > f$i; i=$((i+1)); done")
	lres, err = l.s.ListFiles(ctx, ListPayload{ID: A, Path: "/tmp/many"}, nil)
	l.check("files.list-1000", err == nil && len(lres.(ListResult).Entries) == 1000 && lres.(ListResult).Truncated, "err=%v", err)
	_, err = l.s.DeleteFile(ctx, DeletePayload{ID: A, Path: "/tmp/d/e/run.sh"}, nil)
	_, _, rerr = read(A, "/tmp/d/e/run.sh")
	l.check("files.delete", err == nil && errors.Is(rerr, ErrNotFound), "err=%v after=%v", err, rerr)
	_, err = l.s.DeleteFile(ctx, DeletePayload{ID: A, Path: "/tmp/d/e/run.sh"}, nil)
	l.check("files.delete-not_found", errors.Is(err, ErrNotFound), "err=%v", err)
	_, err = l.s.DeleteFile(ctx, DeletePayload{ID: A, Path: "/tmp/d/e/link"}, nil)
	l.check("files.delete-dangling-link", err == nil, "err=%v", err)
	_, err = l.s.DeleteFile(ctx, DeletePayload{ID: A, Path: "/tmp/d"}, nil)
	l.check("files.delete-dir-needs-recursive", err != nil, "err=%v", err)
	_, err = l.s.DeleteFile(ctx, DeletePayload{ID: A, Path: "/tmp/d", Recursive: true}, nil)
	_, _, rerr = read(A, "/tmp/d")
	l.check("files.delete-recursive", err == nil && errors.Is(rerr, ErrNotFound), "err=%v after=%v", err, rerr)

	// ---- repo clone
	G := newID()
	_, d, err = l.create(CreatePayload{ID: G, Image: "alpine/git:latest", Repo: &Repo{URL: "https://github.com/octocat/Hello-World", Ref: "master"}})
	r = l.sh(G, "cat /workspace/Hello-World/README; git -C /workspace/Hello-World rev-parse --abbrev-ref HEAD; git -C /workspace/Hello-World config --list | grep -ci credential; env | grep -c FALAK_GIT")
	l.check("repo.clone", err == nil && strings.HasPrefix(r.out, "Hello World!\nmaster\n0\n0"), "%s err=%v out=%q", d.Round(time.Millisecond), err, r.out)
	l.kill(G)
	G2 := newID()
	_, _, err = l.create(CreatePayload{ID: G2, Image: "alpine/git:latest", Repo: &Repo{URL: "https://github.com/octocat/Hello-World", Token: "ghp_notarealtoken"}})
	r = l.sh(G2, "git -C /workspace/Hello-World config --list | grep -c -e credential -e ghp_; grep -rc ghp_ /workspace/Hello-World/.git/config")
	l.check("repo.clone-with-token", err == nil && strings.HasPrefix(r.out, "0\n0"), "err=%v out=%q", err, r.out)
	l.kill(G2)
	G3 := newID()
	_, _, err = l.create(CreatePayload{ID: G3, Image: "ubuntu:24.04", Repo: &Repo{URL: "https://github.com/octocat/Hello-World"}})
	_, statErr := os.Stat(l.s.dir(G3))
	l.check("repo.no-git", err != nil && strings.Contains(err.Error(), "has no git") && os.IsNotExist(statErr), "err=%v state-dir-removed=%v", err, os.IsNotExist(statErr))
	G4 := newID()
	_, _, err = l.create(CreatePayload{ID: G4, Image: "alpine/git:latest", Repo: &Repo{URL: "https://github.com/octocat/does-not-exist-falak"}})
	l.check("repo.clone-fails", err != nil && strings.Contains(err.Error(), "git clone failed"), "err=%v", err)

	// ---- network: internet sandbox A
	priv, pub := hostAddrs()
	ipB := created[B].IP
	_ = l.sh(B, "nohup sh -c 'while :; do echo HELLO-B | nc -l -p 8080; done' >/dev/null 2>&1 &")
	time.Sleep(time.Second)
	selfB := l.sh(B, "timeout 5 nc -w 3 127.0.0.1 8080 </dev/null")
	l.check("net.control.B-listens", strings.Contains(selfB.out, "HELLO-B"), "%q", selfB.out)
	r = l.sh(A, "wget -q -T 10 -O - https://example.com | grep -o 'Example Domain' | head -1")
	l.check("net.internet.https", strings.TrimSpace(r.out) == "Example Domain", "out=%q err=%q", r.out, r.errOut)
	r = l.sh(A, "nslookup example.com 2>&1 | tail -3")
	l.check("net.internet.dns", strings.Contains(r.out, "Address"), "%q", r.out)
	r = l.sh(A, "cat /etc/resolv.conf; ip -4 -o addr show eth0 2>/dev/null | awk '{print $4}'; ip route 2>/dev/null")
	l.check("net.internet.config", strings.Contains(r.out, "1.1.1.1") && strings.Contains(r.out, created[A].IP+"/16"), "%q", r.out)
	r = l.sh(A, "wget -q -T 4 -O - http://169.254.169.254/latest/meta-data/ 2>&1; echo rc=$?")
	l.check("net.internet.metadata-blocked", !strings.Contains(r.out, "ami-id") && strings.Contains(r.out, "rc=1"), "%q", strings.TrimSpace(r.out))
	for _, c := range []struct{ name, host, port string }{
		{"host-private-ip", priv, "22"}, {"host-public-ip", pub, "22"}, {"gateway", gatewayIP, "22"}, {"docker0", "172.17.0.1", "22"},
		{"other-sandbox", ipB, "8080"},
	} {
		got := l.probe(A, c.host, c.port)
		l.check("net.internet."+c.name+"-blocked", !strings.Contains(got, "SSH-") && !strings.Contains(got, "HELLO"), "%s:%s → %q", c.host, c.port, got)
	}
	l.check("net.control.host-ssh-banner", strings.Contains(sh("sh", "-c", "timeout 4 nc -w 2 "+priv+" 22 </dev/null"), "SSH-"), "host reaches its own sshd")
	sh("docker", "run", "-d", "--rm", "--name", "falak-live-nginx", "nginx:alpine")
	nginxIP := sh("docker", "inspect", "-f", "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}", "falak-live-nginx")
	time.Sleep(time.Second)
	r = l.sh(A, "wget -q -T 4 -O - http://"+nginxIP+"/ 2>&1 | head -c 80; echo rc=$?")
	ctl := sh("sh", "-c", "curl -s -m 3 http://"+nginxIP+"/ | grep -o -m1 nginx")
	l.check("net.internet.docker-container-blocked", !strings.Contains(r.out, "nginx") && strings.Contains(ctl, "nginx"), "container %s: from sandbox %q, from host %q", nginxIP, strings.TrimSpace(r.out), ctl)
	sh("docker", "rm", "-f", "falak-live-nginx")
	r = l.sh(A, "ping -c 1 -W 2 "+ipB+" >/dev/null 2>&1; echo rc=$?")
	l.check("net.internet.icmp-other-sandbox-blocked", strings.Contains(r.out, "rc=1"), "%q", strings.TrimSpace(r.out))
	l.check("net.bridge-port-isolated", strings.Contains(sh("sh", "-c", "bridge -d link show dev "+vethName(A)), "isolated on"), "%s", sh("sh", "-c", "bridge -d link show dev "+vethName(A)+" | tr '\\n' ' '"))

	// ---- H5: Docker's own rules (user network, published ports, dockerd restart) never let a sandbox through
	sh("docker", "network", "create", "falak-live-net")
	sh("docker", "run", "-d", "--restart", "always", "--name", "falak-live-web", "--network", "falak-live-net", "-p", "8081:80", "-p", "127.0.0.1:8082:80", "nginx:alpine")
	time.Sleep(2 * time.Second)
	webIP := sh("docker", "inspect", "-f", "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}", "falak-live-web")
	h5 := func(stage string) {
		for _, c := range []struct{ name, url string }{
			{"user-net-container", "http://" + webIP + "/"}, {"published-port-private-ip", "http://" + priv + ":8081/"},
			{"published-port-docker0", "http://172.17.0.1:8081/"}, {"published-port-public-ip", "http://" + pub + ":8081/"},
		} {
			r := l.sh(A, "wget -q -T 4 -O - "+c.url+" 2>&1 | grep -o -m1 -e nginx -e 'timed out' -e refused; true")
			ctl := sh("sh", "-c", "curl -s -m 3 "+c.url+" | grep -o -m1 nginx")
			l.check("h5."+stage+"."+c.name+"-blocked", !strings.Contains(r.out, "nginx"), "%s → sandbox %q (host %q)", c.url, strings.TrimSpace(r.out), ctl)
		}
		r := l.sh(A, "wget -q -T 10 -O - https://example.com | grep -c 'Example Domain'")
		l.check("h5."+stage+".internet-still-works", strings.TrimSpace(r.out) == "1", "%q", r.out)
	}
	h5("network-created")
	sh("systemctl", "restart", "docker")
	time.Sleep(3 * time.Second)
	fw := sh("sh", "-c", "iptables -S FORWARD | sed -n 2,3p; iptables -t raw -S PREROUTING | sed -n 2p")
	l.check("h5.after-docker-restart.order", true, "before the agent re-asserts: %q", fw)
	h5("after-docker-restart")
	err = l.s.ensureNetwork(ctx)
	fw = sh("sh", "-c", "iptables -S FORWARD | sed -n 2,3p; iptables -t raw -S PREROUTING | sed -n 2p")
	l.check("h5.reasserted-on-top", err == nil && strings.HasPrefix(fw, "-A FORWARD -i br-sbx -j FALAK-SBX\n-A FORWARD -o br-sbx -j FALAK-SBX\n-A PREROUTING -i br-sbx -j FALAK-SBX-PRE"), "err=%v %q", err, fw)
	h5("reasserted")
	sh("docker", "rm", "-f", "falak-live-web")
	sh("docker", "network", "rm", "falak-live-net")

	// ---- L2: a sandbox's veth can't send with another sandbox's address
	nsA := netnsName(A)
	brMAC := sh("cat", "/sys/class/net/"+bridge+"/address")
	sh("ip", "netns", "exec", nsA, "ip", "addr", "add", ipB+"/16", "dev", "eth0")
	sh("ip", "netns", "exec", nsA, "ip", "neigh", "replace", gatewayIP, "lladdr", brMAC, "dev", "eth0") // IP frames, not only ARP
	spoof := sh("sh", "-c", "(timeout 4 tcpdump -lni "+bridge+" -c 4 'host "+ipB+"' 2>/dev/null | grep -c . > /tmp/br.cnt) & (timeout 4 tcpdump -lni "+vethName(A)+" -c 4 'host "+ipB+"' 2>/dev/null | grep -c . > /tmp/veth.cnt) & sleep 1; ip netns exec "+nsA+" ping -c 3 -W 1 -I "+ipB+" "+gatewayIP+" >/dev/null 2>&1; ip netns exec "+nsA+" arping -c 1 -w 1 -s "+ipB+" "+gatewayIP+" >/dev/null 2>&1; wait; echo veth=$(cat /tmp/veth.cnt) bridge=$(cat /tmp/br.cnt)")
	sh("ip", "netns", "exec", nsA, "ip", "neigh", "del", gatewayIP, "dev", "eth0")
	sh("ip", "netns", "exec", nsA, "ip", "addr", "del", ipB+"/16", "dev", "eth0")
	l.check("l2.anti-spoof", strings.Contains(spoof, "bridge=0") && !strings.Contains(spoof, "veth=0"), "%q; table: %q", spoof, sh("sh", "-c", "nft list table bridge falak_sbx | grep -c saddr"))
	r = l.sh(A, "wget -q -T 10 -O - https://example.com | grep -c 'Example Domain'")
	l.check("l2.real-address-still-works", strings.TrimSpace(r.out) == "1", "%q", r.out)

	// ---- network: none (ubuntu)
	r = l.exec(U, []string{"sh", "-c", "cat /proc/net/dev | tail -n +3 | awk '{print $1}' | tr '\\n' ' '; timeout 5 bash -c 'echo > /dev/tcp/1.1.1.1/53' 2>&1; echo rc=$?"}, nil)
	l.check("net.none", !strings.Contains(r.out, "eth0") && strings.Contains(r.out, "rc=1"), "%q", strings.TrimSpace(r.out))
	l.check("net.none.no-netns", !strings.Contains(sh("ip", "netns", "list"), netnsName(U)) && created[U].IP == "", "ip=%q", created[U].IP)

	// ---- network: allowlist (node)
	js := `const http=require('http'),tls=require('tls'),net=require('net');
const [mode,host,port]=process.argv.slice(2);const P={host:'10.88.0.1',port:3128};
const done=m=>{console.log(m);process.exit(0)};setTimeout(()=>done('TIMEOUT'),8000);
if(mode==='connect'){const r=http.request({...P,method:'CONNECT',path:host+':'+port});
 r.on('response',res=>done('CONNECT '+res.statusCode));
 r.on('connect',(res,sock)=>{if(res.statusCode!==200)return done('CONNECT '+res.statusCode);
  const t=tls.connect({socket:sock,servername:host},()=>t.write('GET / HTTP/1.1\r\nHost: '+host+'\r\nConnection: close\r\n\r\n'));
  let d='';t.on('data',c=>d+=c);t.on('end',()=>done('CONNECT 200 '+d.split('\r\n')[0]));t.on('error',e=>done('TLSERR '+e.message))});
 r.on('error',e=>done('ERR '+e.message));r.end()}
else if(mode==='get'){http.get({...P,path:'http://'+host+'/',headers:{Host:host}},res=>{res.resume();done('GET '+res.statusCode)}).on('error',e=>done('ERR '+e.message))}
else{const s=net.connect({host,port:+port});s.on('connect',()=>done('DIRECT connected'));s.on('error',e=>done('DIRECT '+e.code))}`
	if err := write(N, "/root/p.js", []byte(js), "", nil); err != nil {
		t.Errorf("write p.js: %v", err)
	}
	node := func(args ...string) string {
		r := l.exec(N, append([]string{"node", "/root/p.js"}, args...), nil)
		return strings.TrimSpace(r.out + r.errOut)
	}
	got = map[string]string{
		"connect-allowed":  node("connect", "example.com", "443"),
		"get-allowed":      node("get", "example.com"),
		"connect-denied":   node("connect", "google.com", "443"),
		"get-denied":       node("get", "google.com"),
		"connect-ip":       node("connect", "1.1.1.1", "443"),
		"connect-metadata": node("connect", "169.254.169.254.nip.io", "80"),
		"connect-loopback": node("connect", "localtest.me", "22"),
		"direct":           node("direct", "1.1.1.1", "443"),
		"direct-gateway":   node("direct", gatewayIP, "22"),
	}
	l.check("net.allowlist.connect-allowed", strings.HasPrefix(got["connect-allowed"], "CONNECT 200 HTTP/1.1 200"), "%q", got["connect-allowed"])
	l.check("net.allowlist.http-allowed", got["get-allowed"] == "GET 200", "%q", got["get-allowed"])
	l.check("net.allowlist.connect-denied", got["connect-denied"] == "CONNECT 403", "%q", got["connect-denied"])
	l.check("net.allowlist.http-denied", got["get-denied"] == "GET 403", "%q", got["get-denied"])
	l.check("net.allowlist.ip-literal-denied", got["connect-ip"] == "CONNECT 403", "%q", got["connect-ip"])
	l.check("net.allowlist.private-resolve-refused", got["connect-metadata"] == "CONNECT 502" && got["connect-loopback"] == "CONNECT 502", "metadata=%q loopback=%q", got["connect-metadata"], got["connect-loopback"])
	l.check("net.allowlist.direct-denied", got["direct"] != "DIRECT connected" && got["direct-gateway"] != "DIRECT connected", "1.1.1.1=%q gateway:22=%q", got["direct"], got["direct-gateway"])
	r = l.exec(N, []string{"sh", "-c", "echo $HTTPS_PROXY; cat /etc/resolv.conf"}, nil)
	l.check("net.allowlist.env", strings.Contains(r.out, "http://10.88.0.1:3128"), "%q", r.out)
	r = l.exec(N, []string{"sh", "-c", "wget -q -T 8 -O - https://example.com 2>&1 | grep -o 'Example Domain' | head -1"}, nil)
	l.check("net.allowlist.busybox-wget-https", true, "informational (busybox wget and https_proxy): %q", strings.TrimSpace(r.out))

	// ---- M6: with Falak's managed firewall (input policy drop) the proxy is still reachable
	ruleset, err := netcfg.RenderRuleset(netcfg.FirewallPayload{})
	if err == nil {
		cmd := exec.Command("nft", "-f", "-")
		cmd.Stdin = strings.NewReader(ruleset)
		out, nerr := cmd.CombinedOutput()
		err = nerr
		if nerr != nil {
			t.Logf("nft: %s", out)
		}
	}
	m6 := node("connect", "example.com", "443")
	l.check("m6.proxy-through-managed-firewall", err == nil && strings.HasPrefix(m6, "CONNECT 200"), "err=%v %q", err, m6)
	sh("nft", "delete", "table", "inet", "falak")

	// ---- limits: CPU and disk
	L := newID()
	_, _, err = l.create(CreatePayload{ID: L, Image: "alpine:3.20", CPUs: 0.5, MemoryMB: 256, DiskMB: 256, Network: NetNone})
	l.check("limits.create", err == nil, "err=%v", err)
	cg := filepath.Join("/sys/fs/cgroup", cgroupPath(L))
	cgFiles := map[string]string{}
	for _, f := range []string{"cpu.max", "memory.max", "memory.swap.max", "pids.max"} {
		b, _ := os.ReadFile(filepath.Join(cg, f))
		cgFiles[f] = strings.TrimSpace(string(b))
	}
	l.check("limits.cgroup", cgFiles["cpu.max"] == "50000 100000" && cgFiles["memory.max"] == "268435456" && cgFiles["pids.max"] == "1024", "%s: %v", cg, cgFiles)
	_, cpu0 := l.s.usage(L)
	t0 := time.Now()
	_ = l.exec(L, []string{"sh", "-c", "timeout 10 sh -c 'while :; do :; done'"}, nil)
	_, cpu1 := l.s.usage(L)
	wall := time.Since(t0).Seconds()
	ratio := (cpu1 - cpu0) / wall
	l.check("limits.cpu-0.5", ratio > 0.4 && ratio < 0.6, "busy loop used %.2f CPUs (cpu %.2fs over %.2fs wall)", ratio, cpu1-cpu0, wall)
	r = l.sh(L, "dd if=/dev/zero of=/root/fill bs=1M count=400 2>&1 | tail -1; ls -l /root/fill | awk '{print $5}'; df -m / | tail -1 | awk '{print $2, $3}'")
	l.check("limits.disk-256", r.err == nil && strings.Contains(r.out, "\n256 256\n") && !strings.Contains(r.out, "419430400"), "%q err=%v", r.out, r.err)
	img := created[A].ImageDigest
	fsFiles, _ := filepath.Glob(filepath.Join(l.s.d.Root, "images", strings.TrimPrefix(img, "sha256:"), "rootfs", ".gvisor.filestore."+cid(L)+"*"))
	allFS := sh("sh", "-c", "find / -xdev -name '.gvisor.filestore*' 2>/dev/null | head; ls -la "+filepath.Join(l.s.d.Root, "images", "*", "rootfs", ".gvisor*")+" 2>&1 | head")
	l.check("limits.filestore-location", len(fsFiles) > 0, "glob=%v all=%q", fsFiles, allFS)
	l.kill(L)
	fsAfter, _ := filepath.Glob(filepath.Join(l.s.d.Root, "images", "*", "rootfs", ".gvisor.filestore."+cid(L)+"*"))
	l.check("limits.filestore-removed", len(fsAfter) == 0, "%v", fsAfter)

	// ---- limits: memory → OOM → inventory → reaper
	M := newID()
	_, _, err = l.create(CreatePayload{ID: M, Image: "alpine:3.20", MemoryMB: 128, Network: NetNone})
	l.check("oom.create", err == nil, "err=%v", err)
	r = l.exec(M, []string{"sh", "-c", "head -c 300000000 /dev/zero | tr '\\0' a | awk '{print length}'"}, func(p *ExecPayload) { p.TimeoutS = 60 })
	l.check("oom.exec-fails", r.err != nil || r.ExitCode != 0, "code=%d out=%q stderr=%.200q err=%v", r.ExitCode, r.out, r.errOut, r.err)
	var item InventoryItem
	for i := 0; i < 20; i++ {
		inv, err := l.s.Inventory(ctx, InventoryPayload{}, nil)
		if err == nil {
			for _, it := range inv.(InventoryResult).Sandboxes {
				if it.ID == M {
					item = it
				}
			}
		}
		if item.Status == "stopped" {
			break
		}
		time.Sleep(500 * time.Millisecond)
	}
	ev, _ := os.ReadFile(filepath.Join("/sys/fs/cgroup", cgroupPath(M), "memory.events"))
	l.check("oom.inventory", item.Status == "stopped" && item.OOMKilled, "item=%+v memory.events=%q runsc=%q", item, strings.ReplaceAll(string(ev), "\n", " "), sh("runsc", "--root=/run/falak-sbx", "list"))
	l.s.ReapOnce(ctx)
	rep := req.find(M)
	_, statErr = os.Stat(l.s.dir(M))
	l.check("oom.reaped", rep != nil && rep["reason"] == ReasonOOM && os.IsNotExist(statErr), "request=%v dir-removed=%v", rep, os.IsNotExist(statErr))
	hostOK := sh("sh", "-c", "uptime")
	l.check("oom.host-unaffected", hostOK != "", "%s", hostOK)

	// ---- extend
	before, _ := l.s.readState(A)
	later := time.Now().Add(3 * time.Hour).Unix()
	_, err = l.s.Extend(ctx, ExtendPayload{ID: A, ExpiresAt: later}, nil)
	after, _ := l.s.readState(A)
	r = l.sh(A, "cat /root/bin.dat | wc -c")
	l.check("extend", err == nil && after.ExpiresAt == later && after.StartedAt == before.StartedAt && after.IP == before.IP && strings.TrimSpace(r.out) == "10240",
		"err=%v expires %d→%d started %s→%s files-kept=%q", err, before.ExpiresAt, after.ExpiresAt, before.StartedAt, after.StartedAt, strings.TrimSpace(r.out))
	cr, _, err := l.create(CreatePayload{ID: A, Image: "alpine:3.20", ExpiresAt: later + 99})
	again, _ := l.s.readState(A)
	l.check("create.redelivered-running", err == nil && cr.StartedAt == before.StartedAt && again.ExpiresAt == later, "err=%v expires=%d", err, again.ExpiresAt)

	// ---- reaper: expired
	E := newID()
	_, _, err = l.create(CreatePayload{ID: E, Image: "alpine:3.20", ExpiresAt: time.Now().Add(-time.Second).Unix()})
	l.s.ReapOnce(ctx)
	rep = req.find(E)
	_, statErr = os.Stat(l.s.dir(E))
	l.check("reaper.expired", err == nil && rep != nil && rep["reason"] == ReasonExpired && os.IsNotExist(statErr) && !strings.Contains(sh("runsc", "--root=/run/falak-sbx", "list"), cid(E)),
		"create=%v request=%v dir-removed=%v", err, rep, os.IsNotExist(statErr))
	// The reaper with the control plane unreachable keeps the state dir (inventory: stopped) and retries.
	E2 := newID()
	_, _, _ = l.create(CreatePayload{ID: E2, Image: "alpine:3.20", Network: NetNone, ExpiresAt: time.Now().Add(-time.Second).Unix()})
	failing := New(Deps{Runner: runner.Exec{}, FS: hostfs.FS{}, Docker: docker.NewClient(""), Requests: failRequests{}, Root: "/var/lib/falak/sandboxes"})
	failing.ReapOnce(ctx)
	st, _ := l.s.readState(E2)
	l.check("reaper.cp-down-keeps-state", st != nil && st.Reaped == ReasonExpired, "state=%+v", st)
	l.s.ReapOnce(ctx)
	l.check("reaper.retry", req.find(E2) != nil, "%v", req.find(E2))

	// ---- kill
	stB, _ := l.s.readState(B)
	l.kill(B)
	addrs, _ := os.ReadFile(filepath.Join(l.s.d.Root, "addresses.json"))
	fsB, _ := filepath.Glob(filepath.Join(stB.Rootfs, ".gvisor.filestore."+cid(B)+"*"))
	_, dirErr := os.Stat(l.s.dir(B))
	_, cgErr := os.Stat(filepath.Join("/sys/fs/cgroup", cgroupPath(B)))
	gone := map[string]bool{
		"runsc":     !strings.Contains(sh("runsc", "--root=/run/falak-sbx", "list"), cid(B)),
		"netns":     !strings.Contains(sh("ip", "netns", "list"), netnsName(B)),
		"veth":      !strings.Contains(sh("ip", "link"), vethName(B)),
		"iptables":  !strings.Contains(sh("iptables-save"), ipB+"/32"),
		"filestore": len(fsB) == 0,
		"statedir":  os.IsNotExist(dirErr),
		"address":   !strings.Contains(string(addrs), ipB+`"`),
		"cgroup":    os.IsNotExist(cgErr),
	}
	allGone := true
	for _, v := range gone {
		allGone = allGone && v
	}
	l.check("kill.everything-gone", allGone, "%v", gone)
	_, err = l.s.Kill(ctx, KillPayload{ID: B}, nil)
	l.check("kill.idempotent", err == nil, "err=%v", err)

	// ---- agent restart
	ipt0 := iptablesSave()
	// A restart: the old agent's proxy listener is gone.
	l.s.netMu.Lock()
	if l.s.proxyLn != nil {
		l.s.proxyLn.Close()
		l.s.proxyLn = nil
	}
	l.s.netMu.Unlock()
	s2 := newLiveService(req)
	err = s2.ensureNetwork(ctx)
	ipt1 := iptablesSave()
	s2.netMu.Lock()
	if s2.proxyLn != nil {
		s2.proxyLn.Close()
		s2.proxyLn = nil
	}
	s2.netMu.Unlock()
	l.check("restart.rules-idempotent", err == nil && ipt0 == ipt1, "err=%v changed=%v", err, ipt0 != ipt1)
	l.check("restart.single-jumps", countLines(ipt1, "-A FORWARD -i br-sbx -j FALAK-SBX") == 1 && countLines(ipt1, "-A FORWARD -o br-sbx -j FALAK-SBX") == 1 &&
		countLines(ipt1, "-A INPUT -i br-sbx -j FALAK-SBX") == 1 && countLines(ipt1, "-A POSTROUTING -s 10.88.0.0/16 -j FALAK-SBX-NAT") == 1, "%s", sh("sh", "-c", "iptables-save | grep -e '-j FALAK'"))
	// A firewall reload flushed the chain and dropped a jump while the agent was down.
	sh("iptables", "-F", chain)
	sh("iptables", "-D", "FORWARD", "-i", bridge, "-j", chain)
	s3 := newLiveService(req)
	rctx, cancel := context.WithCancel(ctx)
	runDone := make(chan struct{})
	go func() { s3.Run(rctx); close(runDone) }()
	time.Sleep(3 * time.Second)
	cancel()
	<-runDone
	ipt2 := iptablesSave()
	l.check("restart.rules-restored", ipt2 == ipt1, "restored=%v\nbefore:\n%s\nafter:\n%s", ipt2 == ipt1, ipt1, ipt2)
	inv, err := s3.Inventory(ctx, InventoryPayload{}, nil)
	var invA InventoryItem
	if err == nil {
		for _, it := range inv.(InventoryResult).Sandboxes {
			if it.ID == A {
				invA = it
			}
		}
	}
	l.check("restart.inventory", invA.Status == "running" && invA.MemoryBytes > 0 && invA.CPUSeconds > 0, "%+v err=%v", invA, err)
	l.s = s3
	r = l.sh(A, "wget -q -T 10 -O - https://example.com | grep -c 'Example Domain'")
	l.check("restart.sandbox-still-works", strings.TrimSpace(r.out) == "1", "%q", r.out)
	n := l.exec(N, []string{"node", "/root/p.js", "connect", "example.com", "443"}, nil)
	l.check("restart.proxy-back", strings.HasPrefix(strings.TrimSpace(n.out), "CONNECT 200"), "%q", n.out)

	// ---- terminal
	m := pty.New(pty.Options{})
	ts := &liveStream{}
	type openRes struct {
		res any
		err error
	}
	done := make(chan openRes, 1)
	go func() {
		res, err := m.Open(ctx, pty.OpenPayload{SessionID: "live-term-1", Sandbox: &pty.SandboxTarget{ID: A}}, ts)
		done <- openRes{res, err}
	}()
	time.Sleep(1500 * time.Millisecond)
	_, _ = m.Input(pty.InputPayload{SessionID: "live-term-1", Data: base64.StdEncoding.EncodeToString([]byte("echo term-$((40+2)); [ -t 0 ] && echo isatty\n"))})
	time.Sleep(time.Second)
	_, _ = m.Input(pty.InputPayload{SessionID: "live-term-1", Data: base64.StdEncoding.EncodeToString([]byte("exit 3\n"))})
	var or openRes
	select {
	case or = <-done:
	case <-time.After(10 * time.Second):
		or.err = errors.New("session did not end after exit")
		m.Close(ctx, "live-term-1")
	}
	code := -999
	if r, ok := or.res.(pty.OpenResult); ok && r.ExitCode != nil {
		code = *r.ExitCode
	}
	l.check("terminal.shell", or.err == nil && strings.Contains(ts.String(), "term-42") && code == 3, "exit=%d err=%v output=%q", code, or.err, ts.String())
	ts2 := &liveStream{}
	go func() {
		res, err := m.Open(ctx, pty.OpenPayload{SessionID: "live-term-2", Sandbox: &pty.SandboxTarget{ID: A}}, ts2)
		done <- openRes{res, err}
	}()
	time.Sleep(1500 * time.Millisecond)
	_, _ = m.Input(pty.InputPayload{SessionID: "live-term-2", Data: base64.StdEncoding.EncodeToString([]byte("sleep 1000\n"))})
	time.Sleep(time.Second)
	m.Close(ctx, "live-term-2")
	select {
	case or = <-done:
	case <-time.After(10 * time.Second):
		or.err = errors.New("close did not end the session")
	}
	time.Sleep(time.Second)
	r = l.sh(A, "ps -o pid,args | grep -e 'sleep 1000' -e 'sh -l' | grep -v grep")
	l.check("terminal.close-ends-shell", or.err == nil && strings.TrimSpace(r.out) == "", "res=%+v err=%v left=%q", or.res, or.err, r.out)

	// ---- concurrency + idle memory
	for _, id := range []string{A, U, N, E2} {
		l.kill(id)
	}
	memBefore := sh("sh", "-c", "free -m | awk '/Mem/{print $3}'")
	ids := []string{newID(), newID(), newID(), newID(), newID()}
	var wg sync.WaitGroup
	errs := make([]error, len(ids))
	ips := make([]string, len(ids))
	start = time.Now()
	for i, id := range ids {
		wg.Add(1)
		go func() {
			defer wg.Done()
			res, _, err := l.create(CreatePayload{ID: id, Image: "alpine:3.20", MemoryMB: 256})
			errs[i], ips[i] = err, res.IP
		}()
	}
	wg.Wait()
	elapsed := time.Since(start)
	uniq := map[string]bool{}
	okAll := true
	for i := range ids {
		okAll = okAll && errs[i] == nil
		uniq[ips[i]] = true
	}
	l.check("concurrency.create-5", okAll && len(uniq) == 5, "%s errs=%v ips=%v", elapsed.Round(time.Millisecond), errs, ips)
	nets := 0
	for _, id := range ids {
		if strings.TrimSpace(l.sh(id, "wget -q -T 10 -O - https://example.com | grep -c 'Example Domain'").out) == "1" {
			nets++
		}
	}
	l.check("concurrency.internet-each", nets == 5, "%d/5", nets)
	time.Sleep(3 * time.Second)
	var mems []int64
	for _, id := range ids {
		mb, _ := l.s.usage(id)
		mems = append(mems, mb>>20)
	}
	memAfter := sh("sh", "-c", "free -m | awk '/Mem/{print $3}'")
	l.check("idle-memory", true, "cgroup memory.current MB per sandbox=%v; host used %s→%s MB", mems, memBefore, memAfter)
	ipt := sh("iptables-save")
	for _, id := range ids {
		l.kill(id)
	}
	left2 := l.s.states()
	iptAfter := sh("iptables-save")
	l.check("cleanup", len(left2) == 0 && perSandboxRules(iptAfter) == 0 && perSandboxRules(ipt) == 10 &&
		strings.TrimSpace(sh("sh", "-c", "ip netns list | grep -c falak-sbx")) == "0" && countLines(sh("nft", "list", "table", "bridge", nftTable), "saddr") == 0,
		"states=%d per-sandbox-rules before=%d after=%d", len(left2), perSandboxRules(ipt), perSandboxRules(iptAfter))

	// ---- M5: an image built on this server (secrets may be baked in) is refused
	_ = os.WriteFile("/tmp/falak-live.Dockerfile", []byte("FROM alpine:3.20\nENV SECRET=baked\n"), 0o644)
	sh("docker", "build", "-q", "-t", "falak-live-local:1", "-f", "/tmp/falak-live.Dockerfile", "/tmp")
	_, _, err = l.create(CreatePayload{ID: newID(), Image: "falak-live-local:1", Network: NetNone})
	l.check("m5.local-image-refused", err != nil && strings.Contains(err.Error(), "anyone can pull"), "err=%v", err)
	_, _, err = l.create(CreatePayload{ID: newID(), Image: "127.0.0.1:5000/x", Network: NetNone})
	l.check("m5.private-registry-refused", err != nil, "err=%v", err)
	sh("docker", "rmi", "falak-live-local:1")

	// ---- M7: unused base images go after 6 h (the directory's mtime is the last use), with the Docker image
	hex := strings.TrimPrefix(created[U].ImageDigest, "sha256:")
	dir := filepath.Join(l.s.d.Root, "images", hex)
	old := time.Now().Add(-7 * time.Hour)
	_ = os.Chtimes(dir, old, old)
	l.s.ReapOnce(ctx)
	_, statErr = os.Stat(dir)
	l.check("m7.image-gc", os.IsNotExist(statErr) && sh("docker", "images", "-q", "ubuntu:24.04") == "", "dir-removed=%v docker=%q", os.IsNotExist(statErr), sh("docker", "images", "-q", "ubuntu:24.04"))
	fresh, _ := os.ReadDir(filepath.Join(l.s.d.Root, "images"))
	l.check("m7.recent-images-kept", len(fresh) > 0, "%d left", len(fresh))
}

// ---- v0.21 (sandbox.v2): snapshots, restore and fork, pause/resume, database import

func (l *live) snapshot(id, snap string, leave bool) (SnapshotResult, time.Duration, error) {
	start := time.Now()
	res, err := l.s.Snapshot(l.ctx, SnapshotPayload{ID: id, SnapshotID: snap, LeaveRunning: &leave}, nil)
	if err != nil {
		return SnapshotResult{}, time.Since(start), err
	}
	return res.(SnapshotResult), time.Since(start), nil
}

// counter reads /tmp/c, the counter the background loop of a counting sandbox writes every second.
func (l *live) counter(id string) int {
	n := -1
	_, _ = fmt.Sscan(strings.TrimSpace(l.sh(id, "cat /tmp/c").out), &n)
	return n
}

const counterLoop = "falak-bg 'i=0; while :; do i=$((i+1)); echo $i > /tmp/c; sleep 1; done'"

// nodeServer serves {n, pid, ip, id}: a counter, its pid, its eth0 address and /app/id.
const nodeServer = `const http=require('http'),os=require('os'),fs=require('fs');let n=0;setInterval(()=>n++,1000);
http.createServer((q,r)=>{const ip=((os.networkInterfaces().eth0)||[]).filter(a=>a.family==='IPv4').map(a=>a.address).join(',');
r.end(JSON.stringify({n,pid:process.pid,ip,id:fs.readFileSync('/app/id','utf8').trim()}))}).listen(3000);`

type served struct {
	N   int    `json:"n"`
	PID int    `json:"pid"`
	IP  string `json:"ip"`
	ID  string `json:"id"`
}

func (l *live) serve(id string) (served, string) {
	r := l.sh(id, "wget -qO- -T 5 http://127.0.0.1:3000/")
	var s served
	_ = json.Unmarshal([]byte(r.out), &s)
	return s, strings.TrimSpace(r.out + r.errOut)
}

func freeBytes(path string) uint64 {
	f, _, _ := diskSpace(path)
	return f
}

// TestLiveV2 checks v0.21 against real runsc, Docker and a database container. LIVE_FALAK_DB is a linux build of
// cmd/falak-db (copied into a postgres:17 container named like an instance's, as the agent's db code expects).
func TestLiveV2(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	req := &captureRequests{}
	ctx := context.Background()
	l := &live{t: t, ctx: ctx, s: newLiveService(req), req: req}
	for _, st := range l.s.states() {
		l.kill(st.ID)
	}
	for _, m := range l.s.snapshots() {
		_, _ = l.s.SnapshotDelete(ctx, SnapshotDeletePayload{SnapshotID: m.SnapshotID}, nil)
	}
	if _, err := l.s.Prepare(ctx, PreparePayload{}, &liveStream{}); err != nil {
		t.Fatal(err)
	}
	t.Logf("runsc %s", sh("runsc", "--version"))

	// ---- snapshot with leave_running: the counter goes on in the source and in the restore
	A := newID()
	ca, _, err := l.create(CreatePayload{ID: A, Image: "alpine:3.20", Env: map[string]string{"FOO": "from-create"}})
	if !l.check("v2.create.A", err == nil, "ip=%s err=%v", ca.IP, err) {
		t.FailNow()
	}
	l.sh(A, "echo marker-A > /root/marker; head -c 20000000 /dev/urandom > /root/blob; "+counterLoop+"; nohup sleep 1000 </dev/null >/dev/null 2>&1 &")
	time.Sleep(3 * time.Second)
	S1 := newID()
	before := l.counter(A)
	s1, d, err := l.snapshot(A, S1, true)
	l.check("v2.snapshot.leave-running", err == nil && s1.SnapshotID == S1 && s1.SizeBytes > 20_000_000 && s1.ImageDigest == ca.ImageDigest && s1.CreatedAt != "",
		"%s %+v err=%v", d.Round(time.Millisecond), s1, err)
	fi, _ := os.Stat(l.s.snapDir(S1))
	mi, _ := os.Stat(filepath.Join(l.s.snapDir(S1), "meta.json"))
	l.check("v2.snapshot.root-only", fi != nil && fi.Mode().Perm() == 0o700 && mi != nil && mi.Mode().Perm() == 0o600 &&
		strings.Contains(sh("stat", "-c", "%a", filepath.Join(l.s.d.Root, "snapshots")), "700"), "dir=%v meta=%v", fi.Mode(), mi.Mode())
	l.check("v2.snapshot.files", true, "%s", sh("sh", "-c", "ls -la "+l.s.snapImage(S1)+" | tail -n +2"))
	again, _, err := l.snapshot(A, S1, true)
	l.check("v2.snapshot.idempotent", err == nil && again == s1, "%+v err=%v", again, err)
	time.Sleep(3 * time.Second)
	afterA := l.counter(A)
	l.check("v2.snapshot.source-continues", afterA >= before+3, "before=%d after=%d", before, afterA)

	R1 := newID()
	cr, d, err := l.create(CreatePayload{ID: R1, Image: "alpine:3.20", FromSnapshot: S1, Env: map[string]string{"FORK": "1"}})
	l.check("v2.restore", err == nil && cr.Status == "running" && cr.IP != "" && cr.IP != ca.IP && cr.ImageDigest == ca.ImageDigest,
		"%s ip=%s (source %s) err=%v", d.Round(time.Millisecond), cr.IP, ca.IP, err)
	c0 := l.counter(R1)
	time.Sleep(3 * time.Second)
	c1 := l.counter(R1)
	l.check("v2.restore.counter-continues", c0 >= before && c0 <= before+3 && c1 >= c0+2, "snapshot≈%d restored=%d after 3s=%d", before, c0, c1)
	ps := l.sh(R1, "ps -o args | grep -c '^sleep 1000'").out
	l.check("v2.restore.exec-processes-dropped", strings.TrimSpace(ps) == "0" && strings.TrimSpace(l.sh(A, "ps -o args | grep -c '^sleep 1000'").out) == "1",
		"exec-started sleep in restore: %q (gVisor kills processes descending from runsc exec on restore; falak-bg jobs survive)", ps)
	r := l.sh(R1, "cat /root/marker; wc -c < /root/blob; echo $FOO $FORK")
	l.check("v2.restore.files-and-env", r.out == "marker-A\n20000000\nfrom-create 1\n", "%q %q", r.out, r.errOut)
	r = l.sh(R1, "wget -q -T 8 -O /dev/null http://example.com && echo internet-ok")
	l.check("v2.restore.internet", strings.Contains(r.out, "internet-ok"), "%q %q", r.out, r.errOut)
	l.sh(R1, "echo changed > /root/marker")
	l.check("v2.restore.independent", l.sh(A, "cat /root/marker").out == "marker-A\n", "source sees %q", l.sh(A, "cat /root/marker").out)

	_, _, err = l.create(CreatePayload{ID: newID(), Image: "alpine:3.20", FromSnapshot: S1, MemoryMB: 1024})
	l.check("v2.restore.limit-mismatch", err != nil && strings.Contains(err.Error(), "must equal"), "err=%v", err)
	missing := newID()
	_, _, err = l.create(CreatePayload{ID: newID(), Image: "alpine:3.20", FromSnapshot: missing})
	l.check("v2.restore.unknown-snapshot", err != nil && err.Error() == "snapshot "+missing+" not found", "err=%v", err)
	_, err = l.s.Snapshot(ctx, SnapshotPayload{ID: newID(), SnapshotID: newID()}, nil)
	l.check("v2.snapshot.unknown-sandbox", err != nil && strings.Contains(err.Error(), "not found"), "err=%v", err)

	// ---- node server: snapshot, kill the source, fork x3; each serves on its own address
	N := newID()
	cn, _, err := l.create(CreatePayload{ID: N, Image: "node:22-alpine"})
	l.check("v2.create.node", err == nil, "ip=%s err=%v", cn.IP, err)
	_, err = l.s.WriteFile(ctx, WritePayload{ID: N, Path: "/app/server.js", Content: base64.StdEncoding.EncodeToString([]byte(nodeServer))}, nil)
	l.sh(N, "echo source > /app/id; cd /app && falak-bg 'node server.js > /app/log 2>&1'")
	var sv served
	var raw string
	for i := 0; i < 30; i++ {
		if sv, raw = l.serve(N); sv.PID != 0 {
			break
		}
		time.Sleep(500 * time.Millisecond)
	}
	l.check("v2.node.serves", err == nil && sv.PID != 0 && sv.IP == cn.IP, "%s", raw)
	S2 := newID()
	s2, d, err := l.snapshot(N, S2, true)
	l.check("v2.node.snapshot", err == nil, "%s size=%d MB err=%v", d.Round(time.Millisecond), s2.SizeBytes>>20, err)
	l.kill(N)
	leftover, _ := filepath.Glob(filepath.Join(l.s.d.Root, "images", "*", "rootfs", ".gvisor.filestore."+cid(N)+"*"))
	l.check("v2.node.source-gone", len(leftover) == 0 && l.s.states() != nil, "filestores left %v", leftover)
	forks := []string{newID(), newID(), newID()}
	start := time.Now()
	var wg sync.WaitGroup
	fres := make([]CreateResult, 3)
	ferr := make([]error, 3)
	for i, id := range forks {
		wg.Add(1)
		go func() {
			defer wg.Done()
			fres[i], _, ferr[i] = l.create(CreatePayload{ID: id, Image: "node:22-alpine", FromSnapshot: S2})
		}()
	}
	wg.Wait()
	l.check("v2.fork3", ferr[0] == nil && ferr[1] == nil && ferr[2] == nil, "%s errs=%v", time.Since(start).Round(time.Millisecond), ferr)
	ips := map[string]bool{} // the source's address was released by its kill: a fork may get it
	for i, id := range forks {
		l.sh(id, fmt.Sprintf("echo fork-%d > /app/id", i))
	}
	for i, id := range forks {
		s, raw := l.serve(id)
		ok := s.PID == sv.PID && s.N >= sv.N && s.IP == fres[i].IP && !ips[s.IP] && s.ID == fmt.Sprintf("fork-%d", i)
		ips[s.IP] = true
		l.check(fmt.Sprintf("v2.fork.%d.serves", i), ok, "%s (result ip %s)", raw, fres[i].IP)
		r := l.sh(id, "wget -q -T 8 -O /dev/null http://example.com && echo internet-ok")
		l.check(fmt.Sprintf("v2.fork.%d.internet", i), strings.Contains(r.out, "internet-ok"), "%q %q", r.out, r.errOut)
	}
	l.check("v2.fork.own-ips", len(ips) == 3, "%v", ips)

	// ---- leave_running false: checkpoint, then removed as by a kill; the snapshot restores
	B := newID()
	_, _, err = l.create(CreatePayload{ID: B, Image: "alpine:3.20"})
	l.sh(B, "echo marker-B > /root/marker; "+counterLoop)
	time.Sleep(2 * time.Second)
	S3 := newID()
	_, d, err = l.snapshot(B, S3, false)
	stB, _ := l.s.readState(B)
	l.check("v2.snapshot.stop", err == nil && stB == nil && !strings.Contains(sh("runsc", "--root="+l.s.d.RunscRoot, "list"), cid(B)) &&
		!strings.Contains(sh("ip", "netns", "list"), netnsName(B)), "%s err=%v state=%v", d.Round(time.Millisecond), err, stB)
	RB := newID()
	_, _, err = l.create(CreatePayload{ID: RB, Image: "alpine:3.20", FromSnapshot: S3})
	rb := l.sh(RB, "cat /root/marker /tmp/c; ps -o pid,args")
	l.check("v2.snapshot.stop.restores", err == nil && strings.HasPrefix(rb.out, "marker-B\n") && l.counter(RB) > 0, "err=%v %q %q", err, rb.out, rb.errOut)

	// ---- pause / resume: no CPU while paused; the reaper only expires paused sandboxes
	P := newID()
	_, _, err = l.create(CreatePayload{ID: P, Image: "alpine:3.20"})
	l.sh(P, "nohup sh -c 'while :; do :; done' </dev/null >/dev/null 2>&1 &")
	time.Sleep(2 * time.Second)
	res, err := l.s.Pause(ctx, IDPayload{ID: P}, nil)
	_, cpu0 := l.s.usage(P)
	time.Sleep(3 * time.Second)
	_, cpu1 := l.s.usage(P)
	l.check("v2.pause", err == nil && res.(StatusResult).Status == "paused" && cpu1-cpu0 < 0.05, "cpu while paused %.3fs in 3s err=%v", cpu1-cpu0, err)
	res, err = l.s.Pause(ctx, IDPayload{ID: P}, nil)
	l.check("v2.pause.idempotent", err == nil && res.(StatusResult).Status == "paused", "err=%v", err)
	inv, _ := l.s.Inventory(ctx, InventoryPayload{}, nil)
	pst := ""
	for _, it := range inv.(InventoryResult).Sandboxes {
		if it.ID == P {
			pst = it.Status
		}
	}
	l.check("v2.pause.inventory", pst == "paused", "status=%s", pst)
	_, err = l.s.Snapshot(ctx, SnapshotPayload{ID: P, SnapshotID: newID()}, nil)
	l.check("v2.snapshot.paused-refused", err != nil && strings.Contains(err.Error(), "paused"), "err=%v", err)
	l.s.ReapOnce(ctx)
	stP, _ := l.s.readState(P)
	l.check("v2.pause.survives-reaper", stP != nil && stP.Reaped == "", "state=%+v", stP)
	res, err = l.s.Resume(ctx, IDPayload{ID: P}, nil)
	_, cpu2 := l.s.usage(P)
	time.Sleep(2 * time.Second)
	_, cpu3 := l.s.usage(P)
	l.check("v2.resume", err == nil && res.(StatusResult).Status == "running" && cpu3-cpu2 > 1, "cpu after resume %.2fs in 2s err=%v", cpu3-cpu2, err)
	_, _ = l.s.Pause(ctx, IDPayload{ID: P}, nil)
	_, _ = l.s.Extend(ctx, ExtendPayload{ID: P, ExpiresAt: time.Now().Unix() - 1}, nil)
	l.s.ReapOnce(ctx)
	l.check("v2.pause.expires", req.find(P)["reason"] == "expired", "%v", req.find(P))
	_, err = l.s.Resume(ctx, IDPayload{ID: P}, nil)
	l.check("v2.resume.unknown", err != nil && err.Error() == "sandbox "+P+" not found", "err=%v", err)

	// ---- database import from a postgres:17 instance container (falak-db inside, as db.instance.create makes it)
	if bin := os.Getenv("LIVE_FALAK_DB"); bin != "" {
		inst := newID()
		ctr := db.Container(inst)
		sh("docker", "rm", "-f", ctr)
		sh("docker", "run", "-d", "--name", ctr, "-e", "POSTGRES_PASSWORD=live-only", "-e", "FALAK_DB_ENGINE=postgres", "postgres:17")
		sh("docker", "cp", bin, ctr+":/usr/local/bin/falak-db")
		for i := 0; i < 60 && sh("docker", "exec", ctr, "pg_isready", "-h", "/var/run/postgresql") != "/var/run/postgresql:5432 - accepting connections"; i++ {
			time.Sleep(time.Second)
		}
		time.Sleep(2 * time.Second)
		sh("docker", "exec", ctr, "psql", "-U", "postgres", "-c", "CREATE DATABASE shop")
		sh("docker", "exec", ctr, "psql", "-U", "postgres", "-d", "shop", "-c", "CREATE TABLE orders(id int, note text); INSERT INTO orders SELECT g, md5(g::text) FROM generate_series(1,50000) g")
		D := newID()
		_, _, err := l.create(CreatePayload{ID: D, Image: "postgres:17", Network: NetNone})
		l.check("v2.db.sandbox", err == nil, "err=%v", err)
		free0 := freeBytes(l.s.d.Root)
		start := time.Now()
		res, err := l.s.DBImport(ctx, DBImportPayload{ID: D, Instance: inst, Engine: "postgres", Database: "shop", Path: "/workspace/db/shop.dump"}, nil)
		var ir DBImportResult
		if err == nil {
			ir = res.(DBImportResult)
		}
		r := l.sh(D, "head -c 5 /workspace/db/shop.dump; echo; stat -c %s /workspace/db/shop.dump; pg_restore -l /workspace/db/shop.dump | grep -c 'TABLE DATA public orders'")
		l.check("v2.db.import", err == nil && ir.Path == "/workspace/db/shop.dump" && ir.SizeBytes > 100000 && r.out == fmt.Sprintf("PGDMP\n%d\n1\n", ir.SizeBytes),
			"%s %+v %q %q err=%v (host free %d→%d MB)", time.Since(start).Round(time.Millisecond), ir, r.out, r.errOut, err, free0>>20, freeBytes(l.s.d.Root)>>20)
		r = l.sh(D, "mkdir -p /tmp/pg && chown postgres /tmp/pg && su postgres -c 'initdb -D /tmp/pg >/dev/null && pg_ctl -D /tmp/pg -o \"-k /tmp\" -w start >/dev/null && createdb -h /tmp shop && pg_restore -h /tmp -d shop /workspace/db/shop.dump && psql -h /tmp -d shop -tAc \"select count(*) from orders\"'")
		l.check("v2.db.restores-in-sandbox", strings.TrimSpace(r.out) == "50000", "%q %q", r.out, r.errOut)
		_, err = l.s.DBImport(ctx, DBImportPayload{ID: D, Instance: inst, Engine: "postgres", Database: "nosuchdb", Path: "/workspace/db/nosuchdb.dump"}, nil)
		r = l.sh(D, "ls /workspace/db")
		l.check("v2.db.failed-import-leaves-nothing", err != nil && r.out == "shop.dump\n", "err=%v ls=%q", err, r.out)
		l.kill(D)
		sh("docker", "rm", "-f", ctr)
	} else {
		l.check("v2.db.import", false, "LIVE_FALAK_DB not set")
	}

	// ---- agent restart: snapshots are kept and restore from a new service
	l2 := &live{t: t, ctx: ctx, s: newLiveService(req), req: req}
	inv, err = l2.s.Inventory(ctx, InventoryPayload{}, nil)
	got := map[string]int64{}
	for _, sn := range inv.(InventoryResult).Snapshots {
		got[sn.SnapshotID] = sn.SizeBytes
	}
	l.check("v2.restart.inventory", err == nil && got[S1] == s1.SizeBytes && got[S2] > 0 && got[S3] > 0, "%v err=%v", got, err)
	R2 := newID()
	_, _, err = l2.create(CreatePayload{ID: R2, Image: "alpine:3.20", FromSnapshot: S1})
	l.check("v2.restart.restores", err == nil && l2.sh(R2, "cat /root/marker").out == "marker-A\n", "err=%v", err)

	// ---- snapshot delete frees its disk; restored sandboxes don't need it
	free0 := freeBytes(l.s.d.Root)
	_, err = l.s.SnapshotDelete(ctx, SnapshotDeletePayload{SnapshotID: S2}, nil)
	free1 := freeBytes(l.s.d.Root)
	_, statErr := os.Stat(l.s.snapDir(S2))
	s, raw := l.serve(forks[0])
	l.check("v2.snapshot.delete", err == nil && os.IsNotExist(statErr) && int64(free1-free0) > s2.SizeBytes/2 && s.PID != 0,
		"freed %d MB (snapshot %d MB); fork still serves %s", int64(free1-free0)>>20, s2.SizeBytes>>20, raw)
	_, err = l.s.SnapshotDelete(ctx, SnapshotDeletePayload{SnapshotID: S2}, nil)
	l.check("v2.snapshot.delete.idempotent", err == nil, "err=%v", err)

	// ---- kill with snapshots present: sandboxes go, snapshots stay
	for _, st := range l.s.states() {
		l.kill(st.ID)
	}
	l.check("v2.kill.cleanup", len(l.s.states()) == 0 && len(l.s.snapshots()) == 2 && strings.TrimSpace(sh("sh", "-c", "ip netns list | grep -c falak-sbx")) == "0" &&
		perSandboxRules(sh("iptables-save")) == 0 && !strings.Contains(sh("runsc", "--root="+l.s.d.RunscRoot, "list"), "falak-sbx-"),
		"states=%d snapshots=%d", len(l.s.states()), len(l.s.snapshots()))

	// ---- the image GC keeps a snapshot's base image, and removes it once the last snapshot is gone
	m1, _ := l.s.readMeta(S1)
	imgDir := filepath.Dir(m1.Rootfs)
	old := time.Now().Add(-7 * time.Hour)
	_ = os.Chtimes(imgDir, old, old)
	l.s.ReapOnce(ctx)
	_, statErr = os.Stat(imgDir)
	l.check("v2.gc.keeps-snapshot-image", statErr == nil, "err=%v", statErr)
	for _, sn := range []string{S1, S3} {
		_, _ = l.s.SnapshotDelete(ctx, SnapshotDeletePayload{SnapshotID: sn}, nil)
	}
	_ = os.Chtimes(imgDir, old, old)
	l.s.ReapOnce(ctx)
	_, statErr = os.Stat(imgDir)
	l.check("v2.gc.after-snapshots-deleted", os.IsNotExist(statErr), "err=%v", statErr)
}

// TestLiveRestoreDebug snapshots a counting sandbox, restores it and prints what runs in both (LIVE_DEBUG=1); both
// are left running for poking at by hand.
func TestLiveRestoreDebug(t *testing.T) {
	if os.Getenv("LIVE_DEBUG") == "" {
		t.Skip("LIVE_DEBUG not set")
	}
	l := &live{t: t, ctx: context.Background(), s: newLiveService(&captureRequests{})}
	A, R, S := newID(), newID(), newID()
	_, _, err := l.create(CreatePayload{ID: A, Image: "alpine:3.20"})
	t.Logf("A=%s err=%v", A, err)
	l.sh(A, counterLoop)
	time.Sleep(2 * time.Second)
	_, _, err = l.snapshot(A, S, true)
	t.Logf("S=%s err=%v", S, err)
	_, _, err = l.create(CreatePayload{ID: R, Image: "alpine:3.20", FromSnapshot: S})
	t.Logf("R=%s err=%v", R, err)
	time.Sleep(3 * time.Second)
	for _, id := range []string{A, R} {
		t.Logf("%s ps:\n%s", id, l.sh(id, "ps -o pid,stat,time,args; cat /tmp/c").out)
	}
	t.Logf("R runsc.log tail:\n%s", sh("sh", "-c", "tail -c 3000 "+filepath.Join(l.s.dir(R), "runsc.log")))
}

type failRequests struct{}

func (failRequests) Request(context.Context, string, any, any) error {
	return errors.New("control plane unreachable")
}

// TestLiveKeep creates one sandbox and leaves it running (for poking at by hand): LIVE_KEEP=1, LIVE_DISK_MB.
func TestLiveKeep(t *testing.T) {
	if os.Getenv("LIVE_KEEP") == "" {
		t.Skip("LIVE_KEEP not set")
	}
	l := &live{t: t, ctx: context.Background(), s: newLiveService(&captureRequests{})}
	disk := 1024
	_, _ = fmt.Sscan(os.Getenv("LIVE_DISK_MB"), &disk)
	id := newID()
	res, _, err := l.create(CreatePayload{ID: id, Image: "alpine:3.20", DiskMB: disk})
	t.Logf("KEEP id=%s cid=%s res=%+v err=%v", id, cid(id), res, err)
}

// TestLiveTerm opens a terminal session in sandbox LIVE_ID and prints what it saw.
func TestLiveTerm(t *testing.T) {
	id := os.Getenv("LIVE_ID")
	if id == "" {
		t.Skip("LIVE_ID not set")
	}
	m := pty.New(pty.Options{})
	ts := &liveStream{}
	done := make(chan any, 1)
	go func() {
		res, err := m.Open(context.Background(), pty.OpenPayload{SessionID: "live-term-x", Sandbox: &pty.SandboxTarget{ID: id}}, ts)
		if r, ok := res.(pty.OpenResult); ok && r.ExitCode != nil {
			t.Logf("exit code %d reason %s", *r.ExitCode, r.Reason)
		}
		done <- err
	}()
	time.Sleep(1500 * time.Millisecond)
	_, err := m.Input(pty.InputPayload{SessionID: "live-term-x", Data: base64.StdEncoding.EncodeToString([]byte("echo term-$((40+2))\nexit 3\n"))})
	t.Logf("input err=%v", err)
	select {
	case err := <-done:
		t.Logf("open err=%v", err)
	case <-time.After(10 * time.Second):
		t.Logf("no exit")
	}
	t.Logf("output=%q", ts.String())
}
