// Package logs ships log files and Docker container logs into the OTLP relay (replacing
// promtail/alloy). Files are polled, so rotation (rename + recreate) and truncation are both handled
// without inotify; offsets survive agent restarts.
package logs

import (
	"bytes"
	"context"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/obs"
)

// Source is one configured log source.
type Source struct {
	Path    string `json:"path"` // absolute host path or glob
	Service string `json:"service,omitempty"`
	Site    string `json:"site,omitempty"`
	Format  string `json:"format,omitempty"` // plain | json
	// Kind is the falak.log.kind of the records: "app" (default for site sources) or "access" (edge HTTP
	// access log in Caddy's JSON format, flattened into http.* / url.* / client.* attributes).
	Kind string `json:"kind,omitempty"`
	// Multiline merges continuation lines into the record they belong to. "laravel": a record starts
	// with "[YYYY-MM-DD" (Monolog's line format), so stack traces become part of the error record.
	Multiline string `json:"multiline,omitempty"`
	// MultilinePattern is a start-of-record regular expression (RE2): a line it matches starts a record, every other
	// line is appended to the record before it. Wins over Multiline.
	MultilinePattern string `json:"multiline_pattern,omitempty"`
	// Container: Path is inside the running containers of Site (label falak.site), read through their
	// /proc/<pid>/root; ComposeService narrows them to one compose service (label falak.service).
	Container      bool   `json:"container,omitempty"`
	ComposeService string `json:"compose_service,omitempty"`
	// Origin is the falak.log.origin attribute of the records: "file" (default), "php_fpm", "php_fpm_slow"; container
	// sources are always "container_file". The Logs tab filters on it (structured metadata, not a Loki label).
	Origin string `json:"origin,omitempty"`
	// SiteFromFile takes the site slug from the file name (<slug>.log); the edge access logs use it.
	SiteFromFile bool `json:"-"`
}

// Values of falak.log.origin.
const (
	OriginFile          = "file"
	OriginContainerFile = "container_file"
	OriginPHPFPM        = "php_fpm"
	OriginPHPFPMSlow    = "php_fpm_slow"
)

// key identifies a source across SetSources calls: two sites may name the same path inside their containers.
func (s Source) key() string {
	if s.Container {
		return ContainerKeyPrefix + s.Site + ":" + s.ComposeService + ":" + s.Path
	}
	return s.Path
}

// AccessLogDir holds the edge's per-site HTTP access logs (<slug>.log, Caddy JSON). falak-edge writes
// them (edge.caddy.apply sites[].access_log) and the telemetry service always tails them.
const AccessLogDir = "/var/log/falak/access"

// AccessSource is the built-in source for AccessLogDir.
func AccessSource() Source {
	return Source{Path: AccessLogDir + "/*.log", Kind: "access", Format: "json", SiteFromFile: true}
}

// MaxRecord bounds a merged multi-line record (a long stack trace); beyond it a new record starts.
const MaxRecord = 256 << 10

// multilineStart matches the first line of a record per Multiline mode.
var multilineStart = map[string]*regexp.Regexp{
	"laravel": regexp.MustCompile(`^\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}`),
}

var slugRE = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)

// MaxLine bounds a single line; longer lines are split.
const MaxLine = 64 << 10

// readChunk bounds bytes read per file per poll so one busy file cannot starve the others.
const readChunk = 4 << 20

type fileState struct {
	src     Source
	display string            // log.file.path: the host path, or the path inside the container
	attrs   map[string]string // the container's attributes (container sources)
	f       *os.File
	ino     uint64
	offset  int64
	partial []byte
	head    []byte // first ≤ headLen bytes; a change means the file was truncated and rewritten
	// pending is a multi-line record still collecting continuation lines; pendingN is the number of
	// file bytes it spans (excluded from the saved offset so a restart re-reads it).
	pending  []byte
	pendingN int64
}

const headLen = 64

type savedOffset struct {
	Ino    uint64 `json:"ino"`
	Offset int64  `json:"offset"`
	Head   []byte `json:"head,omitempty"`
}

