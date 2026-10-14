package insights

import (
	"regexp"
	"strings"
	"time"
)

// Exceptions found in log files (a Laravel entry with a stack trace, logs.ParseLaravelException) reach Insights
// without the APM package. A site that also runs the APM package reports each exception twice, as a span event and as
// a log line: a log-derived exception is dropped when an APM exception with the same key (class + first in-app frame)
// arrived close to when the log line was read. Only that: a site running the APM package still logs exceptions the APM
// never sees (queue supervisors, Horizon, artisan commands), and those must reach Insights. The match uses the time the
// line was read, not the entry's own timestamp (Laravel writes it in the app's timezone, without the zone). Both pass
// through this agent, so the tee decides alone.
const (
	// LogHold is how long a log-derived exception waits before it is sent: the span of the same exception is exported
	// a few seconds after the log line is written.
	LogHold = 15 * time.Second
	// APMActiveWindow is how long an APM exception's key is remembered for matching.
	APMActiveWindow = 15 * time.Minute
	// APMMatchWindow: an APM exception with the same key within this much of the log line's reading is the same
	// exception.
	APMMatchWindow = time.Minute
	// MaxLogExceptionsPerMinute bounds the log-derived exceptions of one site per minute (a crash loop logging the same
	// error thousands of times); the rest are counted as dropped.
	MaxLogExceptionsPerMinute = 120
	// maxAPMExceptions bounds the APM exception keys remembered per site.
	maxAPMExceptions = 1000
)

// LogException is an exception found in a site's log (site already resolved to its id).
type LogException struct {
	SiteID     string
	Type       string
	Message    string
	Stacktrace string
	Name       string // the log file's name: the line's route_or_name
	At         time.Time
}

type heldLog struct {
	e     LogException
	key   string
	seen  time.Time // when the line was read (matched against APM exceptions)
	until time.Time
}

type apmException struct {
	key string
	at  time.Time
}

// Frame is one frame of a PHP stack trace ("#0 /srv/…/app/Foo.php(12): App\Foo->bar()").
type Frame struct {
	File     string // release paths stripped (releases/<id>/ or current/)
	Line     int
	Function string // without its arguments
	InApp    bool   // not vendor/, not an internal function
}

var (
	phpFrame    = regexp.MustCompile(`^#\d+\s+(?:(.+?)\((\d+)\)|\[internal function\]):\s*(.*)$`)
	releasePath = regexp.MustCompile(`^.*?/(?:releases/[^/]+|current)/`)
	callArgs    = regexp.MustCompile(`\(.*$`)
	anonClass   = regexp.MustCompile(`class@anonymous[^\s:>-]*`)
)

// ParsePHPFrames parses the frames of a PHP stack trace as getTraceAsString (Laravel's "[stacktrace]") prints them,
// normalized like the control plane's Insights StackTrace: release paths stripped, arguments dropped. "{main}" and
// lines that are not frames are skipped.
func ParsePHPFrames(stack string) []Frame {
	var out []Frame
	for _, line := range strings.Split(stack, "\n") {
		m := phpFrame.FindStringSubmatch(strings.TrimSpace(line))
		if m == nil {
			continue
		}
		f := Frame{Function: strings.TrimSpace(anonClass.ReplaceAllString(callArgs.ReplaceAllString(strings.TrimSpace(m[3]), ""), "class@anonymous"))}
		if m[1] != "" {
			f.File = releasePath.ReplaceAllString(strings.TrimPrefix(strings.TrimSpace(m[1]), "file://"), "")
			for _, c := range m[2] {
				f.Line = f.Line*10 + int(c-'0')
			}
			f.InApp = !strings.HasPrefix(f.File, "vendor/") && !strings.Contains(f.File, "/vendor/")
		}
		out = append(out, f)
	}
	return out
}

