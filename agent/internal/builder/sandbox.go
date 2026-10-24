package builder

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Native build steps run repository code. A shared builder serves every organization, so a builder running as root
// runs the steps as an unprivileged uid of the build's organization (allocated from orgUIDBase, no passwd entry) with
// a clean environment: they cannot read the builder's token, its state or another organization's workspace or caches.
// A builder that is not root (local `falak-builder run`) runs them as itself.
const orgUIDBase = 200000

// Vars: tests fake root.
var (
	dropPrivileges = func() bool { return os.Geteuid() == 0 }
	lchown         = os.Lchown
)

// sandbox prepares j for its build steps: the organization's cache dir, a HOME, and (as root) the org uid owning the
// checkout, HOME, bin dir and cache while the builder's own dirs stay root-only. Runs before any step.
func (b *Builder) sandbox(j *job) error {
	orgs := filepath.Join(b.CacheDir, "orgs")
	j.cache = filepath.Join(orgs, firstNonEmpty(j.OrganizationID, "_"))
	j.home = filepath.Join(j.ws, "home")
	if err := os.MkdirAll(j.home, 0o700); err != nil {
		return err
	}
	if !dropPrivileges() {
		return os.MkdirAll(j.cache, 0o700)
	}
	uid, err := b.orgUID(orgs, j.OrganizationID)
	if err != nil {
		return fmt.Errorf("build uid: %w", err)
	}
	if err := os.MkdirAll(j.cache, 0o700); err != nil {
		return err
	}
	// Traversable, not listable: the step reaches its own workspace and cache by name only.
	for _, d := range []string{b.WorkDir, j.ws, b.CacheDir, orgs} {
		if err := os.Chmod(d, 0o711); err != nil {
			return err
		}
	}
	for _, d := range []string{j.src, j.home, j.binDir, j.cache} {
		if d == "" {
			continue
		}
		if err := chownTree(d, int(uid)); err != nil {
			return err
		}
	}
	j.uid = uid
	return nil
}

// orgUID returns the organization's build uid from <dir>/uids.json (root 0600), allocating the next free one. The map
// lives beside the caches it guards: when it is missing the caches are dropped too, since a lost map would hand an
// organization's cache to whichever organization gets its uid next.
// ponytail: no file lock, one falak-builder process per cache dir.
func (b *Builder) orgUID(dir, org string) (uint32, error) {
	file := filepath.Join(dir, "uids.json")
	uids := map[string]uint32{}
	data, err := os.ReadFile(file)
	switch {
	case err == nil:
		if err := json.Unmarshal(data, &uids); err != nil {
			return 0, fmt.Errorf("%s: %w", file, err)
		}
	case errors.Is(err, fs.ErrNotExist):
		if err := os.RemoveAll(dir); err != nil {
			return 0, err
		}
	default:
		return 0, err
	}
	if uid, ok := uids[org]; ok {
		return uid, nil
	}
	uid := uint32(orgUIDBase + len(uids))
	uids[org] = uid
	if err := os.MkdirAll(dir, 0o711); err != nil {
		return 0, err
	}
	data, _ = json.Marshal(uids)
	tmp := file + ".tmp"
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return 0, err
	}
	return uid, os.Rename(tmp, file)
}

// chownTree hands dir to uid (gid = uid) without following symlinks. Only called while no build process runs.
func chownTree(dir string, uid int) error {
	return filepath.WalkDir(dir, func(p string, _ fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		return lchown(p, uid, uid)
	})
}

// reap kills every process left by the build uid (daemons a step started, setsid or not), so nothing changes the
// workspace while the builder packages it. `kill -1` as that uid signals exactly its processes, never the builder's.
func (b *Builder) reap(j *job) {
	if j.uid == 0 {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	_, _ = b.Runner.Run(ctx, runner.Cmd{Name: "/bin/sh", Args: []string{"-c", "kill -9 -1"}, ClearEnv: true, UID: j.uid, GID: j.uid})
}
