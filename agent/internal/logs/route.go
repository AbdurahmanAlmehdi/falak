package logs

import (
	"regexp"
	"strings"
)

// Route normalization bounds (docs/plans/network/http.md, "Route normalization").
const (
	maxRouteSegments = 8
	maxRouteBytes    = 200
)

var (
	routeDigits = regexp.MustCompile(`^[0-9]+$`)
	routeUUID   = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)
	routeULID   = regexp.MustCompile(`^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$`)
	routeHex    = regexp.MustCompile(`^[0-9a-fA-F]{16,}$`)
	// A hashed asset name: a base, a separator and 8+ hex characters (or a long mixed token), then an extension.
	routeAsset = regexp.MustCompile(`^[A-Za-z0-9_]*[-.]([0-9a-fA-F]{8,}|[A-Za-z0-9_-]{8,})(\.[A-Za-z0-9]{1,8})$`)
	routeToken = regexp.MustCompile(`^[A-Za-z0-9_\-=~.+]{20,}$`)
)

// NormalizeRoute turns a request path into its route: segments that identify a record (numbers, UUIDs, ULIDs, hashes,
// long tokens) become ":id", hashed asset names ":asset.<ext>", at most 8 segments and 200 bytes are kept. The query
// string is never part of it. The result keeps url.route's cardinality bounded per site.
func NormalizeRoute(path string) string {
	if path == "" || path[0] != '/' {
		return "/"
	}
	parts := strings.Split(path[1:], "/")
	truncated := false
	if len(parts) > maxRouteSegments {
		parts, truncated = parts[:maxRouteSegments], true
	}
	for i, seg := range parts {
		parts[i] = normalizeSegment(seg)
	}
	out := "/" + strings.Join(parts, "/")
	if truncated {
		out += "/…"
	}
	if len(out) > maxRouteBytes {
		cut := maxRouteBytes
		for cut > 0 && !utf8Start(out[cut]) {
			cut--
		}
		out = out[:cut] + "…"
	}
	return out
}

func utf8Start(b byte) bool { return b&0xC0 != 0x80 }

func normalizeSegment(seg string) string {
	switch {
	case seg == "":
		return seg
	case routeDigits.MatchString(seg), routeUUID.MatchString(seg), routeHex.MatchString(seg):
		return ":id"
	case routeULID.MatchString(seg) && hasDigit(seg) && hasLetter(seg):
		return ":id"
	}
	if m := routeAsset.FindStringSubmatch(seg); m != nil && (isHexWord(m[1]) || (hasDigit(m[1]) && hasLetter(m[1]))) {
		return ":asset" + strings.ToLower(m[2])
	}
	if routeToken.MatchString(seg) && hasDigit(seg) && hasLetter(seg) && !strings.Contains(seg, ".") {
		return ":id"
	}
	return seg
}

func isHexWord(s string) bool {
	for i := 0; i < len(s); i++ {
		c := s[i]
		if !(c >= '0' && c <= '9' || c >= 'a' && c <= 'f' || c >= 'A' && c <= 'F') {
			return false
		}
	}
	return len(s) >= 8 && hasDigit(s)
}

func hasDigit(s string) bool { return strings.ContainsAny(s, "0123456789") }

func hasLetter(s string) bool {
	for i := 0; i < len(s); i++ {
		c := s[i] | 0x20
		if c >= 'a' && c <= 'z' {
			return true
		}
	}
	return false
}