// ExceptionKey identifies an exception for de-duplication: its class and its first in-app frame (file and function,
// no line number), else its first frame with a file. The control plane groups Issues by the class and the top in-app
// frames, so two exceptions with the same key land in the same Issue.
func ExceptionKey(typ, stack string) string {
	typ = strings.TrimLeft(strings.TrimSpace(typ), `\`)
	var first *Frame
	frames := ParsePHPFrames(stack)
	for i := range frames {
		if frames[i].InApp {
			first = &frames[i]
			break
		}
		if first == nil && frames[i].File != "" {
			first = &frames[i]
		}
	}
	if first == nil {
		return typ
	}
	return typ + "\n" + first.File + ":" + first.Function
}

// ObserveLogException queues an exception found in a site's log; it is sent after LogHold unless the site's APM
// reported it.
func (t *Tee) ObserveLogException(e LogException) {
	if !t.enabled.Load() || e.SiteID == "" || e.Type == "" {
		return
	}
	now := t.now()
	t.mu.Lock()
	defer t.mu.Unlock()
	minute := now.Unix() / 60
	if t.logPerMinute == nil || t.logMinute != minute {
		t.logPerMinute, t.logMinute = map[string]int{}, minute
	}
	if t.logPerMinute[e.SiteID] >= MaxLogExceptionsPerMinute {
		t.dropped++
		return
	}
	t.logPerMinute[e.SiteID]++
	if len(e.Stacktrace) > MaxStackBytes {
		e.Stacktrace = e.Stacktrace[:MaxStackBytes]
	}
	t.held = append(t.held, heldLog{e: e, key: ExceptionKey(e.Type, e.Stacktrace), seen: now, until: now.Add(LogHold)})
	if over := len(t.held) - MaxPending; over > 0 {
		t.held = t.held[over:]
		t.dropped += int64(over)
	}
}

// HeldLogExceptions returns the log-derived exceptions waiting for their hold to end (status, tests).
func (t *Tee) HeldLogExceptions() []LogException {
	t.mu.Lock()
	defer t.mu.Unlock()
	out := make([]LogException, 0, len(t.held))
	for _, h := range t.held {
		out = append(out, h.e)
	}
	return out
}

// observeAPMLocked remembers the keys of a site's APM exceptions.
func (t *Tee) observeAPMLocked(site string, exc []apmException) {
	if site == "" {
		return
	}
	if t.apmExc == nil {
		t.apmExc = map[string][]apmException{}
	}
	if len(exc) > 0 {
		list := append(t.apmExc[site], exc...)
		if over := len(list) - maxAPMExceptions; over > 0 {
			list = list[over:]
		}
		t.apmExc[site] = list
	}
}

// releaseHeldLocked sends the log-derived exceptions whose hold is over, unless the APM reported them, and forgets
// what the de-duplication no longer needs.
func (t *Tee) releaseHeldLocked() {
	now := t.now()
	keep := t.held[:0]
	for _, h := range t.held {
		if h.until.After(now) {
			keep = append(keep, h)
			continue
		}
		if t.reportedByAPMLocked(h) {
			t.deduplicated++
			continue
		}
		t.enqueueLocked(Exception{
			Kind: "exception", SiteID: h.e.SiteID, Type: h.e.Type, Message: h.e.Message, Stacktrace: h.e.Stacktrace,
			// Laravel logs what its exception handler reports: unhandled, or handled and report()ed; the log cannot tell.
			Handled: false, EventType: "log", RouteOrName: h.e.Name,
			At: h.e.At.UTC().Format(time.RFC3339Nano),
		})
	}
	t.held = keep
	for site, list := range t.apmExc {
		fresh := list[:0]
		for _, x := range list {
			if now.Sub(x.at) <= APMActiveWindow {
				fresh = append(fresh, x)
			}
		}
		if len(fresh) == 0 {
			delete(t.apmExc, site)
		} else {
			t.apmExc[site] = fresh
		}
	}
}

func (t *Tee) reportedByAPMLocked(h heldLog) bool {
	for _, x := range t.apmExc[h.e.SiteID] {
		if x.key == h.key && absDuration(x.at.Sub(h.seen)) <= APMMatchWindow {
			return true
		}
	}
	return false
}

func absDuration(d time.Duration) time.Duration {
	if d < 0 {
		return -d
	}
	return d
}
