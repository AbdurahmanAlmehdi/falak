package configspec

import (
	"fmt"
	"regexp"
	"sort"
	"strings"
)

// RefPattern matches one reference, exactly like the control plane's VariableReferences::PATTERN: group 1 is the
// service (unit) name or "secrets", group 2 the key.
var RefPattern = regexp.MustCompile(`\$\{\{\s*([A-Za-z0-9][A-Za-z0-9 _.\-]*?)\.([A-Za-z_][A-Za-z0-9_]*)\s*\}\}`)

var secretName = regexp.MustCompile(`^[A-Z_][A-Z0-9_]*$`)

// DatabaseKeys and RedisKeys are what database and redis units expose to references (Databases'
// DatabaseConnections::KEYS and ::REDIS_KEYS).
var (
	DatabaseKeys = []string{"DATABASE_URL", "DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"}
	RedisKeys    = []string{"REDIS_URL", "REDIS_CLIENT", "REDIS_HOST", "REDIS_PORT", "REDIS_PASSWORD"}
)

// Resolve merges environment env into the units of a schema-valid file and checks its references. The resolved
// document has the file's shape: `units` merged, `environments` holding only env (without its unit overrides), and
// `$schema`, `version` and `previews` as they were. It is nil when errors stop the merge.
//
// Merge rules: an override map merges into the unit's map key by key; any other value (list, scalar) replaces;
// null removes the key (a null unit removes the unit from env); an override for a key not in `units` adds a unit.
func Resolve(doc any, env string) (resolved map[string]any, errs, warnings []Issue) {
	root, _ := doc.(map[string]any)
	envs, _ := root["environments"].(map[string]any)
	e, ok := envs[env].(map[string]any)
	if !ok {
		return nil, []Issue{{Code: CodeEnvironmentUnknown, Pointer: "/environments", Source: "/environments", Message: fmt.Sprintf("the file has no environment %q", env)}}, nil
	}

	units, _ := clone(root["units"]).(map[string]any)
	if units == nil {
		units = map[string]any{}
	}
	overrides, _ := e["units"].(map[string]any)
	for _, key := range sortedKeys(overrides) {
		ov := overrides[key]
		if ov == nil {
			delete(units, key)
			continue
		}
		ovm, _ := ov.(map[string]any)
		base, exists := units[key].(map[string]any)
		if exists {
			if t, has := ovm["type"]; has && t != base["type"] {
				p := "/environments/" + escape(env) + "/units/" + escape(key) + "/type"
				errs = append(errs, Issue{Code: CodeOverrideType, Pointer: p, Source: p, Message: "an override can't change a unit's type"})
				continue
			}
			units[key] = merge(base, ovm)
		} else {
			units[key] = merge(map[string]any{}, ovm)
		}
	}
	if len(errs) > 0 {
		return nil, errs, nil
	}

	resolved = map[string]any{"version": root["version"], "units": units}
	for _, k := range []string{"$schema", "previews"} {
		if v, ok := root[k]; ok {
			resolved[k] = clone(v)
		}
	}
	envOut := map[string]any{}
	for k, v := range e {
		if k != "units" {
			envOut[k] = clone(v)
		}
	}
	resolved["environments"] = map[string]any{env: envOut}

	src := sources{env: env, overrides: overrides, base: root["units"]}
	errs, warnings = checkReferences(resolved, src)
	return resolved, errs, warnings
}

func merge(base, over map[string]any) map[string]any {
	out := make(map[string]any, len(base)+len(over))
	for k, v := range base {
		out[k] = clone(v)
	}
	for k, v := range over {
		if v == nil {
			delete(out, k)
			continue
		}
		bm, bok := out[k].(map[string]any)
		om, ook := v.(map[string]any)
		if bok && ook {
			out[k] = merge(bm, om)
			continue
		}
		if ook {
			out[k] = merge(map[string]any{}, om)
			continue
		}
		out[k] = clone(v)
	}
	return out
}

func clone(v any) any {
	switch t := v.(type) {
	case map[string]any:
		out := make(map[string]any, len(t))
		for k, x := range t {
			out[k] = clone(x)
		}
		return out
	case []any:
		out := make([]any, len(t))
		for i, x := range t {
			out[i] = clone(x)
		}
		return out
	}
	return v
}

