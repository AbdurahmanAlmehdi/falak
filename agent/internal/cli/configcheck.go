package cli

import (
	"fmt"
	"math"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Offline part of stage 4 (semantic checks): the rules of the contract that need no organization data, run on a
// resolved file. The control plane runs them again with the rest of stage 4 (names, config lists, server limits).

// Codes of stage 4 the CLI can check offline.
const (
	codeValueRange       = "value.range"
	codeValueUnsupported = "value.unsupported"
	codeValueConflict    = "value.conflict"
)

// semanticIssue is a stage 4 issue with its pointer into the resolved file.
type semanticIssue struct {
	code, pointer, message string
}

var durationRe = regexp.MustCompile(`^(?:([0-9]+)d)?(?:([0-9]+)h)?(?:([0-9]+)m)?(?:([0-9]+)s)?$`)

// durationSeconds parses a schema duration (`1h30m`); ok is false for anything else.
func durationSeconds(s string) (int64, bool) {
	m := durationRe.FindStringSubmatch(s)
	if m == nil || len(s) < 2 {
		return 0, false
	}
	var total int64
	for i, mult := range []int64{86400, 3600, 60, 1} {
		if m[i+1] != "" {
			n, err := strconv.ParseInt(m[i+1], 10, 64)
			if err != nil {
				return 0, false
			}
			total += n * mult
		}
	}
	return total, true
}

var sizeRe = regexp.MustCompile(`^([0-9]+(?:\.[0-9]+)?)([kKmMgGtT])(?:[iI]?[bB])?$`)

const (
	kib = 1024.0
	mib = 1024 * kib
	gib = 1024 * mib
	tib = 1024 * gib
)

// sizeBytes parses a schema size (`512m`, `1.5GiB`) into bytes (binary multiples).
func sizeBytes(s string) (float64, bool) {
	m := sizeRe.FindStringSubmatch(s)
	if m == nil {
		return 0, false
	}
	n, err := strconv.ParseFloat(m[1], 64)
	if err != nil {
		return 0, false
	}
	mult := map[string]float64{"k": kib, "m": mib, "g": gib, "t": tib}[strings.ToLower(m[2])]
	return n * mult, true
}

func humanDuration(s int64) string {
	if s == 0 {
		return "0s"
	}
	var b strings.Builder
	for _, u := range []struct {
		n int64
		s string
	}{{86400, "d"}, {3600, "h"}, {60, "m"}, {1, "s"}} {
		if s >= u.n {
			fmt.Fprintf(&b, "%d%s", s/u.n, u.s)
			s %= u.n
		}
	}
	return b.String()
}

func humanSize(b float64) string {
	for _, u := range []struct {
		n float64
		s string
	}{{tib, "t"}, {gib, "g"}, {mib, "m"}} {
		if b >= u.n && math.Mod(b, u.n) == 0 {
			return fmt.Sprintf("%.0f%s", b/u.n, u.s)
		}
	}
	return fmt.Sprintf("%.0f bytes", b)
}

type checker struct {
	issues []semanticIssue
}

func (c *checker) add(code, ptr, format string, a ...any) {
	c.issues = append(c.issues, semanticIssue{code: code, pointer: ptr, message: fmt.Sprintf(format, a...)})
}

// duration checks a duration field against [min, max] seconds (unit "h": whole hours).
func (c *checker) duration(m map[string]any, key, ptr string, min, max int64, wholeHours bool) {
	s, ok := m[key].(string)
	if !ok {
		return
	}
	secs, ok := durationSeconds(s)
	if !ok {
		return // the schema reports it
	}
	p := ptr + "/" + escapeToken(key)
	if secs < min || secs > max {
		c.add(codeValueRange, p, "%s must be between %s and %s", key, humanDuration(min), humanDuration(max))
		return
	}
	if wholeHours && secs%3600 != 0 {
		c.add(codeValueRange, p, "%s must be whole hours", key)
	}
}

// size checks a size field: at least min bytes, at most max (0: no maximum), a whole number of unit bytes.
func (c *checker) size(m map[string]any, key, ptr string, min, max, unit float64) {
	s, ok := m[key].(string)
	if !ok {
		return
	}
	b, ok := sizeBytes(s)
	if !ok {
		return
	}
	p := ptr + "/" + escapeToken(key)
	switch {
	case b < min || (max > 0 && b > max):
		if max > 0 {
			c.add(codeValueRange, p, "%s must be between %s and %s", key, humanSize(min), humanSize(max))
		} else {
			c.add(codeValueRange, p, "%s must be at least %s", key, humanSize(min))
		}
	case math.Mod(b, unit) != 0:
		c.add(codeValueRange, p, "%s must be a whole number of %s", key, map[float64]string{mib: "MiB", gib: "GiB"}[unit])
	}
}

func (c *checker) limits(l map[string]any, ptr string) {
	if l == nil {
		return
	}
	c.size(l, "memory", ptr, 32*mib, 0, mib)
	c.size(l, "memory_reservation", ptr, 0, 0, mib)
	if mem, ok := sizeBytes(str(l["memory"])); ok {
		if res, ok := sizeBytes(str(l["memory_reservation"])); ok && res > mem {
			c.add(codeValueConflict, ptr+"/memory_reservation", "memory_reservation is above memory")
		}
	}
	if log, ok := l["log"].(map[string]any); ok {
		c.size(log, "max_size", ptr+"/log", 1*mib, 10*gib, mib)
	}
}

func str(v any) string { s, _ := v.(string); return s }

func asMapAny(v any) map[string]any { m, _ := v.(map[string]any); return m }

func sortedMapKeys(m map[string]any) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

func escapeToken(s string) string {
	return strings.ReplaceAll(strings.ReplaceAll(s, "~", "~0"), "/", "~1")
}

// domainName is a domain entry's host name (a string or {name}).
func domainName(d any) string {
	if s, ok := d.(string); ok {
		return s
	}
	return str(asMapAny(d)["name"])
}

var phpRuntimes = map[string]bool{"frankenphp": true, "php-fpm": true}

// semanticChecks runs the offline stage 4 rules on a resolved file.
func semanticChecks(resolved map[string]any, fileEnvs []string) []semanticIssue {
	c := &checker{}
	units := asMapAny(resolved["units"])
	domainOwner := map[string]string{}

	checkDomains := func(list any, ptr, unit string) {
		primaries := 0
		for i, d := range asList(list) {
			name := domainName(d)
			p := fmt.Sprintf("%s/%d", ptr, i)
			if prev, dup := domainOwner[name]; dup && name != "" && prev != unit {
				c.add(codeValueConflict, p, "%s is also a domain of unit %q", name, prev)
			} else if name != "" {
				domainOwner[name] = unit
			}
			o := asMapAny(d)
			if o == nil {
				continue
			}
			if o["primary"] == true {
				if primaries++; primaries == 2 {
					c.add(codeValueConflict, p+"/primary", "two domains say primary: true")
				}
			}
			if www := str(o["www"]); (www == "to_www" || www == "to_apex") && (strings.HasPrefix(name, "*.") || strings.HasPrefix(name, "www.")) {
				c.add(codeValueConflict, p+"/www", "www redirects need an apex domain (not %s)", name)
			}
		}
	}

	for _, key := range sortedMapKeys(units) {
		u := asMapAny(units[key])
		ptr := "/units/" + escapeToken(key)
		typ, runtime := str(u["type"]), str(u["runtime"])

		checkDomains(u["domains"], ptr+"/domains", key)

		if typ == "app" {
			if _, ok := u["laravel"]; ok && !phpRuntimes[runtime] {
				c.add(codeValueUnsupported, ptr+"/laravel", "laravel settings need a PHP runtime (frankenphp or php-fpm), not %s", runtime)
			}
			for _, f := range []string{"port", "image", "volumes"} {
				if _, ok := u[f]; ok && runtime != "docker" {
					c.add(codeValueUnsupported, ptr+"/"+f, "%s is only for the docker runtime", f)
				}
			}
			if d := asMapAny(u["deploy"]); d["secrets"] == "files" && runtime != "docker" {
				c.add(codeValueUnsupported, ptr+"/deploy/secrets", "secrets: files is only for the docker runtime")
			}
		}
		if d := asMapAny(u["deploy"]); d != nil {
			if h := asMapAny(d["health"]); h != nil {
				c.duration(h, "timeout", ptr+"/deploy/health", 1, 120, false)
				c.duration(h, "retry_delay", ptr+"/deploy/health", 0, 300, false)
			}
		}
		c.limits(asMapAny(u["limits"]), ptr+"/limits")

		workers := asMapAny(u["workers"])
		for _, w := range sortedMapKeys(workers) {
			wm, wp := asMapAny(workers[w]), ptr+"/workers/"+escapeToken(w)
			c.duration(wm, "timeout", wp, 0, 86400, false)
			c.duration(wm, "sleep", wp, 0, 3600, false)
			c.duration(wm, "backoff", wp, 0, 86400, false)
			c.duration(wm, "max_time", wp, 0, 7*86400, false)
			c.size(wm, "memory", wp, 32*mib, 64*gib, mib)
			c.limits(asMapAny(wm["limits"]), wp+"/limits")
		}
		daemons := asMapAny(u["daemons"])
		for _, d := range sortedMapKeys(daemons) {
			dm, dp := asMapAny(daemons[d]), ptr+"/daemons/"+escapeToken(d)
			c.duration(dm, "stop_timeout", dp, 1, 3600, false)
			c.limits(asMapAny(dm["limits"]), dp+"/limits")
		}
		schedules := asMapAny(u["schedules"])
		for _, s := range sortedMapKeys(schedules) {
			c.duration(asMapAny(schedules[s]), "timeout", ptr+"/schedules/"+escapeToken(s), 1, 86400, false)
		}

		switch typ {
		case "compose":
			services := asMapAny(u["services"])
			for _, s := range sortedMapKeys(services) {
				sm, sp := asMapAny(services[s]), ptr+"/services/"+escapeToken(s)
				if _, ok := sm["domains"]; ok {
					if _, hasPort := sm["port"]; !hasPort {
						c.add(codeValueConflict, sp, "service %s has domains but no port", s)
					}
				}
				checkDomains(sm["domains"], sp+"/domains", key)
				c.limits(asMapAny(sm["limits"]), sp+"/limits")
			}
		case "function":
			if sc := asMapAny(u["scaling"]); sc != nil {
				c.duration(sc, "idle_timeout", ptr+"/scaling", 10, 86400, false)
				mn, okMin := sc["min_instances"].(int64)
				mx, okMax := sc["max_instances"].(int64)
				if okMin && okMax && mn > mx {
					c.add(codeValueConflict, ptr+"/scaling/min_instances", "min_instances is above max_instances")
				}
			}
			c.size(u, "memory", ptr, 64*mib, 16*gib, mib)
			c.duration(u, "timeout", ptr, 1, 900, false)
		case "database", "redis":
			c.size(u, "memory", ptr, 16*mib, 0, mib)
			c.size(u, "disk", ptr, 1*gib, 16*tib, gib)
		}
	}

	if p := asMapAny(resolved["previews"]); p != nil {
		c.duration(p, "idle_ttl", "/previews", 3600, 30*86400, true)
		if base := str(p["base"]); p["enabled"] == true && base != "" && !contains(fileEnvs, base) {
			c.add(codeValueConflict, "/previews/base", "previews are enabled with base %q, an environment this file doesn't deploy to", base)
		}
	}
	return c.issues
}

func asList(v any) []any { l, _ := v.([]any); return l }

func contains(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}
