package telemetry

import (
	"context"
	"encoding/json"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/logs"
	"github.com/OthmanHaba/falak/agent/internal/otlp"
)

// The fields of logs.container_files are validated before anything is applied.
func TestValidateLogSourcesV2(t *testing.T) {
	for _, tc := range []struct {
		name string
		src  logs.Source
		ok   bool
	}{
		{"container source", logs.Source{Path: "/var/www/html/storage/logs/*.log", Site: "shop", Container: true, ComposeService: "app"}, true},
		{"container source without its site", logs.Source{Path: "/app/x.log", Container: true}, false},
		{"dot-dot inside the container", logs.Source{Path: "/app/../../etc/shadow", Site: "shop", Container: true}, false},
		{"relative path", logs.Source{Path: "app/x.log", Site: "shop", Container: true}, false},
		{"access kind in a container", logs.Source{Path: "/app/x.log", Site: "shop", Container: true, Kind: "access"}, false},
		{"compose service without container", logs.Source{Path: "/app/x.log", Site: "shop", ComposeService: "app"}, false},
		{"bad compose service", logs.Source{Path: "/app/x.log", Site: "shop", Container: true, ComposeService: "-app"}, false},
		{"pattern", logs.Source{Path: "/var/log/falak/php-fpm-slow/shop.log", Site: "shop", Origin: "php_fpm_slow", MultilinePattern: `^\[\d{2}-`}, true},
		{"bad pattern", logs.Source{Path: "/app/x.log", MultilinePattern: `^(`}, false},
		{"long pattern", logs.Source{Path: "/app/x.log", MultilinePattern: strings.Repeat("a", 257)}, false},
		{"unknown origin", logs.Source{Path: "/app/x.log", Origin: "journal"}, false},
		{"php_fpm origin", logs.Source{Path: "/var/log/falak/fpm-shop.log", Site: "shop", Origin: "php_fpm"}, true},
		{"proc in a container", logs.Source{Path: "/proc/kmsg", Site: "shop", Container: true}, false},
		{"sys glob in a container", logs.Source{Path: "/sys/*/x", Site: "shop", Container: true}, false},
		{"dev in a container", logs.Source{Path: "/dev/stdout", Site: "shop", Container: true}, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			err := validateSourceV2(tc.src)
			if (err == nil) != tc.ok {
				t.Fatalf("validate = %v, want ok %v", err, tc.ok)
			}
		})
	}
}

// A source the agent refuses is skipped, never the whole telemetry.configure: the other sources and settings apply.
func TestConfigureSkipsOnlyARefusedSource(t *testing.T) {
	s, _ := newService(t, "", nil)
	p := Payload{LogSources: []logs.Source{
		{Path: "/var/www/*.log", Site: "shop", Container: true, MultilinePattern: `^(?=x)`}, // RE2 has no lookahead
		{Path: "/proc/kmsg", Site: "shop", Container: true},
		{Path: "/srv/falak/sites/shop/shared/storage/logs/*.log", Site: "shop", Multiline: "laravel"},
	}}
	if err := validate(p); err != nil {
		t.Fatalf("payload refused: %v", err)
	}
	s.apply(p)
	var paths []string
	for _, src := range s.tailer.Sources() {
		paths = append(paths, src.Path)
	}
	if strings.Join(paths, "|") != "/srv/falak/sites/shop/shared/storage/logs/*.log|"+logs.AccessLogDir+"/*.log" {
		t.Fatalf("sources = %q", paths)
	}
}

// The control plane accepts a start pattern only when Go's RE2 compiles it too (Sites' SiteLogSources::patternError
// reads the same corpus): every accepted pattern must compile here.
func TestMultilinePatternCorpusCompiles(t *testing.T) {
	b, err := os.ReadFile("../../../contracts/agent-protocol/testdata/multiline-patterns.json")
	if err != nil {
		t.Fatal(err)
	}
	var corpus struct {
		Accepted []string `json:"accepted"`
		Rejected []string `json:"rejected"`
	}
	if err := json.Unmarshal(b, &corpus); err != nil {
		t.Fatal(err)
	}
	if len(corpus.Accepted) == 0 || len(corpus.Rejected) == 0 {
		t.Fatal("empty corpus")
	}
	for _, p := range corpus.Accepted {
		if err := validateSourceV2(logs.Source{Path: "/a.log", MultilinePattern: p}); err != nil {
			t.Errorf("accepted by the control plane, refused by the agent: %q: %v", p, err)
		}
	}
}

// The tailer's exceptions reach the tee with the site's id for its slug; a slug this server does not know is dropped.
func TestLogExceptionsResolveTheSiteID(t *testing.T) {
	ins := &posts{}
	s, _ := newService(t, "", ins)
	s.mu.Lock()
	s.cur.Sites = []otlp.Site{{Slug: "shop", SiteID: "01J9ZQ4C5M8W3X2Y1V0T9S8R7N"}}
	s.mu.Unlock()
	at := time.Now().Add(-time.Minute)
	l := logExceptions{s}
	l.ObserveLogException(logs.LogException{Site: "shop", Type: "RuntimeException", Message: "boom", Stacktrace: "#0 /app/app/x.php(1): f()", File: "laravel.log", At: at})
	l.ObserveLogException(logs.LogException{Site: "unknown", Type: "RuntimeException", At: at})
	_ = s.tee.Flush(context.Background())
	if strings.Contains(ins.all(), "RuntimeException") {
		t.Fatal("sent before the hold")
	}
	held := s.tee.HeldLogExceptions()
	if len(held) != 1 || held[0].SiteID != "01J9ZQ4C5M8W3X2Y1V0T9S8R7N" || held[0].Name != "laravel.log" || !held[0].At.Equal(at) {
		t.Fatalf("held = %+v (the unknown site is dropped)", held)
	}
}
