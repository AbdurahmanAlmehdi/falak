package builder

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func TestViaComposerPhar(t *testing.T) {
	phar := []byte("#!/usr/bin/env php\n<?php echo 'composer';\n")
	sum := sha256.Sum256(phar)
	hits := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { hits++; _, _ = w.Write(phar) }))
	defer srv.Close()
	oldURL, oldSum := composerURL, composerSHA256
	defer func() { composerURL, composerSHA256 = oldURL, oldSum }()
	composerURL, composerSHA256 = srv.URL, hex.EncodeToString(sum[:])

	have := map[string]bool{"php": true}
	b := &Builder{CacheDir: t.TempDir(), HTTP: srv.Client(), LookPath: func(n string) (string, error) {
		if have[n] {
			return "/usr/bin/" + n, nil
		}
		return "", errors.New("not found")
	}}
	plan := func() Plan {
		return Plan{Steps: []Step{{Name: "composer install", Cmd: []string{"composer", "install"}}}}
	}

	bin := filepath.Join(t.TempDir(), "bin")
	p := plan()
	ok, err := b.viaComposerPhar(context.Background(), &p, bin)
	if err != nil || !ok {
		t.Fatalf("ok=%v err=%v", ok, err)
	}
	if got := p.Steps[0].Cmd[0]; got != filepath.Join(bin, "composer") {
		t.Fatalf("step runs %q", got)
	}
	if data, _ := os.ReadFile(filepath.Join(bin, "composer")); string(data) != string(phar) {
		t.Fatalf("bin = %q", data)
	}
	// Cached: a second build doesn't download again.
	p = plan()
	if ok, err := b.viaComposerPhar(context.Background(), &p, filepath.Join(t.TempDir(), "bin")); err != nil || !ok || hits != 1 {
		t.Fatalf("ok=%v err=%v hits=%d", ok, err, hits)
	}

	// A host composer wins.
	have["composer"] = true
	p = plan()
	if ok, _ := b.viaComposerPhar(context.Background(), &p, bin); ok || p.Steps[0].Cmd[0] != "composer" {
		t.Fatalf("used the phar although composer is installed: %v", p.Steps[0].Cmd)
	}

	// A download that doesn't match the checksum is refused and never cached.
	have["composer"] = false
	b.CacheDir = t.TempDir()
	composerSHA256 = "00"
	p = plan()
	if ok, err := b.viaComposerPhar(context.Background(), &p, filepath.Join(t.TempDir(), "bin")); err == nil || ok {
		t.Fatalf("checksum mismatch accepted: ok=%v err=%v", ok, err)
	}
	if entries, _ := os.ReadDir(b.CacheDir); len(entries) != 0 {
		t.Fatalf("cache kept %d files", len(entries))
	}
}
