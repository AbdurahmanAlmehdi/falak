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
	// A file name under a path: a base and an extension (the base is checked for an id-like part).
	routeFile = regexp.MustCompile(`^([A-Za-z0-9_.\-]+)(\.[A-Za-z0-9]{1,8})$`)
)

// NormalizeRoute turns a request path into its route: segments that identify a record (numbers, UUIDs, ULIDs, hashes,
// long tokens, short mixed ids such as "75243p09010") become ":id", hashed asset names ":asset.<ext>", file names with
// an id in them (dk1l076m0115-1-thumb.webp) ":file.<ext>", at most 8 segments and 200 bytes are kept. The query
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
	if idLike(seg) {
		return ":id"
	}
	if m := routeFile.FindStringSubmatch(seg); m != nil {
		for _, part := range strings.FieldsFunc(m[1], func(r rune) bool { return r == '-' || r == '_' || r == '.' }) {
			if idLike(part) || (len(part) >= 4 && routeDigits.MatchString(part)) {
				return ":file" + strings.ToLower(m[2])
			}
		}
	}
	return seg
}

// idLike: letters and digits only, 6+ characters with 3+ digits ("75243p09010", "dk1l076m0115"), so words with a
// version or a digit ("v1", "oauth2", "base64", "utf8mb4") stay as they are.
func idLike(s string) bool {
	if len(s) < 6 || !hasLetter(s) {
		return false
	}
	digits := 0
	for i := 0; i < len(s); i++ {
		c := s[i]
		switch {
		case c >= '0' && c <= '9':
			digits++
		case c|0x20 < 'a' || c|0x20 > 'z':
			return false
		}
	}
	return digits >= 3
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
