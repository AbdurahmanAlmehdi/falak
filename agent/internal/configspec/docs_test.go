package configspec

import (
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"testing"
)

// Every property of the schema is documented in the user guide, as `name`.
func TestUserDocCoversEveryProperty(t *testing.T) {
	schema := readJSON(t, filepath.Join("..", "..", "..", "contracts", "config", "v1.schema.json"))
	raw, err := os.ReadFile(filepath.Join("..", "..", "..", "docs", "CONFIG_AS_CODE.md"))
	if err != nil {
		t.Fatal(err)
	}
	documented := map[string]bool{}
	for _, m := range regexp.MustCompile("`([^`\\s]+)`").FindAllStringSubmatch(string(raw), -1) {
		// `deploy.health.path`, `units.<u>.env` and `strip_prefix: false` count for each of their names.
		for _, part := range regexp.MustCompile(`[A-Za-z_$][A-Za-z0-9_$]*`).FindAllString(m[1], -1) {
			documented[part] = true
		}
	}

	names := map[string]bool{}
	var collect func(v any)
	collect = func(v any) {
		switch node := v.(type) {
		case map[string]any:
			for k, child := range node {
				if k == "properties" {
					if props, ok := child.(map[string]any); ok {
						for name := range props {
							names[name] = true
						}
					}
				}
				collect(child)
			}
		case []any:
			for _, child := range node {
				collect(child)
			}
		}
	}
	collect(schema)

	var missing []string
	for name := range names {
		if !documented[name] {
			missing = append(missing, name)
		}
	}
	sort.Strings(missing)
	if len(missing) > 0 {
		t.Errorf("docs/CONFIG_AS_CODE.md doesn't mention these schema properties (as `name`): %s", strings.Join(missing, ", "))
	}
}