// Tailer follows files matching the configured sources. Files are keyed by their real host path, or for container
// sources by container:<id>:<path in the container> (offsets are per container).
type Tailer struct {
	fs        hostfs.FS
	statePath string // real path of the offsets file
	sink      obs.Sink
	log       *slog.Logger
	interval  time.Duration

	mu         sync.Mutex
	sources    []Source
	patterns   map[string]*regexp.Regexp // compiled MultilinePattern of the sources
	fresh      map[string]bool           // sources not yet polled: files existing at first sight start at EOF
	files      map[string]*fileState
	saved      map[string]savedOffset
	dirty      bool
	stop       context.Context // the running poll's: cancelled, it stops between files and lines
	containers *containerSet   // nil: no Docker, container sources match nothing
	exceptions ExceptionObserver
	excMinute  int64          // the minute excCount counts
	excCount   map[string]int // site → error entries parsed for an exception this minute
}

// NewTailer creates a tailer; offsets persist in <stateDir>/log-offsets.json (stateDir is a host path).
func NewTailer(fs hostfs.FS, stateDir string, sink obs.Sink, log *slog.Logger) *Tailer {
	if log == nil {
		log = slog.Default()
	}
	t := &Tailer{fs: fs, statePath: fs.P(filepath.Join(stateDir, "log-offsets.json")), sink: sink, log: log,
		interval: time.Second, fresh: map[string]bool{}, files: map[string]*fileState{}, saved: map[string]savedOffset{}}
	if b, err := os.ReadFile(t.statePath); err == nil {
		_ = json.Unmarshal(b, &t.saved)
	}
	return t
}

// SetInterval changes the poll interval (tests).
func (t *Tailer) SetInterval(d time.Duration) { t.interval = d }

// Sources returns the configured sources.
func (t *Tailer) Sources() []Source {
	t.mu.Lock()
	defer t.mu.Unlock()
	return append([]Source(nil), t.sources...)
}

// SetContainerEngine lets container sources find the site's running containers. socket is checked before each list
// ("" = never checked: tests).
func (t *Tailer) SetContainerEngine(engine ContainerEngine, socket string) {
	t.mu.Lock()
	defer t.mu.Unlock()
	if engine == nil {
		t.containers = nil
		return
	}
	t.containers = newContainerSet(engine, socket, t.fs, t.log)
}

// SetExceptionObserver receives the exceptions found in Laravel log entries (Insights without the APM package).
func (t *Tailer) SetExceptionObserver(o ExceptionObserver) {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.exceptions = o
}

// SetSources replaces the configured sources; files no longer matched are closed. A MultilinePattern that does not
// compile (validated before) leaves its source line by line.
func (t *Tailer) SetSources(srcs []Source) {
	t.mu.Lock()
	defer t.mu.Unlock()
	known := map[string]bool{}
	for _, s := range t.sources {
		known[s.key()] = true
	}
	t.sources = append([]Source(nil), srcs...)
	t.patterns = map[string]*regexp.Regexp{}
	for _, s := range srcs {
		if !known[s.key()] {
			t.fresh[s.key()] = true
		}
		if s.MultilinePattern != "" {
			if re, err := regexp.Compile(s.MultilinePattern); err == nil {
				t.patterns[s.MultilinePattern] = re
			}
		}
	}
}

// Run polls until ctx is done.
func (t *Tailer) Run(ctx context.Context) {
	tick := time.NewTicker(t.interval)
	defer tick.Stop()
	for {
		// A poll can take long (4 MiB per file, a slow sink): it stops between files and lines once ctx is done, so
		// the shutdown below (which saves the offsets) follows promptly.
		t.poll(ctx)
		select {
		case <-ctx.Done():
			t.mu.Lock()
			for _, fsx := range t.files {
				fsx.f.Close()
			}
			t.files = map[string]*fileState{}
			t.saveLocked()
			t.mu.Unlock()
			return
		case <-tick.C:
		}
	}
}

func inode(fi os.FileInfo) uint64 {
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		return uint64(st.Ino)
	}
	return 0
}

// Poll performs one pass over all sources.
func (t *Tailer) Poll() { t.poll(context.Background()) }

