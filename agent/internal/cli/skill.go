package cli

import (
	"context"
	"embed"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
)

// skillFiles is skills/falak (the Agent Skill), copied here because go:embed can't reach outside the module;
// TestSkillCopyMatchesRepository fails when the two differ.
//
//go:embed all:skill/falak
var skillFiles embed.FS

func skillCommands() *command {
	return &command{name: "skill", summary: "the Falak Agent Skill for AI coding agents", subs: []*command{
		{name: "install", args: "[--user | --project | --dir DIR] [--force]", summary: "write the Falak skill (SKILL.md and references) for Claude Code and other SKILL.md agents", run: cmdSkillInstall},
	}}
}

func cmdSkillInstall(_ context.Context, a *App, args []string) error {
	f := a.flagSet("skill install")
	user := f.Bool("user", false, "for your user: ~/.claude/skills/falak (the default)")
	project := f.Bool("project", false, "for this project: .claude/skills/falak in the working directory")
	dir := f.String("dir", "", "into this directory instead (another agent's skills folder)")
	force := f.Bool("force", false, "replace files that already exist")
	if _, err := a.parse(f, args, 0, 0, "[--user | --project | --dir DIR] [--force]"); err != nil {
		return err
	}
	n := 0
	for _, set := range []bool{*user, *project, *dir != ""} {
		if set {
			n++
		}
	}
	if n > 1 {
		return usagef("skill install: pick one of --user, --project and --dir")
	}
	var target string
	switch {
	case *dir != "":
		target = a.abs(*dir)
	case *project:
		target = filepath.Join(a.workDir(), ".claude", "skills", "falak")
	default:
		home := a.Getenv("HOME")
		if home == "" {
			h, err := os.UserHomeDir()
			if err != nil {
				return err
			}
			home = h
		}
		target = filepath.Join(home, ".claude", "skills", "falak")
	}

	src, err := fs.Sub(skillFiles, "skill/falak")
	if err != nil {
		return err
	}
	var files []string
	if err := fs.WalkDir(src, ".", func(p string, d fs.DirEntry, err error) error {
		if err == nil && !d.IsDir() {
			files = append(files, p)
		}
		return err
	}); err != nil {
		return err
	}
	// Check every file first: without --force nothing is written when one exists.
	if !*force {
		var existing []string
		for _, p := range files {
			if _, err := os.Lstat(filepath.Join(target, filepath.FromSlash(p))); err == nil {
				existing = append(existing, p)
			}
		}
		if len(existing) > 0 {
			return fmt.Errorf("%s already has %s: rerun with --force to replace them", target, strings.Join(existing, ", "))
		}
	}
	if err := os.MkdirAll(target, 0o755); err != nil {
		return err
	}
	for _, p := range files {
		b, err := fs.ReadFile(src, p)
		if err != nil {
			return err
		}
		// Never through a symlink, and only under target (writeInRepo's checks).
		if err := writeInRepo(target, p, b, *force); err != nil {
			return err
		}
	}
	if a.jsonOut {
		return a.printJSON(map[string]any{"dir": target, "files": files})
	}
	fmt.Fprintf(a.Stdout, "Installed the Falak skill in %s (%d files)\n", target, len(files))
	return nil
}
