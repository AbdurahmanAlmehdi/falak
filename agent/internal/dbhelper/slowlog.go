package dbhelper

import (
	"bytes"
	"io"
	"os"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/sqlnorm"
)

// readSlowLog reads the MySQL/MariaDB slow log from the cursor: complete entries only (an entry still being written is
// read next time), at most slowReadMax bytes a call. Without a cursor (the agent's first call) it starts at the end.
// A file replaced or truncated since (another inode, or shorter than the offset) is read from its start. Once read past
// slowTruncate bytes the file is emptied: mysqld appends (O_APPEND), so it carries on at the new end, and the log
// never grows without bound.
func (h *Helper) readSlowLog(o *Observation) {
	path := h.path(mySlowLog)
	f, err := os.OpenFile(path, os.O_RDWR, 0)
	if err != nil {
		if !os.IsNotExist(err) {
			o.warn("slow log: %v", err)
		}
		return
	}
	defer f.Close()
	st, err := f.Stat()
	if err != nil {
		o.warn("slow log: %v", err)
		return
	}
	var inode uint64
	if sys, ok := st.Sys().(*syscall.Stat_t); ok {
		inode = uint64(sys.Ino) //nolint:unconvert // int64 on some platforms
	}
	cur := o.Cursor
	offset := cur.SlowLogOffset
	switch {
	case !cur.Started:
		o.Cursor.SlowLogInode, o.Cursor.SlowLogOffset = inode, st.Size()
		return
	case inode != cur.SlowLogInode || st.Size() < offset:
		offset = 0
	}
	n := min(st.Size()-offset, slowReadMax)
	buf := make([]byte, n)
	if n > 0 {
		if _, err := f.ReadAt(buf, offset); err != nil && err != io.EOF {
			o.warn("slow log: %v", err)
			return
		}
	}
	entries, used := ParseSlowLog(buf)
	if used == 0 && n == slowReadMax {
		used = int(n) // one entry larger than a whole read: skipped rather than read again forever
	}
	for _, e := range entries {
		if len(o.Slow) >= maxSlow {
			break
		}
		o.Slow = append(o.Slow, e)
	}
	offset += int64(used)
	if offset >= slowTruncate && offset == st.Size() {
		if err := f.Truncate(0); err == nil {
			offset = 0
		}
	}
	o.Cursor.SlowLogInode, o.Cursor.SlowLogOffset = inode, offset
}

// ParseSlowLog parses MySQL / MariaDB slow log entries from b and returns them with the number of bytes they span: an
// entry at the end whose statement has not ended (no ";" yet) is left for the next read. Statements are normalized.
func ParseSlowLog(b []byte) ([]SlowEntry, int) {
	var (
		out   []SlowEntry
		e     *SlowEntry
		query strings.Builder
		db    string
		used  int
		pos   int
	)
	flush := func(end int) {
		if e != nil && query.Len() > 0 {
			q := strings.TrimSpace(query.String())
			e.Query = sqlnorm.Normalize(q, sqlnorm.MySQL)
			if e.Database == "" {
				e.Database = db
			}
			if e.Query != "" {
				out = append(out, *e)
			}
			used = end
		}
		e = nil
		query.Reset()
	}
	for pos < len(b) {
		nl := bytes.IndexByte(b[pos:], '\n')
		if nl < 0 {
			break // a line still being written
		}
		line := string(b[pos : pos+nl])
		start := pos
		pos += nl + 1
		switch {
		case strings.HasPrefix(line, "# Time:") || strings.HasPrefix(line, "# User@Host:"):
			// A new entry, unless the previous header lines are still collecting (Time then User@Host).
			if e == nil || query.Len() > 0 {
				flush(start)
				e = &SlowEntry{}
			}
			if t, ok := slowTime(strings.TrimSpace(strings.TrimPrefix(line, "# Time:"))); ok && strings.HasPrefix(line, "# Time:") {
				e.At = t
			}
		case strings.HasPrefix(line, "# "):
			if e == nil {
				continue
			}
			fields := strings.Fields(strings.TrimPrefix(line, "# "))
			for i := 0; i+1 < len(fields); i++ {
				switch fields[i] {
				case "Query_time:":
					e.DurationMS = num(fields[i+1]) * 1000
				case "Rows_examined:":
					e.Rows = int64(num(fields[i+1]))
				case "Schema:":
					e.Database = fields[i+1]
				}
			}
		case e == nil:
			// The header mysqld writes when it opens the file ("Tcp port: …", "Time  Id Command  Argument").
		case strings.HasPrefix(line, "use ") && strings.HasSuffix(line, ";") && query.Len() == 0:
			db = strings.Trim(strings.TrimSuffix(strings.TrimPrefix(line, "use "), ";"), "`")
			e.Database = db
		case strings.HasPrefix(line, "SET ") && strings.Contains(line, "timestamp=") && strings.HasSuffix(line, ";") && query.Len() == 0:
			// "SET timestamp=T;" or "SET last_insert_id=N,insert_id=M,timestamp=T;": the entry's header.
			for _, kv := range strings.Split(strings.TrimSuffix(strings.TrimPrefix(line, "SET "), ";"), ",") {
				if k, v, ok := strings.Cut(strings.TrimSpace(kv), "="); ok && k == "timestamp" {
					if ts, err := strconv.ParseInt(v, 10, 64); err == nil {
						e.At = time.Unix(ts, 0).UTC()
					}
				}
			}
		default:
			query.WriteString(line)
			query.WriteByte('\n')
			if strings.HasSuffix(strings.TrimSpace(line), ";") {
				flush(pos)
			}
		}
	}
	return out, used
}

// slowTime reads a slow log "# Time:" value: MySQL's RFC 3339 or MariaDB's "261014 12:00:00".
func slowTime(s string) (time.Time, bool) {
	if t, err := time.Parse(time.RFC3339Nano, s); err == nil {
		return t.UTC(), true
	}
	if t, err := time.Parse("060102 15:04:05", strings.Join(strings.Fields(s), " ")); err == nil {
		return t.UTC(), true
	}
	return time.Time{}, false
}
