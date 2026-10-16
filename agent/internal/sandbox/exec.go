package sandbox

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync/atomic"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// execReq is one process run inside a sandbox with `runsc exec`.
type execReq struct {
	argv    []string
	cwd     string
	env     []string // added to the sandbox's env (later wins)
	stdin   []byte
	timeout time.Duration
	max     int // bytes kept per stream
}

type execOut struct {
	code           int
	stdout, stderr []byte
	truncated      bool
	timedOut       bool
	duration       time.Duration
}

// capWriter keeps the first max bytes written and notes whether there were more.
type capWriter struct {
	max  int
	buf  bytes.Buffer
	over bool
}

func (c *capWriter) Write(p []byte) (int, error) {
	if room := c.max - c.buf.Len(); len(p) > room {
		c.over = true
		if room > 0 {
			c.buf.Write(p[:room])
		}
		return len(p), nil
	}
	c.buf.Write(p)
	return len(p), nil
}

var execSeq atomic.Uint64

// execProcess is the process.json `runsc exec --process` runs: argv, the sandbox's env (from its bundle) with env on
// top (deduplicated: runsc's --env flags would only be appended to the spec's), the cwd (default /root), as root.
// Capabilities are left out, so runsc uses the sandbox's.
func (s *Service) execProcess(id string, r execReq) (ociProcess, error) {
	var spec ociSpec
	b, err := os.ReadFile(filepath.Join(s.dir(id), "bundle", "config.json"))
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return ociProcess{}, fmt.Errorf("sandbox %s not found", id)
		}
		return ociProcess{}, err
	}
	if err := json.Unmarshal(b, &spec); err != nil {
		return ociProcess{}, err
	}
	cwd := r.cwd
	if cwd == "" {
		cwd = "/root"
	}
	return ociProcess{Args: r.argv, Env: dedupEnv(append(spec.Process.Env, r.env...)), Cwd: cwd}, nil
}

// execIn runs r in the sandbox. The process file (it may hold secret env values) and the file its in-sandbox pid is
// written to are removed afterwards; a timeout kills the process inside the sandbox (runsc kill --pid) as well as
// runsc exec, and reports exit code -1.
func (s *Service) execIn(ctx context.Context, id string, r execReq) (execOut, error) {
	base := filepath.Join(s.dir(id), fmt.Sprintf("exec-%d", execSeq.Add(1)))
	procFile, pidFile := base+".json", base+".pid"
	defer os.Remove(procFile)
	defer os.Remove(pidFile)
	proc, err := s.execProcess(id, r)
	if err != nil {
		return execOut{}, err
	}
	b, _ := json.Marshal(proc)
	if err := os.WriteFile(procFile, b, 0o600); err != nil {
		return execOut{}, err
	}
	args := s.runscArgs("exec", "--process="+procFile, "--internal-pid-file="+pidFile, cid(id))
	out, errw := &capWriter{max: r.max}, &capWriter{max: r.max}
	cmd := runner.Cmd{Name: "runsc", Args: args, Stdout: out, Stderr: errw}
	if r.stdin != nil {
		cmd.Stdin = bytes.NewReader(r.stdin)
	}
	tctx, cancel := context.WithTimeout(ctx, r.timeout)
	defer cancel()
	start := time.Now()
	res, err := s.d.Runner.Run(tctx, cmd)
	o := execOut{code: res.ExitCode, stdout: out.buf.Bytes(), stderr: errw.buf.Bytes(), truncated: out.over || errw.over,
		duration: time.Since(start)}
	if err != nil {
		if tctx.Err() == nil || ctx.Err() != nil {
			return o, err
		}
		o.timedOut, o.code = true, -1
		if b, rerr := os.ReadFile(pidFile); rerr == nil {
			if pid, perr := strconv.Atoi(strings.TrimSpace(string(b))); perr == nil {
				kctx, kcancel := context.WithTimeout(context.WithoutCancel(ctx), 10*time.Second)
				_, _ = s.d.Runner.Run(kctx, runner.Cmd{Name: "runsc", Args: s.runscArgs("kill", "--pid="+strconv.Itoa(pid), cid(id), "9")})
				kcancel()
			}
		}
	}
	return o, nil
}

