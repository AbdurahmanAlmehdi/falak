// Package sqlnorm turns SQL text into its shape: literals become `?`, so a slow query or a top statement can be stored
// and shown without the values it ran with (docs/plans/OBSERVABILITY.md phase 3, "Privacy"). It is a scanner, not a
// parser: it never fails, and text it does not understand is kept as it is, minus anything that looks like a value.
package sqlnorm

import (
	"crypto/sha256"
	"encoding/hex"
	"regexp"
	"strings"
	"unicode"
	"unicode/utf8"
)

// MaxLen is the longest normalized text kept (in bytes, cut at a rune boundary).
const MaxLen = 2000

// Dialect changes how double quotes read: an identifier in PostgreSQL, a string in MySQL (without ANSI_QUOTES).
type Dialect int

const (
	Postgres Dialect = iota
	MySQL
)

// Normalize returns the shape of a statement: string, numeric, hex, bit and dollar-quoted literals as `?`, comments
// dropped, whitespace collapsed, lists of placeholders folded (`IN (?, ?, ?)` → `IN (?, ...)`, repeated VALUES tuples
// into one), at most MaxLen bytes. Placeholders already there (`$1`, `?`) stay.
func Normalize(sql string, d Dialect) string {
	var b strings.Builder
	b.Grow(min(len(sql), MaxLen+16))
	space := false
	emit := func(s string) {
		if space && b.Len() > 0 {
			b.WriteByte(' ')
		}
		space = false
		b.WriteString(s)
	}
	i := 0
	n := len(sql)
	for i < n && b.Len() <= MaxLen*2 {
		c := sql[i]
		switch {
		case c == ' ' || c == '\t' || c == '\n' || c == '\r' || c == '\f' || c == '\v':
			space = true
			i++
		case c == '-' && i+1 < n && sql[i+1] == '-':
			// -- comment to the end of the line
			for i < n && sql[i] != '\n' {
				i++
			}
			space = true
		case c == '#' && d == MySQL:
			for i < n && sql[i] != '\n' {
				i++
			}
			space = true
		case c == '/' && i+1 < n && sql[i+1] == '*':
			i = skipComment(sql, i, d == Postgres)
			space = true
		case c == '\'':
			i = skipQuoted(sql, i, '\'', d == MySQL)
			emit("?")
		case c == '"' && d == MySQL:
			i = skipQuoted(sql, i, '"', true)
			emit("?")
		case c == '"' || c == '`':
			// An identifier: kept.
			j := skipQuoted(sql, i, c, false)
			emit(sql[i:j])
			i = j
		case c == '$' && i+1 < n && (sql[i+1] == '$' || isIdentStart(sql[i+1])):
			// $tag$ … $tag$ (dollar quoting), or $1 / a name.
			if j, ok := dollarQuoted(sql, i); ok {
				emit("?")
				i = j
				continue
			}
			j := i + 1
			for j < n && (isIdentPart(sql[j])) {
				j++
			}
			emit(sql[i:j])
			i = j
		case (c == 'x' || c == 'X' || c == 'b' || c == 'B' || c == 'e' || c == 'E' || c == 'n' || c == 'N') && i+1 < n && sql[i+1] == '\'' && !prevIdent(sql, i):
			// X'ff', B'0101', E'\n', N'text'
			i = skipQuoted(sql, i+1, '\'', d == MySQL || c == 'e' || c == 'E')
			emit("?")
		case c >= '0' && c <= '9' && !prevIdent(sql, i):
			i = skipNumber(sql, i)
			emit("?")
		case c == '.' && i+1 < n && sql[i+1] >= '0' && sql[i+1] <= '9' && !prevIdent(sql, i):
			i = skipNumber(sql, i)
			emit("?")
		case isIdentStart(c) || c >= utf8.RuneSelf:
			j := i
			for j < n && (isIdentPart(sql[j]) || sql[j] >= utf8.RuneSelf) {
				j++
			}
			if j == i {
				j++
			}
			emit(sql[i:j])
			i = j
		default:
			// Operators and punctuation: no space before ",", ")" or after "(".
			if c == ',' || c == ')' || c == ';' {
				space = false
			}
			emit(string(c))
			switch c {
			case '(':
				space = false
			case ',':
				space = true // "a,b" and "a, b" are one shape
			}
			i++
		}
	}
	out := foldLists(b.String())
	out = strings.TrimRight(out, "; ")
	if len(out) > 4 && strings.EqualFold(out[:4], "SET ") {
		// SET x = value / SET x TO value: an unquoted value is a value too (search_path, a role, a level).
		out = setValues(out)
	}
	return truncate(out, MaxLen)
}

// Fingerprint is a short stable id of a normalized text (the shape of a statement with no id of its own).
func Fingerprint(normalized string) string {
	sum := sha256.Sum256([]byte(strings.ToLower(normalized)))
	return hex.EncodeToString(sum[:8])
}

var setValue = regexp.MustCompile(`(?i)(=\s*|\sTO\s+)\S.*$`)

// setValues makes every value of a SET statement ?: after "=" / "TO", and the further items of a list
// (search_path = a, b).
func setValues(s string) string {
	parts := strings.Split(s, ", ")
	for i, p := range parts {
		switch {
		case setValue.MatchString(p):
			parts[i] = setValue.ReplaceAllString(p, "${1}?")
		case i > 0:
			parts[i] = "?"
		}
	}
	return strings.Join(parts, ", ")
}

// skipComment returns the index after the /* comment */ at s[i]; PostgreSQL nests them.
func skipComment(s string, i int, nested bool) int {
	depth := 0
	for j := i; j+1 < len(s); j++ {
		switch {
		case s[j] == '/' && s[j+1] == '*':
			if depth > 0 && !nested {
				continue
			}
			depth++
			j++
		case s[j] == '*' && s[j+1] == '/':
			depth--
			j++
			if depth == 0 {
				return j + 1
			}
		}
	}
	return len(s)
}

