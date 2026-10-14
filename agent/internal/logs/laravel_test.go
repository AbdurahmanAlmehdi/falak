package logs

import (
	"os"
	"strings"
	"sync"
	"testing"
	"time"
	"unicode/utf8"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

const laravelEntry = `[2026-10-14 10:11:12] production.ERROR: Division by zero {"userId":1,"exception":"[object] (DivisionByZeroError(code: 0): Division by zero at /srv/falak/sites/shop/releases/01JREL/app/Http/Controllers/Cart.php:12)
[stacktrace]
#0 /srv/falak/sites/shop/releases/01JREL/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\\Http\\Controllers\\Cart->show(Object(Illuminate\\Http\\Request))
#1 /srv/falak/sites/shop/releases/01JREL/app/Http/Middleware/Locale.php(20): Illuminate\\Routing\\Controller->callAction('show', Array)
#2 [internal function]: App\\Http\\Middleware\\Locale->handle(Object(Illuminate\\Http\\Request), Object(Closure))
#3 {main}
"} `

func TestParseLaravelException(t *testing.T) {
	for _, tc := range []struct {
		name              string
		entry             string
		ok                bool
		typ, msg, firstLn string
		frames            int
		at                time.Time
	}{
		{name: "error with a trace", entry: laravelEntry, ok: true, typ: "DivisionByZeroError", msg: "Division by zero",
			firstLn: `#0 /srv/falak/sites/shop/releases/01JREL/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\Http\Controllers\Cart->show(Object(Illuminate\Http\Request))`,
			frames:  4, at: time.Date(2026, 10, 14, 10, 11, 12, 0, time.UTC)},
		{name: "critical, namespaced class, message with ' at ' and quotes", ok: true, typ: `Illuminate\Database\QueryException`,
			msg:    `SQLSTATE[42S02]: Base table or view not found: 1146 Table "shop.carts" doesn't exist at line 1 (Connection: mysql, SQL: select * from carts)`,
			frames: 2, firstLn: `#0 /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(745): Illuminate\Database\Connection->runQueryCallback('select * from c...', Array, Object(Closure))`,
			entry: `[2026-10-14T10:11:12.123456+00:00] local.CRITICAL: SQLSTATE[42S02] {"exception":"[object] (Illuminate\\Database\\QueryException(code: 42S02): SQLSTATE[42S02]: Base table or view not found: 1146 Table \"shop.carts\" doesn't exist at line 1 (Connection: mysql, SQL: select * from carts) at /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php:793)
[stacktrace]
#0 /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(745): Illuminate\\Database\\Connection->runQueryCallback('select * from c...', Array, Object(Closure))
#1 {main}

[previous exception] [object] (PDOException(code: 42S02): SQLSTATE[42S02] at /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php:406)
[stacktrace]
#0 /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(406): PDO->prepare('select * from c...')
#1 {main}
"} `, at: time.Date(2026, 10, 14, 10, 11, 12, 123456000, time.UTC)},
		{name: "warning is not an exception", entry: strings.Replace(laravelEntry, "production.ERROR", "production.WARNING", 1)},
		{name: "plain error message", entry: `[2026-10-14 10:11:12] production.ERROR: Payment failed {"order":12}`},
		{name: "exception without a trace", entry: `[2026-10-14 10:11:12] production.ERROR: boom {"exception":"[object] (RuntimeException(code: 0): boom at /app/x.php:3)"}`},
		{name: "not a Laravel entry", entry: `ERROR: something [object] (X(code: 0): y at /a.php:1)` + "\n[stacktrace]\n#0 {main}\n"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			ex, ok := ParseLaravelException([]byte(tc.entry))
			if ok != tc.ok {
				t.Fatalf("ok = %v, want %v (%+v)", ok, tc.ok, ex)
			}
			if !ok {
				return
			}
			lines := strings.Split(ex.Stacktrace, "\n")
			if ex.Type != tc.typ || ex.Message != tc.msg || len(lines) != tc.frames || lines[0] != tc.firstLn || !ex.At.Equal(tc.at) {
				t.Fatalf("got type %q msg %q at %s frames %d\n%s", ex.Type, ex.Message, ex.At, len(lines), ex.Stacktrace)
			}
			if strings.Contains(ex.Stacktrace, "PDO->prepare") {
				t.Fatal("previous exception's frames included")
			}
		})
	}
}

type exceptionRecorder struct {
	mu  sync.Mutex
	got []LogException
}

func (r *exceptionRecorder) ObserveLogException(e LogException) {
	r.mu.Lock()
	r.got = append(r.got, e)
	r.mu.Unlock()
}

// The tailer hands the merged Laravel entries with a trace to the exception observer, with the site and file name.
func TestTailerReportsLaravelExceptions(t *testing.T) {
	fs := hostfs.FS{Root: t.TempDir()}
	p := fs.P("/srv/falak/sites/shop/shared/storage/logs/laravel.log")
	mkfile(t, p, "")
	sink := &memSink{}
	rec := &exceptionRecorder{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.SetExceptionObserver(rec)
	tl.SetSources([]Source{
		{Path: "/srv/falak/sites/shop/shared/storage/logs/*.log", Site: "shop", Kind: "app", Multiline: "laravel"},
		{Path: "/var/log/plain/*.log", Site: "shop"}, // line by line: never parsed for exceptions
	})
	tl.Poll()
	appendFile(t, p, laravelEntry+"\n[2026-10-14 10:11:13] production.INFO: fine\n")
	mkfile(t, fs.P("/var/log/plain/app.log"), laravelEntry+"\n")
	tl.Poll()
	tl.Poll()
	if len(rec.got) != 1 {
		t.Fatalf("exceptions = %+v", rec.got)
	}
	if e := rec.got[0]; e.Site != "shop" || e.File != "laravel.log" || e.Type != "DivisionByZeroError" {
		t.Fatalf("exception = %+v", e)
	}
	if len(sink.recs) < 2 {
		t.Fatal("records not shipped")
	}
	_ = os.Remove(p)
}

// Only the first 64 KiB of an entry are parsed; the message is cut at 4 KiB on a character boundary.
func TestParseLaravelExceptionBounds(t *testing.T) {
	long := strings.Repeat("é", MaxExceptionMessage) // 2 bytes each
	entry := strings.Replace(laravelEntry, "): Division by zero at", "): "+long+" at", 1)
	ex, ok := ParseLaravelException([]byte(entry))
	if !ok || len(ex.Message) > MaxExceptionMessage || !strings.HasPrefix(ex.Message, "éé") || !utf8.ValidString(ex.Message) {
		t.Fatalf("ok %v, message of %d bytes", ok, len(ex.Message))
	}
	// The exception after 64 KiB of message is not looked for.
	huge := strings.Replace(laravelEntry, "Division by zero {", strings.Repeat("x", maxExceptionEntry)+" {", 1)
	if _, ok := ParseLaravelException([]byte(huge)); ok {
		t.Fatal("parsed past 64 KiB")
	}
}

// A crash loop's errors are parsed at most MaxExceptionParsesPerMinute times per site and minute.
func TestExceptionParsesAreRateLimited(t *testing.T) {
	tl := NewTailer(hostfs.FS{Root: t.TempDir()}, "/var/lib/falak", &memSink{}, nil)
	for range MaxExceptionParsesPerMinute {
		if !tl.mayParseException("shop") {
			t.Fatal("limited too early")
		}
	}
	if tl.mayParseException("shop") || !tl.mayParseException("blog") {
		t.Fatal("not limited per site")
	}
}
