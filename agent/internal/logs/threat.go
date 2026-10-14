package logs

import (
	"net/url"
	"regexp"
	"strings"
)

// Threat categories (falak.threat.category), docs/plans/network/http.md "Threat rules".
const (
	ThreatProbe     = "probe"
	ThreatTraversal = "traversal"
	ThreatInjection = "injection"
	ThreatMethod    = "method"
)

// ThreatCategories are every category, in a stable order.
var ThreatCategories = []string{ThreatProbe, ThreatTraversal, ThreatInjection, ThreatMethod}

type probeRule struct {
	rule string
	re   *regexp.Regexp
}

// probes match the lowercased path. Order matters: the first match names the rule.
var probes = []probeRule{
	{"env", regexp.MustCompile(`(^|/)\.env($|[./])`)},
	{"git", regexp.MustCompile(`(^|/)\.(git|svn|hg)(/|$)`)},
	{"phpunit", regexp.MustCompile(`/vendor/phpunit(/|$)`)},
	{"wordpress", regexp.MustCompile(`(^|/)(wp-admin|wp-content|wp-includes)(/|$)|(^|/)(wp-login|xmlrpc)\.php$`)},
	{"phpmyadmin", regexp.MustCompile(`(^|/)(phpmyadmin[^/]*|pma|myadmin|adminer(\.php)?)(/|$)`)},
	{"cgi", regexp.MustCompile(`(^|/)cgi-bin(/|$)`)},
	{"config", regexp.MustCompile(`(^|/)(config\.json|\.aws|\.ssh|server-status|actuator|\.ds_store|web\.config|backup\.zip)(/|$)|\.(bak|sql)$`)},
}

var (
	traversalRaw = regexp.MustCompile(`(?i)(\.\./|\.\.\\|%2e%2e|\.\.%2f|%2e\.|\.%2e|%252e|/etc/passwd|/proc/self/environ)`)
	sqliRE       = regexp.MustCompile(`(?i)(union(\s|\+|/\*.*?\*/)+(all(\s|\+)+)?select|'\s*or\s*'?\d+'?\s*=\s*'?\d+|\bsleep\s*\(\s*\d|\bbenchmark\s*\(|information_schema|'\s*(--|#)|;\s*drop\s+table|\bwaitfor\s+delay\b)`)
	xssRE        = regexp.MustCompile(`(?i)(<\s*script|javascript:|\bon(error|load|mouseover)\s*=|<\s*svg[^>]*\bon\w+\s*=|<\s*iframe)`)
)

// usualMethods are the methods a web app serves; anything else is reported (TRACE, CONNECT, PROPFIND, DEBUG, …).
var usualMethods = map[string]bool{"GET": true, "HEAD": true, "POST": true, "PUT": true, "PATCH": true, "DELETE": true, "OPTIONS": true}

// ClassifyThreat reports the probe or attack pattern a request matches ("" when none): the category and the rule.
// uri is the request URI as Caddy logged it (raw: path and query, percent-encoded as the client sent them).
func ClassifyThreat(method, uri string) (category, rule string) {
	rawPath, rawQuery, _ := strings.Cut(uri, "?")
	path := rawPath
	if d, err := url.PathUnescape(rawPath); err == nil {
		path = d
	}
	lower := strings.ToLower(path)
	for _, p := range probes {
		if p.re.MatchString(lower) {
			return ThreatProbe, p.rule
		}
	}
	if traversalRaw.MatchString(rawPath) || traversalRaw.MatchString(path) || traversalRaw.MatchString(rawQuery) {
		return ThreatTraversal, "traversal"
	}
	if rawQuery != "" {
		q := rawQuery
		// Decode twice: scanners double-encode to get past naive filters.
		for range 2 {
			if d, err := url.QueryUnescape(q); err == nil {
				q = d
			}
		}
		if strings.Contains(q, "../") || strings.Contains(q, "/etc/passwd") {
			return ThreatTraversal, "traversal"
		}
		if sqliRE.MatchString(q) {
			return ThreatInjection, "sqli"
		}
		if xssRE.MatchString(q) {
			return ThreatInjection, "xss"
		}
	}
	if method != "" && !usualMethods[strings.ToUpper(method)] {
		return ThreatMethod, "method"
	}
	return "", ""
}
