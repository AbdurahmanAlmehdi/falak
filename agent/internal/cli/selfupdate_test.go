package cli

import (
	"crypto/ed25519"
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"encoding/pem"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func TestSelfUpdate(t *testing.T) {
	asset := fmt.Sprintf("falak-%s-%s", runtime.GOOS, runtime.GOARCH)
	newBin := []byte("#!/bin/sh\necho v9.9.9\n")
	sum := sha256.Sum256(newBin)
	sums := hex.EncodeToString(sum[:]) + "  " + asset + "\n"
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/releases/latest":
			http.Redirect(w, r, "/releases/tag/v9.9.9", http.StatusFound)
		case "/releases/download/v9.9.9/SHA256SUMS":
			_, _ = w.Write([]byte(sums))
		case "/releases/download/v9.9.9/" + asset:
			_, _ = w.Write(newBin)
		case "/releases/download/v8.0.0/SHA256SUMS": // a release whose binary doesn't match its sum
			_, _ = w.Write([]byte(strings.Repeat("0", 64) + "  " + asset + "\n"))
		case "/releases/download/v8.0.0/" + asset:
			_, _ = w.Write([]byte("tampered"))
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(srv.Close)

	exe := filepath.Join(t.TempDir(), "falak")
	_ = os.WriteFile(exe, []byte("old"), 0o755)
	h := newHarness(t, nil)
	h.env["FALAK_RELEASES_URL"] = srv.URL + "/releases"
	run := func(version string, args ...string) int {
		h.out.Reset()
		h.err.Reset()
		h.app = h.newApp("")
		h.app.Version = version
		h.app.Executable = func() (string, error) { return exe, nil }
		return h.app.Run(t.Context(), args)
	}
	read := func() string { b, _ := os.ReadFile(exe); return string(b) }

	if code := run("v0.11.1", "update", "--check"); code != 0 || !strings.Contains(h.out.String(), "v9.9.9 available") || read() != "old" {
		t.Fatalf("--check: %d %q %q", code, h.out, read())
	}
	if code := run("v9.9.9", "update"); code != 0 || !strings.Contains(h.out.String(), "up to date") {
		t.Fatalf("up to date: %d %q", code, h.out)
	}
	if code := run("v0.11.1", "update", "--version", "v8.0.0"); code == 0 || !strings.Contains(h.err.String(), "checksum mismatch") || read() != "old" {
		t.Fatalf("tampered: %d %q %q", code, h.err, read())
	}
	if code := run("v0.11.1", "update", "--version", "v7.0.0"); code == 0 || read() != "old" {
		t.Fatalf("missing release: %d", code)
	}
	if code := run("v0.11.1", "update"); code != 0 || read() != string(newBin) {
		t.Fatalf("update: %d %q %q", code, h.err, read())
	}
	if st, _ := os.Stat(exe); st.Mode().Perm() != 0o755 {
		t.Fatalf("mode %v", st.Mode())
	}
	if left, _ := filepath.Glob(filepath.Join(filepath.Dir(exe), ".falak-update-*")); len(left) != 0 {
		t.Fatalf("temp files left: %v", left)
	}
}

func TestReleaseSigningKeyFile(t *testing.T) {
	if releaseKeyErr != nil {
		t.Fatalf("release-signing.pub: %v", releaseKeyErr)
	}
}

func TestSelfUpdateSignature(t *testing.T) {
	pub, priv, err := ed25519.GenerateKey(nil)
	if err != nil {
		t.Fatal(err)
	}
	der, _ := x509.MarshalPKIXPublicKey(pub)
	key, err := parseReleaseKey(append([]byte("# comment\n"), pem.EncodeToMemory(&pem.Block{Type: "PUBLIC KEY", Bytes: der})...))
	if err != nil || key == nil {
		t.Fatalf("parse: %v", err)
	}
	old := releaseKey
	releaseKey = key
	t.Cleanup(func() { releaseKey = old })

	asset := fmt.Sprintf("falak-%s-%s", runtime.GOOS, runtime.GOARCH)
	newBin := []byte("#!/bin/sh\necho v9.9.9\n")
	sum := sha256.Sum256(newBin)
	sums := []byte(hex.EncodeToString(sum[:]) + "  " + asset + "\n")
	// v7.0.0 is unsigned, v8.0.0 carries a signature over something else.
	sigs := map[string][]byte{"v9.9.9": ed25519.Sign(priv, sums), "v8.0.0": ed25519.Sign(priv, []byte("other"))}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		tag, file, _ := strings.Cut(strings.TrimPrefix(r.URL.Path, "/releases/download/"), "/")
		switch file {
		case "SHA256SUMS":
			_, _ = w.Write(sums)
		case "SHA256SUMS.sig":
			if s, ok := sigs[tag]; ok {
				_, _ = w.Write(s)
				return
			}
			http.NotFound(w, r)
		case asset:
			_, _ = w.Write(newBin)
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(srv.Close)

	exe := filepath.Join(t.TempDir(), "falak")
	_ = os.WriteFile(exe, []byte("old"), 0o755)
	h := newHarness(t, nil)
	h.env["FALAK_RELEASES_URL"] = srv.URL + "/releases"
	run := func(version string) int {
		h.out.Reset()
		h.err.Reset()
		h.app = h.newApp("")
		h.app.Version = "v0.11.1"
		h.app.Executable = func() (string, error) { return exe, nil }
		return h.app.Run(t.Context(), []string{"update", "--version", version})
	}
	read := func() string { b, _ := os.ReadFile(exe); return string(b) }

	if code := run("v7.0.0"); code == 0 || !strings.Contains(h.err.String(), "not signed") || read() != "old" {
		t.Fatalf("unsigned: %d %q %q", code, h.err, read())
	}
	if code := run("v8.0.0"); code == 0 || !strings.Contains(h.err.String(), "does not match its signature") || read() != "old" {
		t.Fatalf("bad signature: %d %q %q", code, h.err, read())
	}
	if code := run("v9.9.9"); code != 0 || read() != string(newBin) {
		t.Fatalf("signed: %d %q %q", code, h.err, read())
	}
}