func (t *Tailer) poll(ctx context.Context) {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.stop = ctx
	defer func() { t.stop = nil }()
	if ctx.Err() != nil {
		return
	}
	t.syncContainers(ctx)
	seen := map[string]bool{}
	for _, src := range t.sources {
		if ctx.Err() != nil {
			// Unfinished pass: files not seen this time are not taken for vanished.
			if t.dirty {
				t.saveLocked()
			}
			return
		}
		first := t.fresh[src.key()]
		delete(t.fresh, src.key())
		if src.Container {
			t.pollContainerSource(ctx, src, first, seen)
			continue
		}
		matches, err := filepath.Glob(t.fs.P(src.Path))
		if err != nil {
			continue
		}
		sort.Strings(matches)
		for _, real := range matches {
			if seen[real] || ctx.Err() != nil {
				continue
			}
			seen[real] = true
			t.pollFile(t.hostTarget(real), src, first)
		}
	}
	if t.containers != nil {
		for _, c := range t.containers.byID {
			c.firstPass = c.firstPass && c.root == nil // polled once its root is open
		}
	}
	for key, st := range t.files {
		if !seen[key] && ctx.Err() == nil {
			// Path vanished (rotated away, not yet recreated, or its container stopped): drain what is left, then close.
			t.closeFile(key, st, true)
		}
	}
	if t.dirty {
		t.saveLocked()
	}
}

// closeFile closes an open file and keeps its offset: a file that comes back with the same inode (a container
// restarted in place, reopened through its new root) resumes there. A vanished file is drained first (what is left is
// emitted, a partial last line included); a file closed only to be reopened keeps its partial line and pending record
// out of the offset, so they are read again whole.
func (t *Tailer) closeFile(key string, st *fileState, drain bool) {
	if drain {
		t.readAvailable(key, st)
		t.flushPartial(key, st)
		t.flushPending(key, st)
	}
	st.f.Close()
	t.saved[key] = savedOffset{Ino: st.ino, Offset: st.offset - int64(len(st.partial)) - st.pendingN, Head: st.head}
	delete(t.files, key)
	t.dirty = true
}

// syncContainers refreshes the container list when a container source needs it: the offsets of containers that went
// away are dropped, the files of a container restarted in place are reopened through its new root.
func (t *Tailer) syncContainers(ctx context.Context) {
	if t.containers == nil {
		return
	}
	wanted := false
	for _, src := range t.sources {
		wanted = wanted || src.Container
	}
	if !wanted {
		return
	}
	gone, reopened := t.containers.refresh(ctx)
	for i, id := range append(gone, reopened...) {
		prefix := ContainerKeyPrefix + id + ":"
		for key, st := range t.files {
			if strings.HasPrefix(key, prefix) {
				t.closeFile(key, st, i < len(gone))
			}
		}
	}
	for _, id := range gone {
		prefix := ContainerKeyPrefix + id + ":"
		for key := range t.saved {
			if strings.HasPrefix(key, prefix) {
				delete(t.saved, key)
				t.dirty = true
			}
		}
	}
}

// target is one file to poll: how to reach it, and what its records say about it.
type target struct {
	key     string // offsets and open files
	display string // log.file.path
	attrs   map[string]string
	stat    func() (os.FileInfo, error)
	open    func() (*os.File, error)
}

func (t *Tailer) hostTarget(real string) target {
	display := real
	if !t.fs.IsReal() {
		display = "/" + strings.TrimPrefix(strings.TrimPrefix(real, filepath.Clean(t.fs.Root)), "/")
	}
	return target{key: real, display: display,
		stat: func() (os.FileInfo, error) { return os.Stat(real) },
		open: func() (*os.File, error) { return os.OpenFile(real, openFlags, 0) }}
}

// pollContainerSource polls a container source's files in every running container of its site (and compose service).
// Paths are resolved inside the container's root (GlobIn) and opened through it, never on the host. A container first
// seen with this source starts its existing files at their end, unless it was created moments before (a deploy).
func (t *Tailer) pollContainerSource(ctx context.Context, src Source, first bool, seen map[string]bool) {
	if t.containers == nil || src.Site == "" {
		return
	}
	now := t.containers.now()
	for _, c := range t.containers.matching(src.Site, src.ComposeService) {
		root := c.root
		// Freshness is checked now, not when the container was first seen: a source added an hour after a deploy
		// starts that container's files at their end like any other.
		fromStart := c.fresh(now)
		for _, inner := range globIn(root, src.Path, MaxFilesPerSource, c.lister(now)) {
			key := containerKey(c.id, inner)
			if seen[key] || ctx.Err() != nil {
				continue
			}
			seen[key] = true
			rel := rootRel(inner)
			t.pollFile(target{key: key, display: inner, attrs: c.attrs(),
				stat: func() (os.FileInfo, error) { return root.Stat(rel) },
				open: func() (*os.File, error) { return root.OpenFile(rel, openFlags, 0) },
			}, src, (first || c.firstPass) && !fromStart)
		}
	}
}

