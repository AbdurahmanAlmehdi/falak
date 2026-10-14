package insights

import (
	"context"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/otlp"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
)

// A Laravel "[stacktrace]" block as the log writes it (JSON escapes already undone by the tailer).
const laravelStack = `#0 /srv/falak/sites/shop/releases/01JREL/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\Http\Controllers\Cart->show(Object(Illuminate\Http\Request))
#1 /srv/falak/sites/shop/releases/01JREL/app/Http/Middleware/Locale.php(20): Illuminate\Routing\Controller->callAction('show', Array)
#2 [internal function]: App\Http\Middleware\Locale->handle(Object(Illuminate\Http\Request), Object(Closure))
#3 {main}`

func TestParsePHPFrames(t *testing.T) {
	frames := ParsePHPFrames(laravelStack)
	want := []Frame{
		{File: "vendor/laravel/framework/src/Illuminate/Routing/Controller.php", Line: 54, Function: `App\Http\Controllers\Cart->show`, InApp: false},
		{File: "app/Http/Middleware/Locale.php", Line: 20, Function: `Illuminate\Routing\Controller->callAction`, InApp: true},
		{Function: `App\Http\Middleware\Locale->handle`},
	}
	if len(frames) != len(want) {
		t.Fatalf("frames = %+v", frames)
	}
	for i := range want {
		if frames[i] != want[i] {
			t.Fatalf("frame %d = %+v, want %+v", i, frames[i], want[i])
		}
	}
}

func TestExceptionKey(t *testing.T) {
	for _, tc := range []struct{ name, typ, stack, want string }{
		{"first in-app frame", `\DivisionByZeroError`, laravelStack, "DivisionByZeroError\napp/Http/Middleware/Locale.php:Illuminate\\Routing\\Controller->callAction"},
		{"line numbers and releases do not matter", "DivisionByZeroError",
			"#0 /srv/falak/sites/shop/releases/01JOTHER/app/Http/Middleware/Locale.php(99): Illuminate\\Routing\\Controller->callAction('index')",
			"DivisionByZeroError\napp/Http/Middleware/Locale.php:Illuminate\\Routing\\Controller->callAction"},
		{"vendor only: first frame with a file", "RuntimeException", "#0 /app/vendor/a/b.php(1): f()\n#1 /app/vendor/c/d.php(2): g()", "RuntimeException\n/app/vendor/a/b.php:f"},
		{"no frames", "RuntimeException", "", "RuntimeException"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if got := ExceptionKey(tc.typ, tc.stack); got != tc.want {
				t.Fatalf("key = %q, want %q", got, tc.want)
			}
		})
	}
}

func logException(at time.Time) LogException {
	return LogException{SiteID: "01SITE", Type: "DivisionByZeroError", Message: "Division by zero", Stacktrace: laravelStack, Name: "laravel.log", At: at}
}

func apmBatch(site string, sp *tracepb.Span) []*tracepb.ResourceSpans {
	return []*tracepb.ResourceSpans{{
		Resource:   &resourcepb.Resource{Attributes: []*commonpb.KeyValue{otlp.Str("falak.site.id", site)}},
		ScopeSpans: []*tracepb.ScopeSpans{{Spans: []*tracepb.Span{sp}}},
	}}
}

func TestLogExceptionIsHeldThenSent(t *testing.T) {
	p := &fakePoster{}
	now := base
	tee := New(p, func() time.Time { return now }, nil)
	tee.ObserveLogException(logException(base.Add(-time.Second)))
	tee.ObserveLogException(LogException{SiteID: "", Type: "X"}) // no site: nowhere to land
	tee.Flush(context.Background())
	if len(p.byKind("exception")) != 0 {
		t.Fatal("sent before the hold is over")
	}
	now = base.Add(LogHold)
	tee.Flush(context.Background())
	got := p.byKind("exception")
	if len(got) != 1 {
		t.Fatalf("lines = %v", p.lines)
	}
	e := got[0]
	if e["site_id"] != "01SITE" || e["type"] != "DivisionByZeroError" || e["message"] != "Division by zero" || e["event_type"] != "log" ||
		e["route_or_name"] != "laravel.log" || e["handled"] != false || e["trace_id"] != "" || e["stacktrace"] != laravelStack ||
		e["at"] != base.Add(-time.Second).Format(time.RFC3339Nano) {
		t.Fatalf("line = %v", e)
	}
}

