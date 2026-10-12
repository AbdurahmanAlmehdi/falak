package configspec

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

// Schema versioning (docs/CONFIG_AS_CODE.md, "Schema versions"): v1 only gains optional fields. The released schema is
// frozen in contracts/config/v1.baseline.json; the live schema may widen it, never narrow it. A breaking change is a
// new `version: 2` schema with its own $id, not an edit of v1.

func readJSON(t *testing.T, path string) map[string]any {
	t.Helper()
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var doc map[string]any
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatalf("%s: %v", path, err)
	}
	return doc
}

func TestSchemaOnlyWidensTheBaseline(t *testing.T) {
	contracts := filepath.Join("..", "..", "..", "contracts", "config")
	base := readJSON(t, filepath.Join(contracts, "v1.baseline.json"))
	current := readJSON(t, filepath.Join(contracts, "v1.schema.json"))

	if base["$id"] != current["$id"] {
		t.Errorf("$id changed from %v to %v: a breaking change is a new schema (version 2), not an edit of v1", base["$id"], current["$id"])
	}
	for _, problem := range narrowings(base, current) {
		t.Error(problem)
	}
}

// The check catches each kind of narrowing (and lets a widening through).
func TestNarrowingsDetectsBreakingChanges(t *testing.T) {
	base := map[string]any{
		"type":     "object",
		"required": []any{"version"},
		"properties": map[string]any{
			"version": map[string]any{"type": []any{"string", "integer"}},
			"mode":    map[string]any{"enum": []any{"a", "b", float64(301)}},
		},
	}
	clone := func(edit func(m map[string]any)) map[string]any {
		raw, _ := json.Marshal(base)
		var m map[string]any
		_ = json.Unmarshal(raw, &m)
		edit(m)
		return m
	}
	props := func(m map[string]any) map[string]any { return m["properties"].(map[string]any) }

	cases := map[string]struct {
		edit func(m map[string]any)
		want string
	}{
		"unchanged":          {func(m map[string]any) {}, ""},
		"new optional field": {func(m map[string]any) { props(m)["extra"] = map[string]any{"type": "string"} }, ""},
		"new enum value":     {func(m map[string]any) { props(m)["mode"].(map[string]any)["enum"] = []any{"a", "b", float64(301), "c"} }, ""},
		"type widened": {func(m map[string]any) {
			props(m)["version"].(map[string]any)["type"] = []any{"string", "integer", "boolean"}
		}, ""},
		"property removed":   {func(m map[string]any) { delete(props(m), "mode") }, "property removed"},
		"type narrowed":      {func(m map[string]any) { props(m)["version"].(map[string]any)["type"] = "string" }, "type narrowed"},
		"enum value removed": {func(m map[string]any) { props(m)["mode"].(map[string]any)["enum"] = []any{"a", "b"} }, "enum value removed"},
		"new required field": {func(m map[string]any) { m["required"] = []any{"version", "mode"} }, "new required field"},
	}
	for name, c := range cases {
		got := strings.Join(narrowings(base, clone(c.edit)), "\n")
		if (c.want == "") != (got == "") || !strings.Contains(got, c.want) {
			t.Errorf("%s: got %q, want %q", name, got, c.want)
		}
	}
}

// narrowings lists what `current` accepts less than `base`: removed properties (and $defs), narrowed types, removed
// enum values and new required fields, at every schema node both have. Arrays (oneOf, anyOf, allOf) compare by index.
func narrowings(base, current any) []string {
	var out []string
	var walk func(b, c any, path string)
	walk = func(b, c any, path string) {
		switch bv := b.(type) {
		case map[string]any:
			cv, ok := c.(map[string]any)
			if !ok {
				out = append(out, fmt.Sprintf("%s: schema node replaced", path))
				return
			}
			if bt, ok := bv["type"]; ok {
				if ct, ok := cv["type"]; ok {
					if missing := missingFrom(list(bt), list(ct)); len(missing) > 0 {
						out = append(out, fmt.Sprintf("%s: type narrowed (lost %s)", path, strings.Join(missing, ", ")))
					}
				}
			}
			if be, ok := bv["enum"]; ok {
				if ce, ok := cv["enum"]; ok {
					if missing := missingFrom(list(be), list(ce)); len(missing) > 0 {
						out = append(out, fmt.Sprintf("%s: enum value removed (%s)", path, strings.Join(missing, ", ")))
					}
				}
			}
			if added := missingFrom(list(cv["required"]), list(bv["required"])); len(added) > 0 {
				out = append(out, fmt.Sprintf("%s: new required field (%s)", path, strings.Join(added, ", ")))
			}
			keys := make([]string, 0, len(bv))
			for k := range bv {
				keys = append(keys, k)
			}
			sort.Strings(keys)
			for _, k := range keys {
				child, ok := cv[k]
				if !ok {
					if strings.HasSuffix(path, "/properties") || strings.HasSuffix(path, "/patternProperties") || strings.HasSuffix(path, "/$defs") {
						out = append(out, fmt.Sprintf("%s/%s: property removed", path, k))
					}
					continue
				}
				walk(bv[k], child, path+"/"+k)
			}
		case []any:
			cv, ok := c.([]any)
			if !ok {
				return
			}
			for i := range bv {
				if i < len(cv) {
					walk(bv[i], cv[i], fmt.Sprintf("%s/%d", path, i))
				}
			}
		}
	}
	walk(base, current, "#")
	return out
}

// list reads a keyword that is a value or a list of values, as JSON texts (so 301 and "301" differ).
func list(v any) []string {
	if v == nil {
		return nil
	}
	items, ok := v.([]any)
	if !ok {
		items = []any{v}
	}
	out := make([]string, len(items))
	for i, item := range items {
		raw, _ := json.Marshal(item)
		out[i] = string(raw)
	}
	return out
}

// missingFrom returns the items of want that aren't in have.
func missingFrom(want, have []string) []string {
	set := map[string]bool{}
	for _, h := range have {
		set[h] = true
	}
	var out []string
	for _, w := range want {
		if !set[w] {
			out = append(out, w)
		}
	}
	return out
}