func isIdentStart(c byte) bool {
	return c == '_' || (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z')
}

func isIdentPart(c byte) bool { return isIdentStart(c) || (c >= '0' && c <= '9') || c == '$' }

// prevIdent: the byte before i continues an identifier (t1, col_2), so a digit there is no number.
func prevIdent(s string, i int) bool {
	if i == 0 {
		return false
	}
	p := s[i-1]
	return isIdentPart(p) || p >= utf8.RuneSelf
}

// skipQuoted returns the index after a quoted run starting at s[i] == q. A doubled quote is an escaped one; with
// backslashes (MySQL strings, PostgreSQL E-strings) a backslash escapes the next byte. An unterminated run ends the text.
func skipQuoted(s string, i int, q byte, backslash bool) int {
	j := i + 1
	for j < len(s) {
		switch {
		case backslash && s[j] == '\\':
			j += 2
		case s[j] == q:
			if j+1 < len(s) && s[j+1] == q {
				j += 2
				continue
			}
			return j + 1
		default:
			j++
		}
	}
	return len(s)
}

// dollarQuoted reads a PostgreSQL dollar-quoted string ($$…$$, $tag$…$tag$) starting at s[i] == '$'.
func dollarQuoted(s string, i int) (int, bool) {
	j := i + 1
	for j < len(s) && s[j] != '$' {
		if !isIdentPart(s[j]) || s[j] == '$' {
			return 0, false
		}
		j++
	}
	if j >= len(s) {
		return 0, false
	}
	tag := s[i : j+1]
	// $1 is a parameter, not a tag (tags can't start with a digit).
	if len(tag) > 2 && tag[1] >= '0' && tag[1] <= '9' {
		return 0, false
	}
	end := strings.Index(s[j+1:], tag)
	if end < 0 {
		return len(s), true
	}
	return j + 1 + end + len(tag), true
}

func skipNumber(s string, i int) int {
	j := i
	if j+1 < len(s) && s[j] == '0' && (s[j+1] == 'x' || s[j+1] == 'X' || s[j+1] == 'b' || s[j+1] == 'B') {
		j += 2
		for j < len(s) && isHex(s[j]) {
			j++
		}
		return j
	}
	for j < len(s) && (s[j] >= '0' && s[j] <= '9' || s[j] == '.' || s[j] == '_') {
		j++
	}
	if j < len(s) && (s[j] == 'e' || s[j] == 'E') {
		k := j + 1
		if k < len(s) && (s[k] == '+' || s[k] == '-') {
			k++
		}
		if k < len(s) && s[k] >= '0' && s[k] <= '9' {
			j = k
			for j < len(s) && s[j] >= '0' && s[j] <= '9' {
				j++
			}
		}
	}
	return j
}

func isHex(c byte) bool {
	return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F')
}

// foldLists folds `(?, ?, ?)` (and `($1, $2, $3)`) into `(?, ...)`, and a run of such tuples (`VALUES (?, ?), (?, ?)`)
// into its first followed by `, ...`.
func foldLists(s string) string {
	var b strings.Builder
	i := 0
	for i < len(s) {
		if s[i] == '(' {
			if j, count, ok := placeholderList(s, i); ok {
				if count > 1 {
					b.WriteString("(?, ...)")
				} else {
					b.WriteString("(?)")
				}
				// Repeated tuples: ", (...)" as long as they are lists too.
				k, folded := j, false
				for strings.HasPrefix(s[k:], ", (") {
					m, _, ok := placeholderList(s, k+2)
					if !ok {
						break
					}
					k, folded = m, true
				}
				if folded {
					b.WriteString(", ...")
				}
				i = k
				continue
			}
		}
		b.WriteByte(s[i])
		i++
	}
	return b.String()
}

// placeholderList reads "(p, p, ...)" at s[i] where every p is ? or $n: its end and how many there are.
func placeholderList(s string, i int) (end, count int, ok bool) {
	j := i + 1
	for j < len(s) {
		switch {
		case s[j] == '?':
			j++
		case s[j] == '$' && j+1 < len(s) && s[j+1] >= '0' && s[j+1] <= '9':
			j++
			for j < len(s) && s[j] >= '0' && s[j] <= '9' {
				j++
			}
		default:
			return 0, 0, false
		}
		count++
		if j < len(s) && s[j] == ')' {
			return j + 1, count, true
		}
		if !strings.HasPrefix(s[j:], ", ") {
			return 0, 0, false
		}
		j += 2
	}
	return 0, 0, false
}

func truncate(s string, max int) string {
	if len(s) <= max {
		return s
	}
	cut := max
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}
	return s[:cut]
}

// Clean makes a free-text message (an engine error) safe to store: quoted values become ?, numbers stay (they are
// pids, durations and codes), control characters go, at most max bytes.
func Clean(msg string, max int) string {
	var b strings.Builder
	i := 0
	for i < len(msg) {
		c := msg[i]
		switch {
		case c == '\'':
			// 'value' → ?; an apostrophe inside a word (don't) is kept.
			if i > 0 && unicode.IsLetter(rune(msg[i-1])) {
				b.WriteByte(c)
				i++
				continue
			}
			i = skipQuoted(msg, i, '\'', false)
			b.WriteString("?")
		case c == '"':
			i = skipQuoted(msg, i, '"', false)
			b.WriteString("?")
		case c < 0x20 || c == 0x7f:
			b.WriteByte(' ')
			i++
		default:
			b.WriteByte(c)
			i++
		}
	}
	return truncate(strings.Join(strings.Fields(b.String()), " "), max)
}
