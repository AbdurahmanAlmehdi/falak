package cli

import (
	"bufio"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// defaultReleases is where install-cli.sh downloads from; FALAK_RELEASES_URL overrides it (mirrors, tests).
const defaultReleases = "https://github.com/falak-sh/falak/releases"

// cmdUpdate replaces the running falak binary with a release's, checked against the release's SHA256SUMS,
// the same way install-cli.sh installs it.
func cmdUpdate(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("update")
	want := fs.String("version", "", "install this release (vX.Y.Z) instead of the latest")
	check := fs.Bool("check", false, "only report whether a newer release exists")
	if _, err := a.parse(fs, args, 0, 0, "[--version vX.Y.Z] [--check]"); err != nil {
		return err
	}
	releases := strings.TrimRight(a.Getenv("FALAK_RELEASES_URL"), "/")
	if releases == "" {
		releases = defaultReleases
	}
	hc := &http.Client{Timeout: 5 * time.Minute}

	tag := *want
	if tag == "" {
		var err error
		if tag, err = latestRelease(ctx, releases); err != nil {
			return err
		}
	}
	if !strings.HasPrefix(tag, "v") {
		tag = "v" + tag
	}
	if tag == a.Version {
		fmt.Fprintf(a.Stdout, "falak %s is up to date\n", a.Version)
		return nil
	}
	if *check {
		run := "falak update"
		if *want != "" {
			run += " --version " + tag
		}
		fmt.Fprintf(a.Stdout, "falak %s → %s available: run `%s`\n", a.Version, tag, run)
		return nil
	}

	exe, err := a.executable()
	if err != nil {
		return fmt.Errorf("find the running binary: %w", err)
	}
	asset := fmt.Sprintf("falak-%s-%s", runtime.GOOS, runtime.GOARCH)
	base := releases + "/download/" + tag
	sums, err := fetch(ctx, hc, base+"/SHA256SUMS")
	if err != nil {
		return err
	}
	sum, err := checksumFor(sums, asset)
	if err != nil {
		return fmt.Errorf("%s: %w", tag, err)
	}
	bin, err := fetch(ctx, hc, base+"/"+asset)
	if err != nil {
		return err
	}
	if got := sha256.Sum256(bin); hex.EncodeToString(got[:]) != sum {
		return fmt.Errorf("checksum mismatch for %s %s: not installed", asset, tag)
	}

	// Write next to the binary and rename over it: atomic, and the running process keeps its old inode.
	tmp, err := os.CreateTemp(filepath.Dir(exe), ".falak-update-*")
	if err != nil {
		return fmt.Errorf("cannot write %s (re-run with sudo, or reinstall with install-cli.sh): %w", filepath.Dir(exe), err)
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(bin); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if err := os.Chmod(tmp.Name(), 0o755); err != nil {
		return err
	}
	if err := os.Rename(tmp.Name(), exe); err != nil {
		return fmt.Errorf("replace %s (re-run with sudo): %w", exe, err)
	}
	fmt.Fprintf(a.Stdout, "falak %s → %s (%s, checksum verified)\n", a.Version, tag, exe)
	return nil
}

func (a *App) executable() (string, error) {
	if a.Executable != nil {
		return a.Executable()
	}
	p, err := os.Executable()
	if err != nil {
		return "", err
	}
	return filepath.EvalSymlinks(p) // a symlinked install (Homebrew-style) updates the real file
}

// latestRelease reads the tag from the redirect of /releases/latest (no GitHub API rate limit).
func latestRelease(ctx context.Context, releases string) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodHead, releases+"/latest", nil)
	if err != nil {
		return "", err
	}
	hc := &http.Client{Timeout: 30 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	resp, err := hc.Do(req)
	if err != nil {
		return "", fmt.Errorf("find the latest release: %w", err)
	}
	resp.Body.Close()
	loc := resp.Header.Get("Location")
	i := strings.LastIndex(loc, "/tag/")
	if i < 0 {
		return "", fmt.Errorf("find the latest release: %s answered %d without a release tag", releases+"/latest", resp.StatusCode)
	}
	return loc[i+len("/tag/"):], nil
}

func fetch(ctx context.Context, hc *http.Client, url string) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	resp, err := hc.Do(req)
	if err != nil {
		return nil, fmt.Errorf("download %s: %w", url, err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("download %s: HTTP %d", url, resp.StatusCode)
	}
	return io.ReadAll(io.LimitReader(resp.Body, 200<<20))
}

// checksumFor finds asset's line in a sha256sum file ("<hex>  name" or "<hex> *name").
func checksumFor(sums []byte, asset string) (string, error) {
	sc := bufio.NewScanner(strings.NewReader(string(sums)))
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) == 2 && strings.TrimPrefix(f[1], "*") == asset && len(f[0]) == 64 {
			return strings.ToLower(f[0]), nil
		}
	}
	return "", errors.New(asset + " is not listed in SHA256SUMS")
}