func sortedKeys(m map[string]any) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

// sources maps a pointer of the resolved document to the value's place in the file: the environment's override
// when it set that value (or a list or scalar containing it), else the unit.
type sources struct {
	env       string
	overrides map[string]any
	base      any
}

func (s sources) of(tokens []string) string {
	if len(tokens) < 2 || tokens[0] != "units" {
		return pointer(tokens)
	}
	envPtr := "/environments/" + escape(s.env) + pointer(tokens)
	ov, has := s.overrides[tokens[1]]
	if !has || ov == nil {
		return pointer(tokens)
	}
	if baseUnits, _ := s.base.(map[string]any); baseUnits[tokens[1]] == nil {
		return envPtr
	}
	cur := ov
	for _, tok := range tokens[2:] {
		m, ok := cur.(map[string]any)
		if !ok {
			return envPtr
		}
		v, has := m[tok]
		if !has {
			return pointer(tokens)
		}
		cur = v
	}
	return envPtr
}

// isEnvValue: units.<u>.env.<K>, the only values references are resolved in (workers and daemons see the unit's env;
// their own env is literal, as Processes stores it).
func isEnvValue(t []string) bool {
	return len(t) == 4 && t[0] == "units" && t[2] == "env"
}

func checkReferences(resolved map[string]any, src sources) (errs, warnings []Issue) {
	units, _ := resolved["units"].(map[string]any)
	add := func(list *[]Issue, code string, tokens []string, msg string) {
		*list = append(*list, Issue{Code: code, Pointer: pointer(tokens), Source: src.of(tokens), Message: msg})
	}

	var walk func(v any, tokens []string)
	walk = func(v any, tokens []string) {
		switch t := v.(type) {
		case map[string]any:
			for _, k := range sortedKeys(t) {
				walk(t[k], append(append([]string{}, tokens...), k))
			}
		case []any:
			for i, x := range t {
				walk(x, append(append([]string{}, tokens...), fmt.Sprint(i)))
			}
		case string:
			if !strings.Contains(t, "${{") {
				return
			}
			if !isEnvValue(tokens) {
				add(&errs, CodeRefNotAllowed, tokens, "references are only resolved in env values")
				return
			}
			matches := RefPattern.FindAllStringSubmatchIndex(t, -1)
			starts := map[int]bool{}
			for _, m := range matches {
				starts[m[0]] = true
			}
			for i := 0; ; {
				j := strings.Index(t[i:], "${{")
				if j < 0 {
					break
				}
				if !starts[i+j] {
					add(&errs, CodeRefMalformed, tokens, "use ${{ secrets.NAME }} or ${{ <unit>.KEY }}")
					return
				}
				i += j + 3
			}
			for _, m := range matches {
				name, key := strings.TrimSpace(t[m[2]:m[3]]), t[m[4]:m[5]]
				if strings.ToLower(name) == "secrets" {
					if !secretName.MatchString(key) {
						add(&errs, CodeRefSecretName, tokens, fmt.Sprintf("secret names are upper case: %q", key))
					}
					continue
				}
				target, ok := units[name].(map[string]any)
				if !ok {
					add(&warnings, CodeRefExternal, tokens, fmt.Sprintf("%q is not a unit of this file; it must be a service of the environment", name))
					continue
				}
				if !exposes(target, key) {
					add(&errs, CodeRefUnknownKey, tokens, fmt.Sprintf("unit %q has no variable %s", name, key))
				}
			}
		}
	}
	walk(resolved, nil)
	return dedupe(errs), dedupe(warnings)
}

func exposes(unit map[string]any, key string) bool {
	switch unit["type"] {
	case "database":
		return contains(DatabaseKeys, key)
	case "redis":
		return contains(RedisKeys, key)
	}
	env, _ := unit["env"].(map[string]any)
	_, ok := env[key]
	return ok
}

func contains(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}

func dedupe(issues []Issue) []Issue {
	seen := map[string]bool{}
	out := issues[:0]
	for _, i := range issues {
		k := i.Code + "\x00" + i.Pointer
		if !seen[k] {
			seen[k] = true
			out = append(out, i)
		}
	}
	if len(out) == 0 {
		return nil
	}
	return out
}
