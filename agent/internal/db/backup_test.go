package db

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestCreateDropAndUserApplyRunFalakDB(t *testing.T) {
	h := newHarness(t)
	ctx := context.Background()
	if _, err := h.db.InstanceCreate(ctx, createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	var spec string
	h.run.OnFunc("docker exec falak-db-"+instID+" falak-db user apply", func(c runnertest.Call) (runner.Result, error) {
		b, _ := os.ReadFile(h.path("/run/falak/secrets/falak-db-" + instID + strings.TrimPrefix(c.Args[len(c.Args)-1], "/run/secrets")))
		spec = string(b)
		return runner.Result{Stdout: []byte(`{"changed":true}`)}, nil
	})
	h.run.On("docker exec", runner.Result{Stdout: []byte(`{"changed":true}`)})
	h.run.Reset()

	res, err := h.db.Create(ctx, CreatePayload{Instance: instID, Engine: "mysql", Name: "shop", Charset: "utf8mb4", Collation: "utf8mb4_0900_ai_ci"}, stream())
	if err != nil || !res.(ChangedResult).Changed {
		t.Fatalf("create %+v %v", res, err)
	}
	if res, err := h.db.Drop(ctx, DropPayload{Instance: instID, Engine: "mysql", Name: "shop"}, stream()); err != nil || !res.(ChangedResult).Changed {
		t.Fatalf("drop %+v %v", res, err)
	}
	res, err = h.db.UserApply(ctx, UserPayload{Instance: instID, Engine: "mysql", Username: "shop", Password: "u-pw", Host: "%",
		Grants: []Grant{{Database: "shop", Privileges: []string{"SELECT"}}}}, stream())
	if err != nil || !res.(ChangedResult).Changed {
		t.Fatalf("user %+v %v", res, err)
	}
	lines := h.run.Lines()
	want := []string{
		"docker exec falak-db-" + instID + " falak-db database create --name shop --charset utf8mb4 --collation utf8mb4_0900_ai_ci",
		"docker exec falak-db-" + instID + " falak-db database drop --name shop",
	}
	if lines[0] != want[0] || lines[1] != want[1] || !strings.HasPrefix(lines[2], "docker exec falak-db-"+instID+" falak-db user apply --spec /run/secrets/.spec-") {
		t.Errorf("lines %q", lines)
	}
	if strings.Contains(strings.Join(lines, " "), "u-pw") {
		t.Error("the password is in argv")
	}
	if !strings.Contains(spec, `"password":"u-pw"`) || !strings.Contains(spec, `"state":"present"`) || !strings.Contains(spec, `"host":"%"`) {
		t.Errorf("spec %q", spec)
	}
	entries, _ := os.ReadDir(h.path("/run/falak/secrets/falak-db-" + instID))
	if len(entries) != 1 || entries[0].Name() != "password" {
		t.Errorf("spec file left behind: %v", entries)
	}

	for name, call := range map[string]func() error{
		"bad name": func() error {
			_, err := h.db.Create(ctx, CreatePayload{Instance: instID, Engine: "postgres", Name: "a;b"}, stream())
			return err
		},
		"redis db": func() error {
			_, err := h.db.Create(ctx, CreatePayload{Instance: instID, Engine: "redis", Name: "a"}, stream())
			return err
		},
		"bad id": func() error {
			_, err := h.db.Drop(ctx, DropPayload{Instance: "x", Engine: "postgres", Name: "a"}, stream())
			return err
		},
		"no pw": func() error {
			_, err := h.db.UserApply(ctx, UserPayload{Instance: instID, Engine: "postgres", Username: "a"}, stream())
			return err
		},
		"bad grant": func() error {
			_, err := h.db.UserApply(ctx, UserPayload{Instance: instID, Engine: "postgres", Username: "a", Password: "x", Grants: []Grant{{Database: "a b"}}}, stream())
			return err
		},
		"bad charset": func() error {
			_, err := h.db.Create(ctx, CreatePayload{Instance: instID, Engine: "mysql", Name: "a", Charset: "x;y"}, stream())
			return err
		},
	} {
		if err := call(); !commands.IsPayloadError(err) {
			t.Errorf("%s: %v", name, err)
		}
	}
}

// putServer records one presigned PUT.
type putServer struct {
	mu   sync.Mutex
	body []byte
}

func (p *putServer) handler(w http.ResponseWriter, r *http.Request) {
	b, _ := io.ReadAll(r.Body)
	p.mu.Lock()
	p.body = b
	p.mu.Unlock()
	w.WriteHeader(200)
}

// testEnc is the backups' key in tests (control-plane held).
var testEnc = backupcrypt.Encryption{Mode: "cp", KeyID: "01hzybackup000000000000001", Key: strings.Repeat("ab", 32)}

// opened decrypts a backup file with testEnc.
func opened(t *testing.T, b []byte) string {
	t.Helper()
	r, err := testEnc.Open(bytes.NewReader(b))
	if err != nil {
		t.Fatal(err)
	}
	plain, err := io.ReadAll(r)
	if err != nil {
		t.Fatal(err)
	}
	return string(plain)
}

func TestBackupStreamsFalakDBToThePresignedURL(t *testing.T) {
	h := newHarness(t)
	var ps putServer
	srv := httptest.NewTLSServer(http.HandlerFunc(ps.handler))
	defer srv.Close()
	h.db.d.HTTP = srv.Client()
	h.run.On("docker exec falak-db-"+instID+" falak-db backup logical --database app --out -", runner.Result{
		Stdout: []byte("PGDMP-custom-format"), Stderr: []byte("pg_dump: done\n" + `falak-db-result: {"kind":"logical","bytes":19}` + "\n"),
	})
	h.run.On("docker exec falak-db-"+instID+" falak-db table-counts --database app", runner.Result{Stdout: []byte(`{"tables":{"public.users":42}}`)})
	res, err := h.db.Backup(context.Background(), BackupPayload{Instance: instID, Engine: "postgres", Database: "app", Encryption: testEnc, TableCounts: true,
		Destination: Location{Kind: "presigned_url", URL: srv.URL + "/b/k?X-Amz-Signature=abc"}}, stream())
	if err != nil {
		t.Fatal(err)
	}
	r := res.(BackupResult)
	if !bytes.HasPrefix(ps.body, []byte("FKB1")) || bytes.Contains(ps.body, []byte("PGDMP")) {
		t.Fatal("the upload is not encrypted")
	}
	plain := opened(t, ps.body)
	sum := sha256.Sum256(ps.body)
	psum := sha256.Sum256([]byte(plain))
	if plain != "PGDMP-custom-format" || r.SHA256 != hex.EncodeToString(sum[:]) || r.UncompressedBytes != 19 || strings.Contains(r.Location, "Signature") ||
		r.PlaintextSHA256 != hex.EncodeToString(psum[:]) || r.Encryption != "cp" || r.KeyID != testEnc.KeyID || r.Cipher != "aes-256-gcm" || r.Compression != "zstd" {
		t.Errorf("result %+v body %q", r, plain)
	}
	if r.TableCounts["public.users"] != 42 {
		t.Errorf("counts %v", r.TableCounts)
	}

	// No result line (falak-db died mid-stream): failed.
	h.run.On("docker exec falak-db-"+instID+" falak-db backup logical --out -", runner.Result{Stdout: []byte("REDIS0012xyz")})
	if _, err := h.db.Backup(context.Background(), BackupPayload{Instance: instID, Engine: "redis", Database: "cache", Encryption: testEnc,
		Destination: Location{Kind: "presigned_url", URL: srv.URL + "/b/k"}}, stream()); err == nil || !strings.Contains(err.Error(), "no result") {
		t.Errorf("missing result: %v", err)
	}
	// Never unencrypted.
	if _, err := h.db.Backup(context.Background(), BackupPayload{Instance: instID, Engine: "postgres", Database: "app",
		Destination: Location{Kind: "presigned_url", URL: srv.URL + "/b/k"}}, stream()); !commands.IsPayloadError(err) {
		t.Errorf("no encryption: %v", err)
	}
}

func TestBackupRecordsTheRDBHeader(t *testing.T) {
	h := newHarness(t)
	h.run.On("docker exec", runner.Result{Stdout: []byte("VALKEY080\x00\x01data"), Stderr: []byte(`falak-db-result: {"bytes":15}` + "\n")})
	dest := t.TempDir() + "/out.rdb"
	res, err := h.db.Backup(context.Background(), BackupPayload{Instance: instID, Engine: "valkey", Database: "cache", Encryption: testEnc,
		Destination: Location{Kind: "local", Path: dest}}, stream())
	if err != nil {
		t.Fatal(err)
	}
	if res.(BackupResult).RDB != "VALKEY080" {
		t.Errorf("rdb %q", res.(BackupResult).RDB)
	}
}

// sealedFile writes a backup of content (sealed with testEnc) and returns its path and sha256.
func sealedFile(t *testing.T, h *harness, content string) (string, string) {
	t.Helper()
	os.MkdirAll(h.path("/srv"), 0o755)
	var b bytes.Buffer
	w, err := testEnc.Seal(&b)
	if err != nil {
		t.Fatal(err)
	}
	w.Write([]byte(content))
	w.Close()
	os.WriteFile(h.path("/srv/dump.fkb"), b.Bytes(), 0o600)
	sum := sha256.Sum256(b.Bytes())
	return "/srv/dump.fkb", hex.EncodeToString(sum[:])
}

func TestRestoreSQLPipesIntoFalakDB(t *testing.T) {
	h := newHarness(t)
	var in string
	h.run.OnFunc("docker exec -i falak-db-"+instID+" falak-db restore logical", func(c runnertest.Call) (runner.Result, error) {
		in = c.Stdin
		return runner.Result{Stdout: []byte(`{}`)}, nil
	})
	file, sha := sealedFile(t, h, "PGDMP")
	res, err := h.db.Restore(context.Background(), RestorePayload{Instance: instID, Engine: "postgres", Database: "app", Encryption: testEnc,
		Source: Location{Kind: "local", Path: file}, SHA256: sha, Owner: "app"}, stream())
	if err != nil {
		t.Fatal(err)
	}
	lines := h.run.Lines()
	// PostgreSQL: swapped in from a scratch database, ownership to the app user.
	if len(lines) != 1 || lines[0] != "docker exec -i falak-db-"+instID+" falak-db restore logical --database app --in - --swap --owner app" || in != "PGDMP" || res.(RestoreResult).Bytes != 5 {
		t.Errorf("lines %q stdin %q res %+v", lines, in, res)
	}
}

func TestRestoreKeyValueStopsTheInstance(t *testing.T) {
	h := newHarness(t)
	ctx := context.Background()
	p := createPayload()
	p.Instance.Engine, p.Instance.Version, p.Instance.Image, p.Instance.TLS = "redis", "8", "ghcr.io/falak-sh/falak-redis:8", nil
	if _, err := h.db.InstanceCreate(ctx, p, stream()); err != nil {
		t.Fatal(err)
	}
	var in string
	h.run.OnFunc("docker run", func(c runnertest.Call) (runner.Result, error) {
		in = c.Stdin
		if h.dock.containers["falak-db-"+instID].State.Running {
			t.Error("restored into a running instance")
		}
		return runner.Result{}, nil
	})
	h.dock.calls = nil
	h.run.Reset()
	file, sha := sealedFile(t, h, "REDIS0012...")
	if _, err := h.db.Restore(ctx, RestorePayload{Instance: instID, Engine: "redis", Database: "cache", Encryption: testEnc, Source: Location{Kind: "local", Path: file}, SHA256: sha}, stream()); err != nil {
		t.Fatal(err)
	}
	line := h.run.Lines()[0]
	want := "docker run --rm -i --name falak-db-" + instID + "-restore --network none --entrypoint falak-db --mount type=bind,source=" +
		h.path("/var/lib/falak/volumes/"+volID+"/data") + ",target=/data sha256:img1 restore logical --in -"
	if line != want || in != "REDIS0012..." {
		t.Errorf("line %q\nwant %q\nstdin %q", line, want, in)
	}
	if !h.dock.containers["falak-db-"+instID].State.Running || !h.dock.called("start falak-db-"+instID) {
		t.Errorf("instance not started again: %v", h.dock.calls)
	}

	// A failed restore still starts the instance.
	h.run.Reset()
	h2 := newHarness(t)
	h2.db.InstanceCreate(ctx, p, stream())
	h2.run.On("docker run", runner.Result{ExitCode: 4, Stderr: []byte("falak-db: conflict")})
	file, sha = sealedFile(t, h2, "REDIS0012...")
	if _, err := h2.db.Restore(ctx, RestorePayload{Instance: instID, Engine: "redis", Database: "cache", Encryption: testEnc, Source: Location{Kind: "local", Path: file}, SHA256: sha}, stream()); err == nil {
		t.Fatal("failed restore succeeded")
	}
	if !h2.dock.containers["falak-db-"+instID].State.Running {
		t.Error("instance left stopped")
	}
}