// ---- sandbox.exec

// ExecPayload is sandbox.exec's payload.
type ExecPayload struct {
	ID        string            `json:"id"`
	Cmd       []string          `json:"cmd"`
	Cwd       string            `json:"cwd,omitempty"`
	Env       map[string]string `json:"env,omitempty"`
	Stdin     string            `json:"stdin,omitempty"`
	TimeoutS  int               `json:"timeout_s"`
	MaxOutput int               `json:"max_output,omitempty"`
}

// Secrets keeps env values out of the command's output and errors.
func (p ExecPayload) Secrets() []string {
	var out []string
	for _, v := range p.Env {
		if v != "" {
			out = append(out, v)
		}
	}
	return out
}

// ExecResult is sandbox.exec's result.
type ExecResult struct {
	ExitCode   int    `json:"exit_code"`
	Stdout     string `json:"stdout"`
	Stderr     string `json:"stderr"`
	Truncated  bool   `json:"truncated"`
	DurationMS int64  `json:"duration_ms"`
	TimedOut   bool   `json:"timed_out"`
}

const (
	maxOutput = 262144
	maxStdin  = 1 << 20
	maxFile   = 5 << 20
)

// Exec runs a command in a sandbox.
func (s *Service) Exec(ctx context.Context, p ExecPayload, _ commands.Stream) (any, error) {
	if err := checkID(p.ID); err != nil {
		return nil, err
	}
	switch {
	case len(p.Cmd) == 0:
		return nil, payloadErr("cmd is empty")
	case p.TimeoutS < 1 || p.TimeoutS > 120:
		return nil, payloadErr("timeout_s must be 1–120")
	case p.MaxOutput < 0 || p.MaxOutput > maxOutput:
		return nil, payloadErr("max_output must be at most %d", maxOutput)
	case p.Cwd != "" && checkPath(p.Cwd) != nil:
		return nil, payloadErr("cwd must be an absolute path")
	}
	for k := range p.Env {
		if !envKeyRe.MatchString(k) {
			return nil, payloadErr("invalid env name %q", k)
		}
	}
	for _, a := range p.Cmd {
		if strings.ContainsRune(a, 0) {
			return nil, payloadErr("cmd contains a NUL byte")
		}
	}
	if p.MaxOutput == 0 {
		p.MaxOutput = maxOutput
	}
	r := execReq{argv: p.Cmd, cwd: p.Cwd, timeout: time.Duration(p.TimeoutS) * time.Second, max: p.MaxOutput}
	if p.Stdin != "" {
		b, err := base64.StdEncoding.DecodeString(p.Stdin)
		if err != nil || len(b) > maxStdin {
			return nil, payloadErr("stdin must be base64, at most 1 MB decoded")
		}
		r.stdin = b
	}
	for _, k := range sortedKeys(p.Env) {
		r.env = append(r.env, k+"="+p.Env[k])
	}
	o, err := s.execIn(ctx, p.ID, r)
	if err != nil {
		return nil, err
	}
	return ExecResult{ExitCode: o.code, Stdout: base64.StdEncoding.EncodeToString(o.stdout), Stderr: base64.StdEncoding.EncodeToString(o.stderr),
		Truncated: o.truncated, DurationMS: o.duration.Milliseconds(), TimedOut: o.timedOut}, nil
}

// ---- sandbox.files.*

// checkPath accepts absolute paths without NUL bytes.
func checkPath(p string) error {
	if !strings.HasPrefix(p, "/") || strings.ContainsRune(p, 0) || len(p) > 4096 {
		return payloadErr("path must be absolute (and without NUL bytes)")
	}
	return nil
}

// Exit codes of the file scripts, mapped to the contract's errors.
const (
	exitNotFound    = 44
	exitIsDirectory = 45
	exitNotDir      = 46
)

var (
	ErrNotFound     = errors.New("not_found")
	ErrIsDirectory  = errors.New("is_directory")
	ErrNotDirectory = errors.New("not_directory")
)

