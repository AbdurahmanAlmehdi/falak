package builder

import (
	"archive/tar"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// DefaultExcludes never ship in a release. A pattern with a leading "/" is anchored at the archive root;
// otherwise it matches a path's base name at any depth. Patterns use path.Match syntax.
// storage/logs/* is what the build itself logged (e.g. laravel.log from composer scripts); it would otherwise be moved
// into the site's shared storage on the first deploy.
var DefaultExcludes = []string{".git", ".DS_Store", "/.env", "/.env.*.local", "/.falak-build", "Thumbs.db", "/storage/logs/*"}

// keptAlways ship even when an exclude pattern matches them (the directory placeholder Laravel commits).
var keptAlways = []string{"storage/logs/.gitignore"}

// TarballInfo describes a written archive.
type TarballInfo struct {
	SHA256    string `json:"sha256"`
	SizeBytes int64  `json:"size_bytes"`
	Files     int    `json:"files"`
}

// WriteTarball writes a deterministic tar.gz of root to w: entries in byte-wise path order, every mtime
// = mtime, uid/gid 0 without names, modes normalized to 0755/0644, no gzip name/timestamp. The same
// tree always yields the same bytes (for a given Go version), whatever the on-disk mtimes, owners or
// directory iteration order.
func WriteTarball(w io.Writer, root string, excludes []string, mtime time.Time) (TarballInfo, error) {
	var info TarballInfo
	type entry struct {
		rel string
		fi  fs.FileInfo
	}
	var entries []entry
	err := filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(root, p)
		if err != nil {
			return err
		}
		if rel == "." {
			return nil
		}
		rel = filepath.ToSlash(rel)
		if excluded(rel, excludes) {
			if d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		fi, err := d.Info()
		if err != nil {
			return err
		}
		entries = append(entries, entry{rel, fi})
		return nil
	})
	if err != nil {
		return info, err
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].rel < entries[j].rel })
	r, err := os.OpenRoot(root)
	if err != nil {
		return info, err
	}
	defer r.Close()

	h := sha256.New()
	cw := &countWriter{w: io.MultiWriter(w, h)}
	gz, _ := gzip.NewWriterLevel(cw, gzip.BestCompression)
	gz.Header.OS = 255 // unknown; ModTime/Name stay zero
	tw := tar.NewWriter(gz)
	mtime = mtime.UTC().Truncate(time.Second)
	for _, e := range entries {
		hdr := &tar.Header{Name: e.rel, ModTime: mtime, Uid: 0, Gid: 0, Format: tar.FormatUnknown}
		mode := e.fi.Mode()
		switch {
		case mode.IsDir():
			hdr.Typeflag, hdr.Name, hdr.Mode = tar.TypeDir, e.rel+"/", 0o755
		case mode&os.ModeSymlink != 0:
			target, err := os.Readlink(filepath.Join(root, filepath.FromSlash(e.rel)))
			if err != nil {
				return info, err
			}
			hdr.Typeflag, hdr.Linkname, hdr.Mode = tar.TypeSymlink, target, 0o777
		case mode.IsRegular():
			hdr.Typeflag, hdr.Size, hdr.Mode = tar.TypeReg, e.fi.Size(), 0o644
			if mode.Perm()&0o111 != 0 {
				hdr.Mode = 0o755
			}
		default:
			continue // sockets, devices, fifos
		}
		if len(hdr.Name) > 100 || len(hdr.Linkname) > 100 {
			hdr.Format = tar.FormatPAX // PAX records carry only path/linkpath (no atime/ctime)
		}
		if err := tw.WriteHeader(hdr); err != nil {
			return info, fmt.Errorf("tar %s: %w", e.rel, err)
		}
		if hdr.Typeflag == tar.TypeReg {
			// Through os.Root: a file swapped for a symlink after the walk cannot pull in anything outside root.
			f, err := r.Open(filepath.FromSlash(e.rel))
			if err != nil {
				return info, err
			}
			n, err := io.Copy(tw, f)
			f.Close()
			if err != nil {
				return info, fmt.Errorf("tar %s: %w", e.rel, err)
			}
			if n != hdr.Size {
				return info, fmt.Errorf("tar %s: file changed while archiving", e.rel)
			}
			info.Files++
		}
	}
	if err := tw.Close(); err != nil {
		return info, err
	}
	if err := gz.Close(); err != nil {
		return info, err
	}
	info.SHA256 = hex.EncodeToString(h.Sum(nil))
	info.SizeBytes = cw.n
	return info, nil
}

func excluded(rel string, patterns []string) bool {
	for _, k := range keptAlways {
		if rel == k {
			return false
		}
	}
	base := path.Base(rel)
	for _, p := range patterns {
		if strings.HasPrefix(p, "/") {
			if ok, _ := path.Match(strings.TrimPrefix(p, "/"), rel); ok {
				return true
			}
			continue
		}
		if ok, _ := path.Match(p, base); ok {
			return true
		}
		if strings.Contains(p, "/") {
			if ok, _ := path.Match(p, rel); ok {
				return true
			}
		}
	}
	return false
}

type countWriter struct {
	w io.Writer
	n int64
}

func (c *countWriter) Write(p []byte) (int, error) {
	n, err := c.w.Write(p)
	c.n += int64(n)
	return n, err
}
