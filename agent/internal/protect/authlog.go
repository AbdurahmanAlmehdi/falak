package protect

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/netip"
	"os"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// AuthKind is what an sshd line says about a source address.
type AuthKind int

// Kinds of auth events.
const (
	AuthNone     AuthKind = iota
	AuthFailure           // an attempt that failed
	AuthAccepted          // a successful login: the address is trusted for a while (never auto-banned)
)

// AuthEvent is one sshd line about a source address.
type AuthEvent struct {
	Kind    AuthKind
	Addr    netip.Addr
	User    string
	Invalid bool // the user does not exist
	Count   int  // "message repeated N times"
	At      time.Time
	// PID is sshd's process for the connection (0 = unknown): a connection counts as one failure however many
	// attempts it makes.
	PID int
}

// Every pattern is anchored at both ends and the user name is greedy, so the address is the LAST "from <ip> port <n>"
// of the line. The user name is the attacker's text ("x from 203.0.113.50 port 1"): a lazy or unanchored match would
// take the address from it and ban whoever it names.
var (
	reRepeated = regexp.MustCompile(`^message repeated (\d+) times: \[\s*(.*?)\s*\]$`)
	reFailed   = regexp.MustCompile(`^Failed (?:password|keyboard-interactive/pam|none) for (invalid user )?(.*) from (\S+) port \d+ ssh2$`)
	reInvalid  = regexp.MustCompile(`^Invalid user (.*) from (\S+) port \d+$`)
	reMaxAuth  = regexp.MustCompile(`^error: maximum authentication attempts exceeded for (invalid user )?(.*) from (\S+) port \d+ ssh2(?: \[preauth\])?$`)
	reAccepted = regexp.MustCompile(`^Accepted (?:publickey|password|keyboard-interactive/pam|gssapi-with-mic|hostbased) for (.*) from (\S+) port \d+ ssh2(?:: [A-Za-z0-9-]+ (?:SHA256|MD5):[A-Za-z0-9+/=:]+)?$`)
)

// ParseAuthMessage reads one sshd message (without the syslog prefix). ok=false when it says nothing about an
// attempt. Counted: "Invalid user", "Failed … for <existing user>" and "maximum authentication attempts exceeded for
// invalid user". Not counted: "Failed password for invalid user" (its "Invalid user" line was), max-auth for an
// existing user (its "Failed" lines were) and closes of authenticating users (nothing failed).
func ParseAuthMessage(msg string) (AuthEvent, bool) {
	msg = strings.TrimSpace(msg)
	count := 1
	if m := reRepeated.FindStringSubmatch(msg); m != nil {
		n, err := strconv.Atoi(m[1])
		if err != nil || n < 1 {
			return AuthEvent{}, false
		}
		count, msg = min(n, 10000), m[2]
	}
	ev := AuthEvent{Count: count}
	var user, addr string
	if m := reAccepted.FindStringSubmatch(msg); m != nil {
		ev.Kind, user, addr = AuthAccepted, m[1], m[2]
	} else if m := reInvalid.FindStringSubmatch(msg); m != nil {
		ev.Kind, ev.Invalid, user, addr = AuthFailure, true, m[1], m[2]
	} else if m := reFailed.FindStringSubmatch(msg); m != nil {
		if m[1] != "" {
			return AuthEvent{}, false
		}
		ev.Kind, user, addr = AuthFailure, m[2], m[3]
	} else if m := reMaxAuth.FindStringSubmatch(msg); m != nil {
		if m[1] == "" {
			return AuthEvent{}, false
		}
		ev.Kind, ev.Invalid, user, addr = AuthFailure, true, m[2], m[3]
	} else {
		return AuthEvent{}, false
	}
	a, err := netip.ParseAddr(addr)
	if err != nil || a.Zone() != "" {
		return AuthEvent{}, false
	}
	ev.Addr = a.Unmap()
	ev.User = sanitizeUser(user)
	return ev, true
}

// sanitizeUser keeps a user name printable and short (it comes from the attacker).
func sanitizeUser(u string) string {
	u = strings.Map(func(r rune) rune {
		if r < 0x20 || r == 0x7f {
			return -1
		}
		return r
	}, u)
	if len(u) > 64 {
		u = u[:64]
	}
	return u
}

