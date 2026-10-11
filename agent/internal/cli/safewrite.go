package cli

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

// writeInRepo writes rel under root without following symlinks: every directory on the way must resolve inside the
// repository, the target must not be a symlink (dangling ones included), and without replace an existing target is
// an error. The file is written aside and linked (new) or renamed (replace) into place.
func writeInRepo(root, rel string, content []byte, replace bool) error {
	realRoot, err := filepath.EvalSymlinks(root)
	if err != nil {
		return err
	}
	target := filepath.Join(root, filepath.FromSlash(rel))
	dir := filepath.Dir(target)
	if err := mkdirInRepo(realRoot, root, dir); err != nil {
		return err
	}
	if st, err := os.Lstat(target); err == nil {
		if st.Mode()&os.ModeSymlink != 0 {
			return fmt.Errorf("%s is a symlink: refusing to write through it", rel)
		}
		if !st.Mode().IsRegular() {
			return fmt.Errorf("%s is not a regular file", rel)
		}
		if !replace {
			return fmt.Errorf("%s already exists", rel)
		}
	} else if !errors.Is(err, os.ErrNotExist) {
		return err
	}
	tmp, err := os.CreateTemp(dir, ".falak-init-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(content); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Chmod(0o644); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if replace {
		return os.Rename(tmp.Name(), target)
	}
	// link(2) fails when the name exists in any form, a dangling symlink included (no race with the Lstat above).
	if err := os.Link(tmp.Name(), target); err != nil {
		if errors.Is(err, os.ErrExist) {
			return fmt.Errorf("%s already exists", rel)
		}
		return err
	}
	return nil
}

// mkdirInRepo creates dir (under root) one level at a time, refusing any level that is a symlink or resolves outside
// the repository.
func mkdirInRepo(realRoot, root, dir string) error {
	rel, err := filepath.Rel(root, dir)
	if err != nil || rel == ".." || (len(rel) > 2 && rel[:3] == ".."+string(filepath.Separator)) {
		return fmt.Errorf("%s is outside the repository", dir)
	}
	cur := root
	if rel != "." {
		for _, part := range splitPath(rel) {
			cur = filepath.Join(cur, part)
			st, err := os.Lstat(cur)
			switch {
			case errors.Is(err, os.ErrNotExist):
				if err := os.Mkdir(cur, 0o755); err != nil {
					return err
				}
			case err != nil:
				return err
			case st.Mode()&os.ModeSymlink != 0:
				shown, _ := filepath.Rel(root, cur)
				return fmt.Errorf("%s is a symlink: refusing to write through it", filepath.ToSlash(shown))
			case !st.IsDir():
				return fmt.Errorf("%s is not a directory", cur)
			}
		}
	}
	if !within(realRoot, dir) {
		return fmt.Errorf("%s leads out of the repository", dir)
	}
	return nil
}

func splitPath(p string) []string {
	var parts []string
	for p != "." && p != "" && p != string(filepath.Separator) {
		dir, file := filepath.Split(p)
		parts = append([]string{file}, parts...)
		p = filepath.Clean(dir)
		if dir == "" {
			break
		}
	}
	return parts
}