// fileExec runs a file script (sh -c script _ args...) and maps its exit codes.
func (s *Service) fileExec(ctx context.Context, id, script string, stdin []byte, max int, args ...string) ([]byte, error) {
	o, err := s.execIn(ctx, id, execReq{argv: append([]string{"sh", "-c", script, "_"}, args...), stdin: stdin, timeout: time.Minute, max: max})
	if err != nil {
		return nil, err
	}
	switch {
	case o.timedOut:
		return nil, errors.New("timed out")
	case o.code == exitNotFound:
		return nil, ErrNotFound
	case o.code == exitIsDirectory:
		return nil, ErrIsDirectory
	case o.code == exitNotDir:
		return nil, ErrNotDirectory
	case o.code != 0:
		return nil, fmt.Errorf("exit status %d: %s", o.code, strings.TrimSpace(string(o.stderr)))
	}
	return o.stdout, nil
}

// readScript prints "<size> <octal mode>" then up to $2 bytes of the file. stat -c works in coreutils and busybox.
const readScript = `[ -e "$1" ] || exit 44
[ -d "$1" ] && exit 45
s=$(stat -L -c '%s %a' -- "$1") || exit 1
printf '%s\n' "$s"
exec head -c "$2" -- "$1"`

// ReadPayload is sandbox.files.read's payload.
type ReadPayload struct {
	ID       string `json:"id"`
	Path     string `json:"path"`
	MaxBytes int    `json:"max_bytes"`
}

// ReadResult is sandbox.files.read's result.
type ReadResult struct {
	Content   string `json:"content"`
	Size      int64  `json:"size"`
	Truncated bool   `json:"truncated"`
	Mode      string `json:"mode,omitempty"`
}

// ReadFile reads a file inside the sandbox (errors not_found, is_directory).
func (s *Service) ReadFile(ctx context.Context, p ReadPayload, _ commands.Stream) (any, error) {
	if err := checkID(p.ID); err != nil {
		return nil, err
	}
	if err := checkPath(p.Path); err != nil {
		return nil, err
	}
	if p.MaxBytes < 1 || p.MaxBytes > maxFile {
		return nil, payloadErr("max_bytes must be 1–%d", maxFile)
	}
	out, err := s.fileExec(ctx, p.ID, readScript, nil, p.MaxBytes+64, p.Path, strconv.Itoa(p.MaxBytes))
	if err != nil {
		return nil, err
	}
	return parseRead(out)
}

func parseRead(out []byte) (ReadResult, error) {
	head, content, ok := bytes.Cut(out, []byte("\n"))
	var size int64
	var mode string
	if !ok {
		return ReadResult{}, errors.New("unexpected output from the sandbox")
	}
	if _, err := fmt.Sscan(string(head), &size, &mode); err != nil {
		return ReadResult{}, fmt.Errorf("unexpected output from the sandbox: %q", head)
	}
	return ReadResult{Content: base64.StdEncoding.EncodeToString(content), Size: size, Truncated: int64(len(content)) < size,
		Mode: fmt.Sprintf("%04s", mode)}, nil
}

// listScript prints one "<kind>/<size>/<octal mode>/<mtime>/<path>" line per entry (%F never contains a slash, so the
// path is everything after the fourth). At most 1001 lines: the caller keeps 1000 and reports truncated.
const listScript = `[ -e "$1" ] || exit 44
[ -d "$1" ] || exit 46
cd -- "$1" || exit 1
for f in * .[!.]* ..?*; do
  if [ -e "$f" ] || [ -L "$f" ]; then stat -c '%F/%s/%a/%Y/%n' -- "$f"; fi
done | head -n 1001`

// ListPayload is sandbox.files.list's payload.
type ListPayload struct {
	ID   string `json:"id"`
	Path string `json:"path"`
}

// Entry is one directory entry.
type Entry struct {
	Name  string `json:"name"`
	Type  string `json:"type"`
	Size  int64  `json:"size"`
	Mode  string `json:"mode"`
	MTime int64  `json:"mtime"`
}

// ListResult is sandbox.files.list's result.
type ListResult struct {
	Entries   []Entry `json:"entries"`
	Truncated bool    `json:"truncated"`
}

