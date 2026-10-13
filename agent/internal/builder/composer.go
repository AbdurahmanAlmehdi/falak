package builder

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
)

// The Composer a builder server uses when its host has PHP (provisioned with the PHP CLI) but no composer: pinned
// and checked against this SHA-256, so a build never runs an unverified download.
const composerVersion = "2.10.3"

var (
	composerSHA256 = "7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6"
	composerURL    = "https://getcomposer.org/download/" + composerVersion + "/composer.phar"
)

// viaComposerPhar puts the pinned composer.phar into binDir (first on the steps' PATH) when a step runs composer
// and the builder has none, and points those steps at it. The phar is cached under CacheDir. Reports whether it did.
func (b *Builder) viaComposerPhar(ctx context.Context, p *Plan, binDir string) (bool, error) {
	uses := false
	for _, st := range p.Steps {
		uses = uses || (len(st.Cmd) > 0 && st.Cmd[0] == "composer")
	}
	if !uses {
		return false, nil
	}
	if _, err := b.LookPath("composer"); err == nil {
		return false, nil
	}
	if _, err := b.LookPath("php"); err != nil {
		return false, nil // the step fails with composer not found; there is no PHP to run a phar either
	}
	cached := filepath.Join(b.CacheDir, "composer-"+composerVersion+".phar")
	if !fileHasSHA256(cached, composerSHA256) {
		if err := b.downloadVerified(ctx, composerURL, composerSHA256, cached); err != nil {
			return false, fmt.Errorf("composer %s: %w", composerVersion, err)
		}
	}
	data, err := os.ReadFile(cached)
	if err != nil {
		return false, err
	}
	if err := os.MkdirAll(binDir, 0o755); err != nil {
		return false, err
	}
	bin := filepath.Join(binDir, "composer")
	// The phar starts with #!/usr/bin/env php.
	if err := os.WriteFile(bin, data, 0o755); err != nil {
		return false, err
	}
	for i, st := range p.Steps {
		if len(st.Cmd) > 0 && st.Cmd[0] == "composer" {
			p.Steps[i].Cmd = append([]string{bin}, st.Cmd[1:]...)
		}
	}
	return true, nil
}

func fileHasSHA256(path, want string) bool {
	f, err := os.Open(path)
	if err != nil {
		return false
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return false
	}
	return hex.EncodeToString(h.Sum(nil)) == want
}

// downloadVerified writes url's body to dst only when it hashes to want.
func (b *Builder) downloadVerified(ctx context.Context, url, want, dst string) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return err
	}
	resp, err := b.HTTP.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("download %s: HTTP %d", url, resp.StatusCode)
	}
	if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(dst), ".download-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	h := sha256.New()
	if _, err := io.Copy(io.MultiWriter(tmp, h), io.LimitReader(resp.Body, 64<<20)); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if got := hex.EncodeToString(h.Sum(nil)); got != want {
		return fmt.Errorf("download %s: checksum %s, want %s", url, got, want)
	}
	return os.Rename(tmp.Name(), dst)
}