func (t *Tailer) pollFile(tg target, src Source, first bool) {
	real := tg.key
	fi, err := tg.stat()
	if err != nil || !fi.Mode().IsRegular() {
		return
	}
	ino := inode(fi)
	st := t.files[real]
	if st != nil && st.ino != ino {
		// Rotated: finish the old file first, then switch to the new one from the start.
		t.readAvailable(real, st)
		t.flushPartial(real, st)
		t.flushPending(real, st)
		st.f.Close()
		delete(t.files, real)
		st = nil
		first = false
		delete(t.saved, real)
	}
	if st == nil {
		f, err := tg.open()
		if err != nil {
			return
		}
		if err := checkOpened(f, fi); err != nil {
			f.Close()
			t.log.Debug("log file refused", "path", tg.display, "err", err)
			return
		}
		st = &fileState{src: src, f: f, ino: ino, display: tg.display, attrs: tg.attrs}
		if sv, ok := t.saved[real]; ok && sv.Ino == ino && sv.Offset <= fi.Size() && bytes.HasPrefix(readHead(f), sv.Head) {
			st.offset = sv.Offset
			st.head = sv.Head
		} else if first {
			st.offset = fi.Size() // pre-existing history is not shipped
		}
		t.files[real] = st
		t.dirty = true
	}
	st.src = src
	if cur := readHead(st.f); fi.Size() < st.offset || !bytes.HasPrefix(cur, st.head) {
		// Truncated (copytruncate rotation).
		t.flushPending(real, st)
		st.offset = 0
		st.partial = nil
		st.head = nil
		t.dirty = true
	}
	if t.readAvailable(real, st) == 0 {
		// Nothing new for a whole poll interval: the collected record is complete.
		t.flushPending(real, st)
	}
	if len(st.head) < headLen {
		st.head = readHead(st.f)
	}
}

func readHead(f *os.File) []byte {
	b := make([]byte, headLen)
	n, _ := f.ReadAt(b, 0)
	return b[:n]
}

// readAvailable consumes new bytes and returns how many were read: at most up to the size the open file has now
// (fstat) and readChunk per call, so a file that keeps producing data however much is read (a pseudo file that slipped
// through) cannot hold the tailer's lock.
func (t *Tailer) readAvailable(real string, st *fileState) int {
	fi, err := st.f.Stat()
	if err != nil {
		return 0
	}
	budget := min(fi.Size()-st.offset, readChunk)
	if budget <= 0 {
		return 0
	}
	if _, err := st.f.Seek(st.offset, io.SeekStart); err != nil {
		return 0
	}
	buf := make([]byte, 64<<10)
	read := 0
	for int64(read) < budget && !t.stopped() {
		n, err := st.f.Read(buf[:min(int64(len(buf)), budget-int64(read))])
		if n > 0 {
			read += n
			st.offset += int64(n)
			t.dirty = true
			t.consume(real, st, buf[:n])
		}
		if err != nil || n == 0 {
			break
		}
	}
	return read
}

// stopped tells whether the running poll should end (its context is done).
func (t *Tailer) stopped() bool { return t.stop != nil && t.stop.Err() != nil }

func (t *Tailer) consume(real string, st *fileState, b []byte) {
	data := append(st.partial, b...)
	for {
		if t.stopped() {
			// Stop between lines: the offset goes back to the first line not handled, so the next start reads it.
			st.offset -= int64(len(data))
			st.partial = nil
			return
		}
		i := bytes.IndexByte(data, '\n')
		if i < 0 {
			break
		}
		t.line(real, st, data[:i], int64(i+1))
		data = data[i+1:]
	}
	for len(data) >= MaxLine {
		t.line(real, st, data[:MaxLine], MaxLine)
		data = data[MaxLine:]
	}
	st.partial = append([]byte(nil), data...)
}

