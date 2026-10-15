package nettool

import (
	"bufio"
	"context"
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"golang.org/x/sys/unix"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/transport"
)

// CaptureControlPlane is what a capture needs from the control plane (*transport.Client): the progress request (its
// reply says whether the user cancelled) and the chunk upload.
type CaptureControlPlane interface {
	Request(ctx context.Context, typ string, in, out any) error
	Upload(ctx context.Context, path string, body []byte, headers map[string]string) error
}

// CaptureResult is net.capture's result.
type CaptureResult struct {
	CaptureID  string `json:"capture_id"`
	Bytes      int64  `json:"bytes"`
	Packets    int64  `json:"packets"`
	SHA256     string `json:"sha256"`
	Ended      string `json:"ended"`
	Interface  string `json:"interface"`
	Namespace  string `json:"namespace"`
	Expression string `json:"expression"`
}

// Why a capture ended.
const (
	capEndDuration  = "duration"
	capEndSizeLimit = "size_limit"
	capEndCancelled = "cancelled"
	capEndExited    = "exited"
)

const (
	captureChunk      = 4 << 20
	captureStaleAfter = 30 * time.Minute
	captureStderrMax  = 16 << 10
	pcapGlobalHeader  = 24
	pcapRecordHeader  = 16
)

var ifaceNameRe = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_.:@-]{0,14}$`)

// RegisterCapture adds net.capture (it needs Options.CP).
func (m *Manager) RegisterCapture(reg *commands.Registry) {
	reg.Register("net.capture", commands.Typed(m.Capture))
}

func (m *Manager) captureDefaults() {
	o := &m.opts
	if o.CaptureDir == "" {
		o.CaptureDir = "/var/lib/falak/captures"
	}
	if o.SysClassNet == "" {
		o.SysClassNet = "/sys/class/net"
	}
	if o.ProgressEvery <= 0 {
		o.ProgressEvery = 5 * time.Second
	}
	if o.MinFreeBytes == 0 {
		o.MinFreeBytes = 256 << 20
	}
	if o.MaxCaptures <= 0 {
		o.MaxCaptures = 2
	}
	if o.ChunkBytes <= 0 {
		o.ChunkBytes = captureChunk
	}
	m.capSem = make(chan struct{}, o.MaxCaptures)
}

// Capture runs tcpdump on an interface of the host or in a container's network namespace, keeps whole pcap records
// up to max_bytes in a root-only temp directory, reports progress (and learns about a cancel) every few seconds, then
// uploads the file in chunks and removes it.
func (m *Manager) Capture(ctx context.Context, p CaptureSpec, st commands.Stream) (any, error) {
	if err := p.Validate(); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	if m.opts.CP == nil {
		return nil, errors.New("packet capture needs the control plane connection")
	}
	select {
	case m.capSem <- struct{}{}:
		defer func() { <-m.capSem }()
	default:
		return nil, fmt.Errorf("too many captures running (max %d)", m.opts.MaxCaptures)
	}
	bin, err := m.tcpdump(ctx, st)
	if err != nil {
		return nil, err
	}
	dir, err := m.captureTempDir(p.MaxBytes)
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(dir)

	res := CaptureResult{CaptureID: p.CaptureID, Expression: p.Filter.Expression(), Namespace: "host"}
	var argv []string
	var netns *os.File
	if p.Container != nil {
		pin, err := m.pin(ctx, p.Container)
		if err != nil {
			return nil, err
		}
		defer pin.netns.Close()
		netns, res.Interface, res.Namespace = pin.netns, captureIfaceAny, "container:"+pin.ct.name()
		argv = append([]string{pin.nsenter, "--net=/proc/self/fd/3", "--"}, tcpdumpArgv(bin, captureIfaceAny, p, dropUser())...)
	} else {
		if res.Interface, err = m.resolveInterface(p.Interface); err != nil {
			return nil, err
		}
		argv = tcpdumpArgv(bin, res.Interface, p, dropUser())
	}

	path := filepath.Join(dir, "capture.pcap")
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return nil, fmt.Errorf("create the capture file: %w", err)
	}
	defer f.Close()
	m.log.Info("net capture", "capture", p.CaptureID, "interface", res.Interface, "namespace", res.Namespace, "duration_s", p.DurationS)
	fmt.Fprintf(st.Stdout(), "capturing on %s (%s) for %ds, at most %s, snap length %d bytes", res.Interface, res.Namespace, p.DurationS, humanBytes(p.MaxBytes), p.Snaplen)
	if res.Expression != "" {
		fmt.Fprintf(st.Stdout(), ", filter %q", res.Expression)
	}
	fmt.Fprintln(st.Stdout())

	h := sha256.New()
	var counts pcapCounts
	ended, err := m.runCapture(ctx, p, argv, netns, io.MultiWriter(f, h), &counts, st)
	if err != nil {
		return nil, err
	}
	if err := f.Close(); err != nil {
		return nil, fmt.Errorf("write the capture file: %w", err)
	}
	res.Ended, res.Bytes, res.Packets, res.SHA256 = ended, counts.bytes.Load(), counts.packets.Load(), hex.EncodeToString(h.Sum(nil))
	if res.Bytes == 0 {
		return nil, errors.New("tcpdump produced no capture")
	}
	fmt.Fprintf(st.Stdout(), "captured %d packets, %s (%s); uploading\n", res.Packets, humanBytes(res.Bytes), endLabel(ended))
	if err := m.uploadCapture(ctx, p.CaptureID, path, res.Bytes, res.SHA256); err != nil {
		return nil, err
	}
	fmt.Fprintln(st.Stdout(), "uploaded")
	return res, nil
}

// runCapture runs tcpdump and copies its pcap stream until the duration, the size cap, a cancel or tcpdump's own exit.
func (m *Manager) runCapture(ctx context.Context, p CaptureSpec, argv []string, netns *os.File, w io.Writer, counts *pcapCounts, st commands.Stream) (string, error) {
	cmd := exec.Command(argv[0], argv[1:]...)
	cmd.Env = append([]string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C.UTF-8"}, m.opts.Env...)
	cmd.Dir = "/"
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	if netns != nil {
		cmd.ExtraFiles = []*os.File{netns} // fd 3
	}
	stderr := &tailWriter{w: st.Stderr(), max: captureStderrMax}
	cmd.Stderr = stderr
	out, err := cmd.StdoutPipe()
	if err != nil {
		return "", err
	}
	if err := cmd.Start(); err != nil {
		return "", fmt.Errorf("start tcpdump: %w", err)
	}

	var (
		mu     sync.Mutex
		reason string
	)
	stop := func(why string) {
		mu.Lock()
		defer mu.Unlock()
		if reason != "" {
			return
		}
		reason = why
		_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGINT) // tcpdump flushes and prints its counters
		time.AfterFunc(3*time.Second, func() { _ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL) })
	}
	copyErr := make(chan error, 1)
	go func() { copyErr <- copyPcap(out, w, p.MaxBytes, counts, func() { stop(capEndSizeLimit) }) }()
	exited := make(chan struct{})
	var cerr error
	go func() {
		cerr = <-copyErr
		_ = cmd.Wait()
		close(exited)
	}()

	timer := time.NewTimer(time.Duration(p.DurationS) * time.Second)
	defer timer.Stop()
	tick := time.NewTicker(m.opts.ProgressEvery)
	defer tick.Stop()
	done := ctx.Done()
loop:
	for {
		select {
		case <-exited:
			break loop
		case <-timer.C:
			stop(capEndDuration)
		case <-done:
			done = nil
			stop(capEndCancelled)
		case <-tick.C:
			b, n := counts.bytes.Load(), counts.packets.Load()
			fmt.Fprintf(st.Stdout(), "%d packets, %s\n", n, humanBytes(b))
			var reply struct {
				Cancel bool `json:"cancel"`
			}
			rctx, cancel := context.WithTimeout(ctx, 10*time.Second)
			err := m.opts.CP.Request(rctx, "net.capture.progress", map[string]any{"capture_id": p.CaptureID, "bytes": b, "packets": n}, &reply)
			cancel()
			if err != nil {
				m.log.Warn("net capture progress", "capture", p.CaptureID, "err", err)
			} else if reply.Cancel {
				fmt.Fprintln(st.Stdout(), "cancelled")
				stop(capEndCancelled)
			}
		}
	}
	mu.Lock()
	why := reason
	mu.Unlock()
	switch {
	case why == capEndCancelled:
		return "", &commands.ExitError{Code: ExitCancelled, Err: errors.New("capture cancelled")}
	case cerr != nil:
		return "", fmt.Errorf("read tcpdump's output: %w", cerr)
	case why == "":
		// tcpdump ended by itself: a bad interface or filter, missing privileges.
		code := cmd.ProcessState.ExitCode()
		if code != 0 || counts.bytes.Load() == 0 {
			msg := strings.TrimSpace(stderr.tail())
			if msg == "" {
				msg = "no output"
			}
			return "", &commands.ExitError{Code: max(code, 1), Err: fmt.Errorf("tcpdump exited with %d: %s", code, lastLine(msg))}
		}
		return capEndExited, nil
	}
	return why, nil
}

// uploadCapture posts the file in chunks (POST /uploads/net.capture/<id>?offset=N), each retried on transient errors.
func (m *Manager) uploadCapture(ctx context.Context, id, path string, size int64, sum string) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()
	buf := make([]byte, m.opts.ChunkBytes)
	for off := int64(0); off < size; {
		n, err := io.ReadFull(f, buf)
		if err != nil && !errors.Is(err, io.ErrUnexpectedEOF) && !errors.Is(err, io.EOF) {
			return err
		}
		chunk := buf[:n]
		cs := sha256.Sum256(chunk)
		headers := map[string]string{
			"X-Falak-Chunk-Sha256":  hex.EncodeToString(cs[:]),
			"X-Falak-Upload-Size":   strconv.FormatInt(size, 10),
			"X-Falak-Upload-Sha256": sum,
		}
		urlPath := "/uploads/net.capture/" + id + "?offset=" + strconv.FormatInt(off, 10)
		var uerr error
		for attempt := 0; attempt < 4; attempt++ {
			if attempt > 0 {
				select {
				case <-ctx.Done():
					return ctx.Err()
				case <-time.After(time.Duration(1<<(attempt-1)) * m.opts.retryUnit()):
				}
			}
			if uerr = m.opts.CP.Upload(ctx, urlPath, chunk, headers); uerr == nil || !transport.Retryable(uerr) {
				break
			}
		}
		if uerr != nil {
			return fmt.Errorf("upload the capture (offset %d): %w", off, uerr)
		}
		if n == 0 {
			return errors.New("the capture file is shorter than recorded")
		}
		off += int64(n)
	}
	return nil
}

func (o Options) retryUnit() time.Duration {
	if o.RetryUnit > 0 {
		return o.RetryUnit
	}
	return time.Second
}

// tcpdump finds tcpdump, installing it on first use when it's missing (servers provisioned before it was a base
// package).
func (m *Manager) tcpdump(ctx context.Context, st commands.Stream) (string, error) {
	if m.opts.Tcpdump != "" {
		return m.opts.Tcpdump, nil
	}
	if bin, err := exec.LookPath("tcpdump"); err == nil {
		return bin, nil
	}
	if m.opts.InstallTcpdump == nil {
		return "", &commands.ExitError{Code: ExitNotInstalled, Err: errors.New("tcpdump is not installed on this server (apt install tcpdump)")}
	}
	fmt.Fprintln(st.Stdout(), "tcpdump is not installed; installing it")
	if err := m.opts.InstallTcpdump(ctx, st); err != nil {
		return "", fmt.Errorf("install tcpdump: %w", err)
	}
	return exec.LookPath("tcpdump")
}

// captureTempDir makes a fresh root-only directory for one capture under CaptureDir (itself root-only and never a
// symlink), after removing leftovers of crashed captures and checking the free space.
func (m *Manager) captureTempDir(maxBytes int64) (string, error) {
	parent := m.opts.CaptureDir
	if err := os.MkdirAll(parent, 0o700); err != nil {
		return "", err
	}
	fi, err := os.Lstat(parent)
	if err != nil {
		return "", err
	}
	if !fi.IsDir() || fi.Mode()&os.ModeSymlink != 0 {
		return "", fmt.Errorf("%s is not a directory", parent)
	}
	if st, ok := fi.Sys().(*syscall.Stat_t); ok && int(st.Uid) != os.Geteuid() {
		return "", fmt.Errorf("%s is not owned by the agent's user", parent)
	}
	if err := os.Chmod(parent, 0o700); err != nil {
		return "", err
	}
	if entries, err := os.ReadDir(parent); err == nil {
		for _, e := range entries {
			if info, err := e.Info(); err == nil && strings.HasPrefix(e.Name(), "cap-") && time.Since(info.ModTime()) > captureStaleAfter {
				_ = os.RemoveAll(filepath.Join(parent, e.Name()))
			}
		}
	}
	var sfs unix.Statfs_t
	if err := unix.Statfs(parent, &sfs); err == nil {
		free := int64(sfs.Bavail) * int64(sfs.Bsize) //nolint:gosec,unconvert // sizes fit; field types differ per OS
		if free < maxBytes+m.opts.MinFreeBytes {
			return "", fmt.Errorf("not enough disk space for the capture (%s free, %s needed)", humanBytes(free), humanBytes(maxBytes+m.opts.MinFreeBytes))
		}
	}
	return os.MkdirTemp(parent, "cap-")
}

// resolveInterface turns an interface kind into the interface's name: the default route's, the first WireGuard
// interface, or docker0.
func (m *Manager) resolveInterface(kind string) (string, error) {
	var name string
	switch kind {
	case captureIfaceDocker:
		name = "docker0"
		if _, err := os.Stat(filepath.Join(m.opts.SysClassNet, name)); err != nil {
			return "", errors.New("Docker's bridge docker0 does not exist on this server") //nolint:staticcheck // shown to users
		}
	case captureIfaceWG:
		entries, _ := os.ReadDir(m.opts.SysClassNet)
		names := make([]string, 0, len(entries))
		for _, e := range entries {
			names = append(names, e.Name())
		}
		sort.Strings(names)
		for _, n := range names {
			if b, err := os.ReadFile(filepath.Join(m.opts.SysClassNet, n, "uevent")); err == nil && strings.Contains(string(b), "DEVTYPE=wireguard") {
				name = n
				break
			}
		}
		if name == "" {
			return "", errors.New("no WireGuard interface on this server (it joins a private network first)")
		}
	default:
		b, err := os.ReadFile(filepath.Join(m.opts.ProcRoot, "net", "route"))
		if err != nil {
			return "", fmt.Errorf("read the routing table: %w", err)
		}
		for _, line := range strings.Split(string(b), "\n")[1:] {
			f := strings.Fields(line)
			if len(f) >= 8 && f[1] == "00000000" && f[7] == "00000000" {
				name = f[0]
				break
			}
		}
		if name == "" {
			return "", errors.New("this server has no IPv4 default route")
		}
	}
	if !ifaceNameRe.MatchString(name) {
		return "", fmt.Errorf("invalid interface name %q", name)
	}
	return name, nil
}

// dropUser is the user tcpdump switches to once the capture device is open.
func dropUser() string {
	if _, err := user.Lookup("tcpdump"); err == nil {
		return "tcpdump"
	}
	return "nobody"
}

type pcapCounts struct{ bytes, packets atomic.Int64 }

// copyPcap copies a pcap stream record by record: the global header, then whole records while the file stays within
// limit. At the limit it calls stop and discards the rest (tcpdump must not block on a full pipe). A record cut short
// by the end of the stream is dropped, so w always holds a valid pcap.
func copyPcap(r io.Reader, w io.Writer, limit int64, c *pcapCounts, stop func()) error {
	br := bufio.NewReaderSize(r, 64<<10)
	defer func() { _, _ = io.Copy(io.Discard, br) }()
	gh := make([]byte, pcapGlobalHeader)
	if _, err := io.ReadFull(br, gh); err != nil {
		if errors.Is(err, io.EOF) {
			return nil // tcpdump wrote nothing (it failed to start)
		}
		return errors.New("truncated pcap header")
	}
	var order binary.ByteOrder
	switch {
	case binary.LittleEndian.Uint32(gh) == 0xa1b2c3d4 || binary.LittleEndian.Uint32(gh) == 0xa1b23c4d:
		order = binary.LittleEndian
	case binary.BigEndian.Uint32(gh) == 0xa1b2c3d4 || binary.BigEndian.Uint32(gh) == 0xa1b23c4d:
		order = binary.BigEndian
	default:
		return errors.New("not a pcap stream")
	}
	if _, err := w.Write(gh); err != nil {
		return err
	}
	c.bytes.Store(pcapGlobalHeader)
	rec := make([]byte, pcapRecordHeader+FullSnaplen)
	for {
		if _, err := io.ReadFull(br, rec[:pcapRecordHeader]); err != nil {
			return nil
		}
		n := int(order.Uint32(rec[8:12]))
		if n > FullSnaplen {
			return fmt.Errorf("pcap record of %d bytes", n)
		}
		if _, err := io.ReadFull(br, rec[pcapRecordHeader:pcapRecordHeader+n]); err != nil {
			return nil
		}
		size := int64(pcapRecordHeader + n)
		if c.bytes.Load()+size > limit {
			stop()
			return nil
		}
		if _, err := w.Write(rec[:size]); err != nil {
			return err
		}
		c.bytes.Add(size)
		c.packets.Add(1)
	}
}

// tailWriter passes writes through up to max bytes and keeps the last ones (for the error message).
type tailWriter struct {
	mu   sync.Mutex
	w    io.Writer
	max  int
	n    int
	last []byte
}

func (t *tailWriter) Write(p []byte) (int, error) {
	t.mu.Lock()
	defer t.mu.Unlock()
	if room := t.max - t.n; room > 0 {
		_, _ = t.w.Write(p[:min(len(p), room)])
	}
	t.n += len(p)
	t.last = append(t.last, p...)
	if len(t.last) > 1024 {
		t.last = t.last[len(t.last)-1024:]
	}
	return len(p), nil
}

func (t *tailWriter) tail() string {
	t.mu.Lock()
	defer t.mu.Unlock()
	return string(t.last)
}

func lastLine(s string) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	return strings.TrimSpace(lines[len(lines)-1])
}

func endLabel(ended string) string {
	switch ended {
	case capEndSizeLimit:
		return "size limit reached"
	case capEndExited:
		return "tcpdump exited"
	default:
		return "duration reached"
	}
}

func humanBytes(n int64) string {
	switch {
	case n >= 1<<20:
		return fmt.Sprintf("%.1f MiB", float64(n)/(1<<20))
	case n >= 1<<10:
		return fmt.Sprintf("%.1f KiB", float64(n)/(1<<10))
	default:
		return fmt.Sprintf("%d B", n)
	}
}
