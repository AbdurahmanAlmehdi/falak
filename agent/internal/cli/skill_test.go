package cli

import (
	"bytes"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestSkillCopyMatchesRepository(t *testing.T) {
	repo := os.DirFS(filepath.Join("..", "..", "..", "skills", "falak"))
	embedded, err := fs.Sub(skillFiles, "skill/falak")
	if err != nil {
		t.Fatal(err)
	}
	list := func(f fs.FS) map[string][]byte {
		out := map[string][]byte{}
		if err := fs.WalkDir(f, ".", func(p string, d fs.DirEntry, err error) error {
			if err != nil || d.IsDir() {
				return err
			}
			b, err := fs.ReadFile(f, p)
			out[p] = b
			return err
		}); err != nil {
			t.Fatal(err)
		}
		return out
	}
	want, got := list(repo), list(embedded)
	if len(want) == 0 || len(want) != len(got) {
		t.Fatalf("skills/falak has %d files, agent/internal/cli/skill/falak %d: copy it again", len(want), len(got))
	}
	for p, b := range want {
		if !bytes.Equal(b, got[p]) {
			t.Fatalf("agent/internal/cli/skill/falak/%s differs from skills/falak/%s: copy it again", p, p)
		}
	}
	if !bytes.HasPrefix(want["SKILL.md"], []byte("---\nname: falak\ndescription: ")) {
		t.Fatal("SKILL.md must start with the frontmatter (name, description)")
	}
}

func TestSkillInstall(t *testing.T) {
	h := newHarness(t, nil)
	home := t.TempDir()
	h.env["HOME"] = home
	userDir := filepath.Join(home, ".claude", "skills", "falak")

	if code := h.run("skill", "install"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err.String())
	}
	b, err := os.ReadFile(filepath.Join(userDir, "SKILL.md"))
	if err != nil || !bytes.HasPrefix(b, []byte("---\nname: falak\n")) {
		t.Fatalf("SKILL.md: %v %q", err, b)
	}
	if _, err := os.Stat(filepath.Join(userDir, "references", "cli.md")); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(h.out.String(), "Installed the Falak skill in "+userDir) {
		t.Fatalf("output: %s", h.out.String())
	}

	// A second install refuses to overwrite and writes nothing; --force replaces a changed file.
	if err := os.WriteFile(filepath.Join(userDir, "SKILL.md"), []byte("mine"), 0o644); err != nil {
		t.Fatal(err)
	}
	if code := h.run("skill", "install", "--user"); code != 1 || !strings.Contains(h.err.String(), "--force") {
		t.Fatalf("exit %d: %s", code, h.err.String())
	}
	if b, _ := os.ReadFile(filepath.Join(userDir, "SKILL.md")); string(b) != "mine" {
		t.Fatal("overwritten without --force")
	}
	if code := h.run("skill", "install", "--force"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err.String())
	}
	if b, _ := os.ReadFile(filepath.Join(userDir, "SKILL.md")); string(b) == "mine" {
		t.Fatal("--force kept the old file")
	}

	// --project writes under the working directory; --dir anywhere, with --json.
	h.wd = t.TempDir()
	if code := h.run("skill", "install", "--project"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err.String())
	}
	if _, err := os.Stat(filepath.Join(h.wd, ".claude", "skills", "falak", "SKILL.md")); err != nil {
		t.Fatal(err)
	}
	other := filepath.Join(t.TempDir(), "codex", "falak")
	if code := h.run("--json", "skill", "install", "--dir", other); code != 0 || !strings.Contains(h.out.String(), `"SKILL.md"`) {
		t.Fatalf("exit %d: %s %s", code, h.out.String(), h.err.String())
	}

	// One destination only.
	if code := h.run("skill", "install", "--project", "--dir", other); code != ExitUsage {
		t.Fatalf("exit %d", code)
	}
}

func TestSkillInstallRefusesSymlinks(t *testing.T) {
	h := newHarness(t, nil)
	target := t.TempDir()
	outside := t.TempDir()
	if err := os.Symlink(outside, filepath.Join(target, "references")); err != nil {
		t.Fatal(err)
	}
	if code := h.run("skill", "install", "--dir", target, "--force"); code == 0 {
		t.Fatal("wrote through a symlinked folder")
	}
	if entries, _ := os.ReadDir(outside); len(entries) != 0 {
		t.Fatalf("wrote outside the target: %v", entries)
	}
}
