package logs

import (
	"regexp"
	"strings"
	"time"
)

// LogException is an exception found in a log record: a Laravel entry at ERROR or above whose context holds an
// exception with its stack trace. The Insights tee sends it like an APM exception, so plain Laravel sites (no APM
// package) get Issues too.
type LogException struct {
	Site       string // slug
	Type       string // the outermost exception class
	Message    string
	Stacktrace string // its "#0 /file.php(12): fn()" frames, as PHP's getTraceAsString prints them
	File       string // the log file's name
	At         time.Time
}

// ExceptionObserver receives the exceptions the tailer finds (the Insights tee, through the telemetry service).
type ExceptionObserver interface {
	ObserveLogException(LogException)
}

// Bounds of the exception parse of one entry.
const (
	// MaxExceptionStack bounds the stack trace taken from one entry (the tee's MaxStackBytes).
	MaxExceptionStack = 16 << 10
	// MaxExceptionMessage bounds the message (the control plane keeps 4 KiB too).
	MaxExceptionMessage = 4 << 10
	// maxExceptionEntry: only the first 64 KiB of an entry are parsed (its header, the exception and the top frames).
	maxExceptionEntry = 64 << 10
	// MaxExceptionParsesPerMinute bounds the entries parsed per site and minute (a crash loop), before any regex runs;
	// the tee then sends at most insights.MaxLogExceptionsPerMinute of them.
	MaxExceptionParsesPerMinute = 240
)

var (
	// laravelErrorHeader: Monolog's line format at the levels Laravel's exception handler and report() write.
	laravelErrorHeader = regexp.MustCompile(`^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\]\s+[A-Za-z0-9_-]+\.(ERROR|CRITICAL|ALERT|EMERGENCY):`)
	// laravelException is the context's "exception" as Monolog normalizes a Throwable: "[object] (Class(code: 0): message
	// at /file.php:12)" then "[stacktrace]" and the frames. The message may span lines and contain " at " (an SQL error's
	// "at line 1"): the match ends at the first " at <absolute file>:<line>)" followed by the stack trace, so the
	// [previous exception]'s is never taken instead.
	laravelException = regexp.MustCompile(`(?s)\[object\] \(([A-Za-z_\\][A-Za-z0-9_\\]*)\(code: [^)\n]*\): (.*?) at (/[^\n]*?|[A-Za-z]:[\\/][^\n]*?):(\d+)\)\n\[stacktrace\]\n`)
	// laravelTraceEnd ends the outermost exception's frames: its previous exception, or the end of the context.
	laravelTraceEnd = regexp.MustCompile(`\n\[previous exception\]|\n"[,}]`)
	// The context is JSON (slashes unescaped): a class name's backslashes and the quotes of a message are escaped.
	jsonUnescape = strings.NewReplacer(`\\`, `\`, `\"`, `"`, `\/`, `/`)
)

// ParseLaravelException extracts the exception of a merged Laravel entry. ok is false for entries below ERROR and for
// those without an exception and its stack trace (a plain Log::error("…")).
func ParseLaravelException(entry []byte) (LogException, bool) {
	if len(entry) > maxExceptionEntry {
		entry = entry[:maxExceptionEntry]
	}
	s := string(entry)
	h := laravelErrorHeader.FindStringSubmatch(s)
	if h == nil {
		return LogException{}, false
	}
	m := laravelException.FindStringSubmatchIndex(s)
	if m == nil {
		return LogException{}, false
	}
	rest := s[m[1]:]
	if end := laravelTraceEnd.FindStringIndex(rest); end != nil {
		rest = rest[:end[0]]
	}
	var frames []string
	for _, line := range strings.Split(rest, "\n") {
		if line = strings.TrimSpace(line); strings.HasPrefix(line, "#") {
			frames = append(frames, jsonUnescape.Replace(line))
		}
	}
	if len(frames) == 0 {
		return LogException{}, false
	}
	stack := strings.Join(frames, "\n")
	if len(stack) > MaxExceptionStack {
		stack = stack[:MaxExceptionStack]
	}
	msg := jsonUnescape.Replace(s[m[4]:m[5]])
	if len(msg) > MaxExceptionMessage {
		msg = strings.ToValidUTF8(msg[:MaxExceptionMessage], "")
	}
	return LogException{
		Type:       jsonUnescape.Replace(s[m[2]:m[3]]),
		Message:    msg,
		Stacktrace: stack,
		At:         laravelTime(h[1]),
	}, true
}

// laravelTime parses the entry's timestamp: Laravel's default "Y-m-d H:i:s" carries no zone. It is the app's
// config('app.timezone'), UTC unless the app changed it; the agent does not know the app's setting, so a zone-less time
// is taken as UTC (an app on another zone gets its log exceptions shifted by its offset in Insights; a time more than
// 5 minutes ahead is replaced by the arrival time there).
func laravelTime(s string) time.Time {
	for _, layout := range []string{time.RFC3339Nano, "2006-01-02T15:04:05.999999Z0700", "2006-01-02 15:04:05", "2006-01-02T15:04:05"} {
		if t, err := time.Parse(layout, s); err == nil {
			return t
		}
	}
	return time.Now()
}
