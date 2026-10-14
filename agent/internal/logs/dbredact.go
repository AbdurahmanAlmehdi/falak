package logs

import (
	"regexp"
	"strings"
)

// pgLogFilter keeps SQL text and values out of a PostgreSQL container's log as it is shipped (docs/plans/OBSERVABILITY.md
// phase 3, "Privacy"): the engine writes slow statements, failing statements and bind parameters to stderr with their
// literals. Those lines are dropped (the database observer ships the slow ones normalized, kind db.slow), with the
// tab-indented lines continuing them; key values in constraint errors become ?. One filter per followed container: a
// continuation line belongs to the line before it.
type pgLogFilter struct {
	dropping bool // the last line was dropped: its continuation lines go too
}

var (
	// '%m [%p] %q%u@%d ' then the level (falak-db's log_line_prefix).
	pgPrefix   = regexp.MustCompile(`^(.*?\[\d+\] (?:\S*@\S* )?)([A-Z]+[0-9]?):\s+(.*)$`)
	pgStmtLog  = regexp.MustCompile(`^(?:duration: [0-9.]+ ms\s+)?(?:statement|execute [^:]*|bind [^:]*|parse [^:]*): `)
	pgKeyValue = regexp.MustCompile(`(Key \([^)]*\))=\(.*\)`)
	pgFailRow  = regexp.MustCompile(`(Failing row contains) \(.*\)`)
)

// filter returns the line to ship, or false to drop it.
func (f *pgLogFilter) filter(line string) (string, bool) {
	if strings.HasPrefix(line, "\t") {
		if f.dropping {
			return "", false
		}
		return redactPGValues(line), true
	}
	m := pgPrefix.FindStringSubmatch(line)
	if m == nil {
		f.dropping = false
		return redactPGValues(line), true
	}
	level, msg := m[2], m[3]
	drop := false
	switch level {
	case "STATEMENT", "QUERY":
		drop = true
	case "LOG":
		drop = pgStmtLog.MatchString(msg)
	case "DETAIL":
		drop = strings.HasPrefix(msg, "parameters:") || strings.HasPrefix(msg, "Parameters:")
	case "CONTEXT":
		// "SQL statement \"…\"", "unnamed portal with parameters: $1 = '…'"
		drop = strings.Contains(msg, "SQL statement") || strings.Contains(msg, "parameters:")
	}
	f.dropping = drop
	if drop {
		return "", false
	}
	return redactPGValues(line), true
}

// redactPGValues hides the values PostgreSQL prints in constraint errors: Key (email)=(a@b.c), Failing row contains (…).
func redactPGValues(line string) string {
	if strings.Contains(line, "Key (") {
		line = pgKeyValue.ReplaceAllString(line, "$1=(?)")
	}
	if strings.Contains(line, "Failing row contains") {
		line = pgFailRow.ReplaceAllString(line, "$1 (?)")
	}
	return line
}

// dbLineFilter is the filter for a container's lines (nil: shipped as they are).
func dbLineFilter(labels map[string]string) func(string) (string, bool) {
	if labels["falak.db.instance"] == "" || labels["falak.db.engine"] != "postgres" {
		return nil
	}
	return (&pgLogFilter{}).filter
}