func (t *Tailer) flushPartial(real string, st *fileState) {
	if len(st.partial) > 0 {
		t.line(real, st, st.partial, int64(len(st.partial)))
		st.partial = nil
	}
}

// line handles one complete line (n file bytes): emitted directly, or merged into the pending
// multi-line record when the source groups continuation lines.
func (t *Tailer) line(real string, st *fileState, line []byte, n int64) {
	start := multilineStart[st.src.Multiline]
	if re := t.patterns[st.src.MultilinePattern]; re != nil {
		start = re
	}
	if start == nil {
		t.emitLine(st, line)
		return
	}
	line = bytes.TrimRight(line, "\r")
	if st.pending != nil && !start.Match(line) && len(st.pending)+1+len(line) <= MaxRecord {
		st.pending = append(append(st.pending, '\n'), line...)
		st.pendingN += n
		return
	}
	t.flushPending(real, st)
	st.pending = append([]byte(nil), line...)
	st.pendingN = n
}

func (t *Tailer) flushPending(real string, st *fileState) {
	if st.pending != nil {
		// A record's trailing blank lines (the PHP-FPM slow log separates its entries with one) are not part of it.
		t.emitLine(st, bytes.TrimRight(st.pending, "\n"))
		st.pending, st.pendingN = nil, 0
		t.dirty = true
	}
}

func (t *Tailer) emitLine(st *fileState, line []byte) {
	src := st.src
	line = bytes.TrimRight(line, "\r")
	if len(bytes.TrimSpace(line)) == 0 {
		return
	}
	var rec obs.LogRecord
	if src.Kind == "access" {
		rec = ParseAccessLine(line)
	} else {
		rec = ParseLine(line, src.Format)
	}
	rec.Site = src.Site
	if src.SiteFromFile {
		if slug := strings.TrimSuffix(filepath.Base(st.display), ".log"); slugRE.MatchString(slug) {
			rec.Site = slug
		}
	}
	rec.Service = src.Service
	rec.Kind = src.Kind
	if rec.Attrs == nil {
		rec.Attrs = map[string]string{}
	}
	for k, v := range st.attrs {
		rec.Attrs[k] = v
	}
	rec.Attrs["log.file.path"] = st.display
	rec.Attrs["log.file.name"] = filepath.Base(st.display)
	if src.Kind != "access" {
		origin := src.Origin
		switch {
		case src.Container:
			origin = OriginContainerFile
		case origin == "":
			origin = OriginFile
		}
		rec.Attrs["falak.log.origin"] = origin
		if origin == OriginPHPFPMSlow {
			rec.Severity = "WARN" // a request over the pool's request_slowlog_timeout, with its backtrace
		}
	}
	if t.exceptions != nil && rec.Site != "" && src.Multiline == "laravel" && (rec.Severity == "ERROR" || rec.Severity == "FATAL") && t.mayParseException(rec.Site) {
		if ex, ok := ParseLaravelException(line); ok {
			ex.Site = rec.Site
			ex.File = filepath.Base(st.display)
			t.exceptions.ObserveLogException(ex)
		}
	}
	t.sink.EmitLog(rec)
}

// mayParseException counts the error entries of a site parsed for an exception this minute: at most
// MaxExceptionParsesPerMinute, so a crash loop's log costs no more than that in regex work.
func (t *Tailer) mayParseException(site string) bool {
	minute := time.Now().Unix() / 60
	if t.excCount == nil || t.excMinute != minute {
		t.excCount, t.excMinute = map[string]int{}, minute
	}
	if t.excCount[site] >= MaxExceptionParsesPerMinute {
		return false
	}
	t.excCount[site]++
	return true
}

func (t *Tailer) saveLocked() {
	out := map[string]savedOffset{}
	for k, v := range t.saved {
		out[k] = v
	}
	for real, st := range t.files {
		// Offsets exclude the buffered partial line and pending record so they are re-read after a restart.
		out[real] = savedOffset{Ino: st.ino, Offset: st.offset - int64(len(st.partial)) - st.pendingN, Head: st.head}
	}
	t.saved = out
	b, _ := json.Marshal(out)
	if err := os.MkdirAll(filepath.Dir(t.statePath), 0o700); err == nil {
		tmp := t.statePath + ".tmp"
		if os.WriteFile(tmp, b, 0o600) == nil {
			_ = os.Rename(tmp, t.statePath)
		}
	}
	t.dirty = false
}