// sshdProgram is the program field of a syslog line: sshd, sshd-session (OpenSSH ≥ 9.8) or sshd-auth, with its PID.
var sshdProgram = regexp.MustCompile(`^(?:sshd|sshd-session|sshd-auth)(?:\[(\d+)\])?:$`)

// ParseAuthLogLine reads one /var/log/auth.log line: RFC 3339 ("2026-10-15T10:00:00.123456+00:00 host sshd[1]: …",
// rsyslog on Ubuntu ≥ 23.10) or classic syslog ("Oct 15 10:00:00 host sshd[1]: …"). Only the program field decides
// whether the line is sshd's: another program's message that mentions "sshd[1]:" is not. The timestamp falls back to
// now.
func ParseAuthLogLine(line string, now time.Time) (AuthEvent, bool) {
	tokens, starts := fieldsAt(line, 6)
	if len(tokens) < 4 {
		return AuthEvent{}, false
	}
	prog := 2 // RFC 3339: time host program
	if _, err := time.Parse(time.RFC3339Nano, tokens[0]); err != nil {
		prog = 4 // classic: Mon DD HH:MM:SS host program
	}
	if len(tokens) <= prog+1 {
		return AuthEvent{}, false
	}
	m := sshdProgram.FindStringSubmatch(tokens[prog])
	if m == nil {
		return AuthEvent{}, false
	}
	ev, ok := ParseAuthMessage(line[starts[prog+1]:])
	if !ok {
		return ev, false
	}
	ev.PID, _ = strconv.Atoi(m[1])
	ev.At = parseSyslogTime(line[:starts[prog]], now)
	return ev, true
}

// fieldsAt splits the first n space-separated fields of s, with their start offsets.
func fieldsAt(s string, n int) ([]string, []int) {
	var tokens []string
	var starts []int
	i := 0
	for len(tokens) < n && i < len(s) {
		for i < len(s) && s[i] == ' ' {
			i++
		}
		if i >= len(s) {
			break
		}
		j := i
		for j < len(s) && s[j] != ' ' {
			j++
		}
		tokens, starts = append(tokens, s[i:j]), append(starts, i)
		i = j
	}
	return tokens, starts
}

func parseSyslogTime(prefix string, now time.Time) time.Time {
	f := strings.Fields(prefix)
	if len(f) >= 1 {
		if t, err := time.Parse(time.RFC3339Nano, f[0]); err == nil {
			return t
		}
	}
	if len(f) >= 3 {
		if t, err := time.ParseInLocation("Jan 2 15:04:05 2006", strings.Join(f[:3], " ")+" "+strconv.Itoa(now.Year()), time.Local); err == nil {
			if t.After(now.Add(24 * time.Hour)) { // December lines read in January
				t = t.AddDate(-1, 0, 0)
			}
			return t
		}
	}
	return now
}

// journalEntry is the part of `journalctl -o json` that is read. MESSAGE is a string, or an array of bytes when it
// is not valid UTF-8.
type journalEntry struct {
	Message  json.RawMessage `json:"MESSAGE"`
	Cursor   string          `json:"__CURSOR"`
	Realtime string          `json:"__REALTIME_TIMESTAMP"`
	PID      string          `json:"_PID"`
	Comm     string          `json:"_COMM"`
}

// sshdComm are the trusted process names of sshd's entries (journald's _COMM).
var sshdComm = map[string]bool{"sshd": true, "sshd-session": true, "sshd-auth": true}

// ParseJournal reads `journalctl -o json` output: the events and the last cursor.
func ParseJournal(out []byte, now time.Time) ([]AuthEvent, string) {
	var events []AuthEvent
	cursor := ""
	sc := bufio.NewScanner(bytes.NewReader(out))
	sc.Buffer(make([]byte, 64*1024), 1<<20)
	for sc.Scan() {
		line := bytes.TrimSpace(sc.Bytes())
		if len(line) == 0 {
			continue
		}
		var e journalEntry
		if json.Unmarshal(line, &e) != nil {
			continue
		}
		if e.Cursor != "" {
			cursor = e.Cursor
		}
		// Defense in depth (journalctl already matches _COMM): another program's entry, e.g. `logger -t sshd`.
		if e.Comm != "" && !sshdComm[e.Comm] {
			continue
		}
		ev, ok := ParseAuthMessage(journalMessage(e.Message))
		if !ok {
			continue
		}
		ev.At = now
		if us, err := strconv.ParseInt(e.Realtime, 10, 64); err == nil && us > 0 {
			ev.At = time.UnixMicro(us)
		}
		ev.PID, _ = strconv.Atoi(e.PID)
		events = append(events, ev)
	}
	return events, cursor
}

