package cli

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// TestInitUpdateGolden runs `falak init --update` on testdata/update/<case>: the repository is the init fixture named
// in its `repo` file, the existing falak.yaml is the case's; expected.diff is the proposed diff, expected.falak.yaml
// the file after --write.
func TestInitUpdateGolden(t *testing.T) {
	cases, err := os.ReadDir(filepath.Join("testdata", "update"))
	if err != nil {
		t.Fatal(err)
	}
	for _, c := range cases {
		t.Run(c.Name(), func(t *testing.T) {
			src := filepath.Join("testdata", "update", c.Name())
			repo, err := os.ReadFile(filepath.Join(src, "repo"))
			if err != nil {
				t.Fatal(err)
			}
			h := newHarness(t, nil)
			h.wd = copyRepo(t, filepath.Join("testdata", "init", strings.TrimSpace(string(repo))))
			before, err := os.ReadFile(filepath.Join(src, "falak.yaml"))
			if err != nil {
				t.Fatal(err)
			}
			writeFile(t, h.wd, "falak.yaml", string(before))

			if code := h.run("init", "--update"); code != 0 {
				t.Fatalf("update: %d %s", code, h.err.String())
			}
			golden(t, filepath.Join(src, "expected.diff"), h.out.Bytes())
			golden(t, filepath.Join(src, "expected.notes"), h.err.Bytes())
			if b, _ := os.ReadFile(filepath.Join(h.wd, "falak.yaml")); string(b) != string(before) {
				t.Fatal("--update without --write changed the file")
			}

			if code := h.run("init", "--update", "--write"); code != 0 {
				t.Fatalf("write: %d %s", code, h.err.String())
			}
			after, _ := os.ReadFile(filepath.Join(h.wd, "falak.yaml"))
			golden(t, filepath.Join(src, "expected.falak.yaml"), after)

			// Nothing the user wrote is gone: every line of the old file is still there, in order.
			rest := string(after)
			for _, line := range strings.Split(strings.TrimRight(string(before), "\n"), "\n") {
				i := strings.Index(rest, strings.TrimRight(line, " "))
				if i < 0 {
					t.Fatalf("lost or reordered: %q\n%s", line, after)
				}
				rest = rest[i+len(strings.TrimRight(line, " ")):]
			}
			if v := validateOffline(configFile{Path: "falak.yaml", Display: "falak.yaml", Format: "yaml", Raw: after}, ""); !v.Valid {
				t.Fatalf("invalid after update: %+v", v.Errors)
			}

			// A second update has nothing to add.
			if code := h.run("init", "--update"); code != 0 || !strings.Contains(h.out.String(), "nothing to add") {
				t.Fatalf("second update: %d %s", code, h.out.String())
			}
		})
	}
}

func TestInitUpdateErrors(t *testing.T) {
	h := newHarness(t, nil)
	h.wd = copyRepo(t, filepath.Join("testdata", "init", "nextjs"))
	if code := h.run("init", "--update"); code != ExitError || !strings.Contains(h.err.String(), "run `falak init` first") {
		t.Fatalf("no file: %d %s", code, h.err.String())
	}
	if code := h.run("init", "--write"); code != ExitUsage {
		t.Fatalf("--write alone: %d", code)
	}
	writeFile(t, h.wd, "falak.yaml", "version: 2\nunits: {}\n")
	if code := h.run("init", "--update"); code != ExitInvalid {
		t.Fatalf("invalid file: %d %s", code, h.err.String())
	}
}

func TestUnifiedDiff(t *testing.T) {
	got := unifiedDiff("a\nb\nc\n", "a\nb\nx\nc\n", "f", "f")
	want := "--- f\n+++ f\n@@ -1,3 +1,4 @@\n a\n b\n+x\n c\n"
	if got != want {
		t.Fatalf("got\n%s\nwant\n%s", got, want)
	}
	if unifiedDiff("same\n", "same\n", "f", "f") != "" {
		t.Fatal("equal texts")
	}
}