var laravelLevel = regexp.MustCompile(`\]\s+[A-Za-z0-9_-]+\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):`)
var bareLevel = regexp.MustCompile(`\b(DEBUG|INFO|NOTICE|WARN|WARNING|ERROR|CRITICAL|FATAL|ALERT|EMERGENCY|PANIC)\b`)

// NormalizeSeverity maps common level names to obs severities.
func NormalizeSeverity(s string) string {
	switch strings.ToUpper(strings.TrimSpace(s)) {
	case "TRACE":
		return "TRACE"
	case "DEBUG":
		return "DEBUG"
	case "", "INFO", "NOTICE", "INFORMATION":
		return "INFO"
	case "WARN", "WARNING":
		return "WARN"
	case "ERROR", "ERR":
		return "ERROR"
	case "CRITICAL", "CRIT", "ALERT", "EMERGENCY", "EMERG", "FATAL", "PANIC":
		return "FATAL"
	}
	return "INFO"
}

// ParseLine converts a raw line into a record. JSON lines (Monolog, pino, slog, zap …) have their
// message/level/time/trace fields extracted and remaining scalar fields turned into attributes.
func ParseLine(line []byte, format string) obs.LogRecord {
	rec := obs.LogRecord{Time: time.Now(), Severity: "INFO", Body: string(line)}
	if format == "json" || (format == "" && len(line) > 0 && line[0] == '{') {
		var m map[string]any
		if json.Unmarshal(line, &m) == nil {
			return fromJSON(m, rec)
		}
	}
	if mm := laravelLevel.FindSubmatch(line); mm != nil {
		rec.Severity = NormalizeSeverity(string(mm[1]))
	} else if mm := bareLevel.FindSubmatch(line); mm != nil {
		rec.Severity = NormalizeSeverity(string(mm[1]))
	}
	return rec
}

func fromJSON(m map[string]any, rec obs.LogRecord) obs.LogRecord {
	take := func(keys ...string) (string, bool) {
		for _, k := range keys {
			if v, ok := m[k]; ok {
				delete(m, k)
				switch t := v.(type) {
				case string:
					return t, true
				case float64:
					return fmt.Sprint(t), true
				}
			}
		}
		return "", false
	}
	if msg, ok := take("message", "msg"); ok {
		rec.Body = msg
	}
	if lvl, ok := take("level_name", "severity", "level"); ok {
		switch lvl {
		case "10":
			lvl = "TRACE"
		case "20":
			lvl = "DEBUG"
		case "30":
			lvl = "INFO"
		case "40":
			lvl = "WARN"
		case "50":
			lvl = "ERROR"
		case "60":
			lvl = "FATAL"
		}
		rec.Severity = NormalizeSeverity(lvl)
	}
	delete(m, "level")
	if ts, ok := take("time", "timestamp", "datetime", "@timestamp", "ts"); ok {
		for _, layout := range []string{time.RFC3339Nano, "2006-01-02T15:04:05.000000Z07:00", "2006-01-02 15:04:05"} {
			if tt, err := time.Parse(layout, ts); err == nil {
				rec.Time = tt
				break
			}
		}
	}
	if s, ok := take("trace_id", "traceId", "trace.id"); ok {
		if b, err := hex.DecodeString(s); err == nil && len(b) == 16 {
			rec.TraceID = b
		}
	}
	if s, ok := take("span_id", "spanId", "span.id"); ok {
		if b, err := hex.DecodeString(s); err == nil && len(b) == 8 {
			rec.SpanID = b
		}
	}
	rec.Attrs = map[string]string{}
	for k, v := range m {
		switch t := v.(type) {
		case string:
			rec.Attrs[k] = t
		case float64, bool:
			rec.Attrs[k] = fmt.Sprint(t)
		case nil:
		default:
			if b, err := json.Marshal(t); err == nil && len(b) <= 4096 {
				rec.Attrs[k] = string(b)
			}
		}
	}
	return rec
}
