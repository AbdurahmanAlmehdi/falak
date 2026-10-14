// Package hostfs gives executors a re-rootable view of the host filesystem so that code writing to
// /etc, /srv, /opt ... can be tested inside a temp directory.
package hostfs

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"
)

// FS maps absolute host paths under Root. Root "" or "/" is the real filesystem.
type FS struct {
	Root string
}

// P maps an absolute host path to the real path.
func (f FS) P(p string) string {
	if f.Root == "" || f.Root == "/" {
		return filepath.Clean(p)
	}
	return filepath.Join(f.Root, filepath.Clean("/"+p))
}

// IsReal reports whether FS operates on the real root (chown etc. only make sense there).
func (f FS) IsReal() bool { return f.Root == "" || f.Root == "/" }

// ReadFile reads a host file.
func (f FS) ReadFile(p string) ([]byte, error) { return os.ReadFile(f.P(p)) }

// Readlink returns the target of the symlink at host path p, as stored: an absolute target is a host path (the link
// lives on the host, e.g. /proc/<pid>/cwd), not one under Root.
func (f FS) Readlink(p string) (string, error) { return os.Readlink(f.P(p)) }

// EvalSymlinks resolves every symlink in host path p (like filepath.EvalSymlinks) and returns the host path it
// names: under a re-rooted FS an absolute link target is a host path too, resolved under Root.
func (f FS) EvalSymlinks(p string) (string, error) {
	if f.IsReal() {
		return filepath.EvalSymlinks(filepath.Clean(p))
	}
	rest := strings.Split(filepath.Clean("/"+p), "/")
	cur := "/"
	for hops := 0; len(rest) > 0; {
		name := rest[0]
		rest = rest[1:]
		switch name {
		case "", ".":
			continue
		case "..":
			cur = filepath.Dir(cur)
			continue
		}
		next := filepath.Join(cur, name)
		fi, err := os.Lstat(f.P(next))
		if err != nil {
			return "", err
		}
		if fi.Mode()&fs.ModeSymlink == 0 {
			cur = next
			continue
		}
		if hops++; hops > 255 {
			return "", fmt.Errorf("%s: too many levels of symbolic links", p)
		}
		target, err := os.Readlink(f.P(next))
		if err != nil {
			return "", err
		}
		if filepath.IsAbs(target) {
			cur = "/"
		}
		rest = append(strings.Split(target, "/"), rest...)
	}
	return cur, nil
}

// Exists reports whether p exists (without following a final symlink).
func (f FS) Exists(p string) bool {
	_, err := os.Lstat(f.P(p))
	return err == nil
}

// MkdirAll creates a directory tree.
func (f FS) MkdirAll(p string, mode os.FileMode) error { return os.MkdirAll(f.P(p), mode) }

// Remove removes a file; missing files are not an error. Returns whether something was removed.
func (f FS) Remove(p string) (bool, error) {
	err := os.Remove(f.P(p))
	if errors.Is(err, fs.ErrNotExist) {
		return false, nil
	}
	return err == nil, err
}

// WriteFile atomically writes data (tmp file in the same dir + fsync + rename) and applies mode.
// It returns changed=false when content and mode already match.
func (f FS) WriteFile(p string, data []byte, mode os.FileMode) (bool, error) {
	real := f.P(p)
	if cur, err := os.ReadFile(real); err == nil && bytes.Equal(cur, data) {
		st, err := os.Stat(real)
		if err == nil && st.Mode().Perm() == mode.Perm() {
			return false, nil
		}
		return true, os.Chmod(real, mode)
	}
	if err := os.MkdirAll(filepath.Dir(real), 0o755); err != nil {
		return false, err
	}
	tmp, err := os.CreateTemp(filepath.Dir(real), "."+filepath.Base(real)+".falak-*")
	if err != nil {
		return false, err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return false, err
	}
	if err := tmp.Chmod(mode); err != nil {
		tmp.Close()
		return false, err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return false, err
	}
	if err := tmp.Close(); err != nil {
		return false, err
	}
	return true, os.Rename(tmp.Name(), real)
}

// Chown sets ownership by name. Empty user is a no-op; empty group means the user's primary group.
func (f FS) Chown(p, usr, group string) error {
	if usr == "" {
		return nil
	}
	uid, gid, err := LookupIDs(usr, group)
	if err != nil {
		return err
	}
	return os.Lchown(f.P(p), uid, gid)
}

// ChownR recursively chowns a tree (symlinks themselves, not targets).
func (f FS) ChownR(p, usr, group string) error {
	if usr == "" {
		return nil
	}
	uid, gid, err := LookupIDs(usr, group)
	if err != nil {
		return err
	}
	return filepath.WalkDir(f.P(p), func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		return os.Lchown(path, uid, gid)
	})
}

// LookupIDs resolves user and group names to numeric ids.
func LookupIDs(usr, group string) (int, int, error) {
	u, err := user.Lookup(usr)
	if err != nil {
		return 0, 0, fmt.Errorf("lookup user %q: %w", usr, err)
	}
	uid, _ := strconv.Atoi(u.Uid)
	gid, _ := strconv.Atoi(u.Gid)
	if group != "" {
		g, err := user.LookupGroup(group)
		if err != nil {
			return 0, 0, fmt.Errorf("lookup group %q: %w", group, err)
		}
		gid, _ = strconv.Atoi(g.Gid)
	}
	return uid, gid, nil
}

// SHA256 returns the lowercase hex digest of b.
func SHA256(b []byte) string {
	s := sha256.Sum256(b)
	return hex.EncodeToString(s[:])
}

// ParseMode parses an octal mode string ("0644", "755"); empty returns def.
func ParseMode(s string, def os.FileMode) (os.FileMode, error) {
	if s == "" {
		return def, nil
	}
	n, err := strconv.ParseUint(s, 8, 32)
	if err != nil {
		return 0, fmt.Errorf("invalid mode %q", s)
	}
	return os.FileMode(n), nil
}

// EnsureTraversable adds the search (x) bit for group and others to an existing directory so other users can reach
// what's inside without listing it (0700 → 0711). Missing directories are left alone. Returns whether it changed.
func EnsureTraversable(f FS, dir string) (bool, error) {
	fi, err := os.Stat(f.P(dir))
	if errors.Is(err, fs.ErrNotExist) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	perm := fi.Mode().Perm()
	if !fi.IsDir() || perm&0o011 == 0o011 {
		return false, nil
	}
	return true, os.Chmod(f.P(dir), perm|0o011)
}
