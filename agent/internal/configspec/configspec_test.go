package configspec

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"reflect"
	"sort"
	"strings"
	"testing"
)

// The shared cases (contracts/config/cases, README.md there); the control plane runs the same ones
// (control-plane/modules/Config/tests/Unit/SchemaCasesTest.php).
var casesDir = filepath.Join("..", "..", "..", "contracts", "config", "cases")

func TestSchemaCopyMatchesContract(t *testing.T) {
	want, err := os.ReadFile(filepath.Join("..", "..", "..", "contracts", "config", "v1.schema.json"))
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(want, SchemaJSON) {
		t.Fatal("agent/internal/configspec/v1.schema.json differs from contracts/config/v1.schema.json: copy it again")
	}
	if _, err := Schema(); err != nil {
		t.Fatal(err)
	}
}

// inputs lists the case files of a directory (.yaml, .yml and .json, not the .expect.json files).
func inputs(t *testing.T, dir string) []string {
	t.Helper()
	entries, err := os.ReadDir(filepath.Join(casesDir, dir))
	if err != nil {
		t.Fatal(err)
	}
	var out []string
	for _, e := range entries {
		n := e.Name()
		if strings.HasSuffix(n, ".expect.json") {
			continue
		}
		if ext := filepath.Ext(n); ext == ".yaml" || ext == ".yml" || ext == ".json" {
			out = append(out, filepath.Join(casesDir, dir, n))
		}
	}
	if len(out) == 0 {
		t.Fatalf("no cases in %s", dir)
	}
	return out
}

func parseFile(t *testing.T, path string) (any, error) {
	t.Helper()
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	format := "yaml"
	if filepath.Ext(path) == ".json" {
		format = "json"
	}
	return Parse(raw, format)
}

func expectFile(t *testing.T, path string, v any) {
	t.Helper()
	raw, err := os.ReadFile(strings.TrimSuffix(path, filepath.Ext(path)) + ".expect.json")
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(raw, v); err != nil {
		t.Fatalf("%s: %v", path, err)
	}
}

func TestValidCases(t *testing.T) {
	for _, path := range inputs(t, "valid") {
		t.Run(filepath.Base(path), func(t *testing.T) {
			doc, err := parseFile(t, path)
			if err != nil {
				t.Fatal(err)
			}
			issues, err := Validate(doc)
			if err != nil {
				t.Fatal(err)
			}
			if len(issues) > 0 {
				t.Fatalf("expected valid, got %v", issues)
			}
		})
	}
}

func TestInvalidCases(t *testing.T) {
	for _, path := range inputs(t, "invalid") {
		t.Run(filepath.Base(path), func(t *testing.T) {
			var want struct {
				Code    string  `json:"code"`
				Pointer *string `json:"pointer"` // null: the YAML parser's own error, without a pointer
			}
			expectFile(t, path, &want)
			doc, err := parseFile(t, path)
			if want.Code != CodeSchema {
				issue, ok := err.(Issue)
				if !ok || issue.Code != want.Code || (want.Pointer != nil && issue.Pointer != *want.Pointer) {
					t.Fatalf("expected %s at %v, got %v", want.Code, want.Pointer, err)
				}
				return
			}
			if want.Pointer == nil {
				t.Fatal("schema cases name their pointer")
			}
			if err != nil {
				t.Fatal(err)
			}
			issues, err := Validate(doc)
			if err != nil {
				t.Fatal(err)
			}
			var got []string
			for _, i := range issues {
				if i.Pointer == *want.Pointer {
					return
				}
				got = append(got, i.Pointer+": "+i.Message)
			}
			t.Fatalf("expected a schema error at %q, got %v", *want.Pointer, got)
		})
	}
}

type issueKey struct {
	Code    string `json:"code"`
	Pointer string `json:"pointer"`
	Source  string `json:"source"`
}

func keys(issues []Issue) []issueKey {
	out := []issueKey{}
	for _, i := range issues {
		out = append(out, issueKey{i.Code, i.Pointer, i.Source})
	}
	sort.Slice(out, func(a, b int) bool { return out[a].Pointer+out[a].Code < out[b].Pointer+out[b].Code })
	return out
}

// normalize round-trips through JSON so int64 and float64 compare like decoded JSON.
func normalize(t *testing.T, v any) any {
	t.Helper()
	raw, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	var out any
	if err := json.Unmarshal(raw, &out); err != nil {
		t.Fatal(err)
	}
	return out
}

func TestResolveCases(t *testing.T) {
	for _, path := range inputs(t, "resolve") {
		t.Run(filepath.Base(path), func(t *testing.T) {
			var want struct {
				Environments map[string]struct {
					Resolved map[string]any `json:"resolved"`
					Errors   []issueKey     `json:"errors"`
					Warnings []issueKey     `json:"warnings"`
				} `json:"environments"`
			}
			expectFile(t, path, &want)
			doc, err := parseFile(t, path)
			if err != nil {
				t.Fatal(err)
			}
			if issues, err := Validate(doc); err != nil || len(issues) > 0 {
				t.Fatalf("the input must be schema-valid: %v %v", issues, err)
			}
			if len(want.Environments) == 0 {
				t.Fatal("no environments in the expect file")
			}
			for env, exp := range want.Environments {
				resolved, errs, warns := Resolve(doc, env)
				sortKeys := func(k []issueKey) []issueKey {
					if k == nil {
						k = []issueKey{}
					}
					sort.Slice(k, func(a, b int) bool { return k[a].Pointer+k[a].Code < k[b].Pointer+k[b].Code })
					return k
				}
				if got, w := keys(errs), sortKeys(exp.Errors); !reflect.DeepEqual(got, w) {
					t.Errorf("%s: errors\n got %+v\nwant %+v", env, got, w)
				}
				if got, w := keys(warns), sortKeys(exp.Warnings); !reflect.DeepEqual(got, w) {
					t.Errorf("%s: warnings\n got %+v\nwant %+v", env, got, w)
				}
				var got any
				if resolved != nil {
					got = normalize(t, resolved)
				}
				var w any
				if exp.Resolved != nil {
					w = normalize(t, exp.Resolved)
				}
				if !reflect.DeepEqual(got, w) {
					g, _ := json.MarshalIndent(got, "", "  ")
					t.Errorf("%s: resolved\n got %s", env, g)
				}
				if resolved != nil {
					if issues, err := Validate(resolved); err != nil || len(issues) > 0 {
						t.Errorf("%s: the resolved document must be schema-valid: %v %v", env, issues, err)
					}
				}
			}
		})
	}
}

// TestParseCases: the JSON value both parsers read from each file.
func TestParseCases(t *testing.T) {
	for _, path := range inputs(t, "parse") {
		t.Run(filepath.Base(path), func(t *testing.T) {
			var want any
			expectFile(t, path, &want)
			doc, err := parseFile(t, path)
			if err != nil {
				t.Fatal(err)
			}
			if got := normalize(t, doc); !reflect.DeepEqual(got, want) {
				g, _ := json.MarshalIndent(got, "", "  ")
				t.Fatalf("parsed\n%s", g)
			}
		})
	}
}