// ListFiles lists a directory inside the sandbox (errors not_found, not_directory).
func (s *Service) ListFiles(ctx context.Context, p ListPayload, _ commands.Stream) (any, error) {
	if err := checkID(p.ID); err != nil {
		return nil, err
	}
	if err := checkPath(p.Path); err != nil {
		return nil, err
	}
	out, err := s.fileExec(ctx, p.ID, listScript, nil, 4<<20, p.Path)
	if err != nil {
		return nil, err
	}
	return parseList(out), nil
}

func parseList(out []byte) ListResult {
	res := ListResult{Entries: []Entry{}}
	for _, line := range strings.Split(string(out), "\n") {
		f := strings.SplitN(line, "/", 5)
		if len(f) != 5 || f[4] == "" {
			continue // an empty line, or a name with a newline in it
		}
		if len(res.Entries) == 1000 {
			res.Truncated = true
			break
		}
		e := Entry{Name: f[4], Mode: fmt.Sprintf("%04s", f[2])}
		e.Size, _ = strconv.ParseInt(f[1], 10, 64)
		e.MTime, _ = strconv.ParseInt(f[3], 10, 64)
		switch f[0] {
		case "regular file", "regular empty file":
			e.Type = "file"
		case "directory":
			e.Type = "dir"
		case "symbolic link":
			e.Type = "link"
		default:
			e.Type = "other"
		}
		res.Entries = append(res.Entries, e)
	}
	return res
}

// WritePayload is sandbox.files.write's payload.
type WritePayload struct {
	ID      string `json:"id"`
	Path    string `json:"path"`
	Content string `json:"content"`
	Mode    string `json:"mode,omitempty"`
	Mkdirs  *bool  `json:"mkdirs,omitempty"`
}

// WriteResult is sandbox.files.write's result.
type WriteResult struct {
	Size int `json:"size"`
}

const (
	writeScript      = `cat > "$1" && chmod "$2" "$1"`
	writeMkdirScript = `mkdir -p "$(dirname "$1")" && cat > "$1" && chmod "$2" "$1"`
)

var modeRe = regexp.MustCompile(`^0?[0-7]{3,4}$`)

// WriteFile writes a file inside the sandbox (stdin into cat), creating its directory unless mkdirs is false.
func (s *Service) WriteFile(ctx context.Context, p WritePayload, _ commands.Stream) (any, error) {
	if err := checkID(p.ID); err != nil {
		return nil, err
	}
	if err := checkPath(p.Path); err != nil {
		return nil, err
	}
	if p.Mode == "" {
		p.Mode = "0644"
	}
	if !modeRe.MatchString(p.Mode) {
		return nil, payloadErr("invalid mode %q", p.Mode)
	}
	b, err := base64.StdEncoding.DecodeString(p.Content)
	if err != nil || len(b) > maxFile {
		return nil, payloadErr("content must be base64, at most 5 MB decoded")
	}
	script := writeMkdirScript
	if p.Mkdirs != nil && !*p.Mkdirs {
		script = writeScript
	}
	if _, err := s.fileExec(ctx, p.ID, script, b, 64<<10, p.Path, p.Mode); err != nil {
		return nil, err
	}
	return WriteResult{Size: len(b)}, nil
}

// DeletePayload is sandbox.files.delete's payload.
type DeletePayload struct {
	ID        string `json:"id"`
	Path      string `json:"path"`
	Recursive bool   `json:"recursive,omitempty"`
}

// DeleteFile removes a file, or a directory with recursive (error not_found).
func (s *Service) DeleteFile(ctx context.Context, p DeletePayload, _ commands.Stream) (any, error) {
	if err := checkID(p.ID); err != nil {
		return nil, err
	}
	if err := checkPath(p.Path); err != nil {
		return nil, err
	}
	rm := `rm -f -- "$1"`
	if p.Recursive {
		rm = `rm -rf -- "$1"`
	}
	if _, err := s.fileExec(ctx, p.ID, `{ [ -e "$1" ] || [ -L "$1" ]; } || exit 44
`+rm, nil, 64<<10, p.Path); err != nil {
		return nil, err
	}
	return struct{}{}, nil
}