func journalMessage(raw json.RawMessage) string {
	var s string
	if json.Unmarshal(raw, &s) == nil {
		return s
	}
	var b []byte
	var ints []int
	if json.Unmarshal(raw, &ints) == nil {
		for _, i := range ints {
			if i >= 0 && i < 256 {
				b = append(b, byte(i))
			}
		}
	}
	return string(b)
}

// Auth log sources.
const (
	SourceJournal = "journald"
	SourceAuthLog = "auth.log"
	AuthLogPath   = "/var/log/auth.log"
	maxJournal    = 5000
	maxAuthBytes  = 1 << 20
)

// authReader reads new sshd events since the previous call: journald, else /var/log/auth.log.
type authReader struct {
	runner runner.Runner
	fs     hostfs.FS
	now    func() time.Time

	cursor    string
	noJournal bool
	inode     uint64
	offset    int64
	started   bool
}

// Read returns the events since the last call and which source they came from. The first file read starts at its
// end; the first journal read takes the last minute.
func (r *authReader) Read(ctx context.Context) ([]AuthEvent, string) {
	if !r.noJournal {
		if evs, ok := r.readJournal(ctx); ok {
			return evs, SourceJournal
		}
	}
	return r.readFile(), SourceAuthLog
}

func (r *authReader) readJournal(ctx context.Context) ([]AuthEvent, bool) {
	// _COMM is set by journald from the kernel (the process name), unlike SYSLOG_IDENTIFIER (-t): `logger -t sshd`
	// from any local user cannot forge an entry. Matches of one field are ORed.
	args := []string{"_COMM=sshd", "_COMM=sshd-session", "_COMM=sshd-auth", "-o", "json", "--no-pager", "-q", "-n", strconv.Itoa(maxJournal)}
	if r.cursor != "" {
		args = append(args, "--after-cursor", r.cursor)
	} else {
		args = append(args, "--since", "@"+strconv.FormatInt(r.now().Add(-time.Minute).Unix(), 10))
	}
	res, err := r.runner.Run(ctx, runner.Cmd{Name: "journalctl", Args: args})
	if err != nil {
		// No journalctl at all (exec failed): the file from now on.
		if ctx.Err() == nil {
			r.noJournal = true
		}
		return nil, false
	}
	// journald without storage (Storage=none, a container): sshd only reaches rsyslog's auth.log.
	if bytes.Contains(res.Stderr, []byte("No journal files were found")) || bytes.Contains(res.Stderr, []byte("No journal files were opened")) {
		r.noJournal = true
		return nil, false
	}
	if res.ExitCode != 0 {
		// A cursor the journal no longer has (vacuumed, rotated away): start over from the last minute.
		r.cursor = ""
		return nil, true
	}
	evs, cursor := ParseJournal(res.Stdout, r.now())
	if cursor != "" {
		r.cursor = cursor
	}
	return evs, true
}

func (r *authReader) readFile() []AuthEvent {
	f, err := os.Open(r.fs.P(AuthLogPath))
	if err != nil {
		return nil
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return nil
	}
	var ino uint64
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		ino = uint64(st.Ino) //nolint:unconvert // int64 on some platforms
	}
	switch {
	case !r.started:
		r.started, r.inode, r.offset = true, ino, fi.Size()
		return nil
	case ino != r.inode || fi.Size() < r.offset:
		r.inode, r.offset = ino, 0 // rotated or truncated
	}
	if _, err := f.Seek(r.offset, io.SeekStart); err != nil {
		return nil
	}
	data, _ := io.ReadAll(io.LimitReader(f, maxAuthBytes))
	// Only whole lines; the rest is read next time.
	if i := bytes.LastIndexByte(data, '\n'); i >= 0 {
		data = data[:i+1]
	} else if len(data) < maxAuthBytes {
		data = nil
	} // else: a 1 MiB "line" is skipped, never re-read forever
	r.offset += int64(len(data))
	now := r.now()
	var evs []AuthEvent
	for _, line := range strings.Split(string(data), "\n") {
		if ev, ok := ParseAuthLogLine(line, now); ok {
			evs = append(evs, ev)
		}
	}
	return evs
}