// A site whose APM package sends spans reports its exceptions itself: the log-derived ones are dropped.
func TestLogExceptionDroppedWhenTheSiteRunsAPM(t *testing.T) {
	p := &fakePoster{}
	now := base
	tee := New(p, func() time.Time { return now }, nil)
	tee.ObserveLogException(logException(base))
	// The request span arrives a few seconds after the log line, within the hold.
	now = base.Add(5 * time.Second)
	tee.ObserveSpans(apmBatch("01SITE", span("GET /cart", 0, 10, otlp.Str("falak.event.type", "request"))))
	now = base.Add(LogHold + time.Second)
	tee.Flush(context.Background())
	if n := len(p.byKind("exception")); n != 0 {
		t.Fatalf("%d exceptions sent", n)
	}
	// Still active 10 minutes later; a different site is not affected.
	now = base.Add(10 * time.Minute)
	tee.ObserveLogException(logException(now))
	other := logException(now)
	other.SiteID = "01OTHER"
	tee.ObserveLogException(other)
	now = now.Add(LogHold)
	tee.Flush(context.Background())
	if got := p.byKind("exception"); len(got) != 1 || got[0]["site_id"] != "01OTHER" {
		t.Fatalf("lines = %v", got)
	}
	// After APMActiveWindow without spans, the site's log exceptions are sent again.
	now = base.Add(5*time.Second + APMActiveWindow + time.Minute)
	tee.ObserveLogException(logException(now))
	now = now.Add(LogHold)
	tee.Flush(context.Background())
	if got := p.byKind("exception"); len(got) != 2 {
		t.Fatalf("lines = %v", got)
	}
	if tee.deduplicated != 2 {
		t.Fatalf("deduplicated = %d", tee.deduplicated)
	}
}

// An exception span event without falak.event.type (an app traced by a plain OTel SDK) with the same key, close in
// time, is the same exception; another exception of the same site is not.
func TestLogExceptionDroppedWhenAnAPMExceptionMatches(t *testing.T) {
	p := &fakePoster{}
	now := base
	tee := New(p, func() time.Time { return now }, nil)
	sp := span("handle", 0, 10)
	sp.Events = []*tracepb.Span_Event{{Name: "exception", TimeUnixNano: uint64(base.Add(2 * time.Second).UnixNano()), Attributes: []*commonpb.KeyValue{
		otlp.Str("exception.type", "DivisionByZeroError"), otlp.Str("exception.message", "Division by zero"),
		otlp.Str("exception.stacktrace", "#0 /srv/falak/sites/shop/releases/01JNEW/app/Http/Middleware/Locale.php(21): Illuminate\\Routing\\Controller->callAction('show', Array)"),
	}}}
	tee.ObserveSpans(apmBatch("01SITE", sp))
	tee.ObserveLogException(logException(base))
	unrelated := logException(base)
	unrelated.Type = "TypeError"
	tee.ObserveLogException(unrelated)
	now = base.Add(LogHold)
	tee.Flush(context.Background())
	got := p.byKind("exception")
	// The APM's own exception line, plus the unrelated log exception.
	if len(got) != 2 || got[0]["event_type"] != "" || got[1]["type"] != "TypeError" {
		t.Fatalf("lines = %v", got)
	}
}

func TestLogExceptionsPerMinuteCapAndDisabled(t *testing.T) {
	p := &fakePoster{}
	now := base
	tee := New(p, func() time.Time { return now }, nil)
	for range MaxLogExceptionsPerMinute + 5 {
		tee.ObserveLogException(logException(base))
	}
	now = base.Add(LogHold)
	tee.Flush(context.Background())
	if n := len(p.byKind("exception")); n != MaxLogExceptionsPerMinute {
		t.Fatalf("%d sent", n)
	}
	if _, dropped, _ := tee.Stats(); dropped != 5 {
		t.Fatalf("dropped = %d", dropped)
	}
	tee.SetEnabled(false)
	now = base.Add(2 * time.Minute)
	tee.ObserveLogException(logException(now))
	now = now.Add(LogHold)
	tee.Flush(context.Background())
	if n := len(p.byKind("exception")); n != MaxLogExceptionsPerMinute {
		t.Fatalf("%d sent while disabled", n)
	}
}
