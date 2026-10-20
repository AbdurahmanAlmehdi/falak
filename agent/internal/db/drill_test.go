package db

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"

	"filippo.io/age"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// bucket is a presigned-URL object store: PUT stores, GET serves.
type bucket struct {
	mu      sync.Mutex
	objects map[string][]byte
	srv     *httptest.Server
}

func newBucket(t *testing.T) *bucket {
	b := &bucket{objects: map[string][]byte{}}
	b.srv = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b.mu.Lock()
		defer b.mu.Unlock()
		switch r.Method {
		case http.MethodPut:
			var buf bytes.Buffer
			buf.ReadFrom(r.Body)
			b.objects[r.URL.Path] = buf.Bytes()
		case http.MethodGet:
			o, ok := b.objects[r.URL.Path]
			if !ok {
				w.WriteHeader(404)
				return
			}
			w.Write(o)
		}
	}))
	t.Cleanup(b.srv.Close)
	return b
}

func (b *bucket) url(key string) string { return b.srv.URL + "/" + key + "?X-Amz-Signature=sig" }

// backedUp runs db.backup of "app" (postgres) into the bucket and returns the result.
func backedUp(t *testing.T, h *harness, b *bucket, enc backupcrypt.Encryption, dump string) BackupResult {
	t.Helper()
	h.run.On("docker exec falak-db-"+instID+" falak-db backup logical --database app --out -", runner.Result{
		Stdout: []byte(dump), Stderr: []byte(`falak-db-result: {"kind":"logical","bytes":1}` + "\n"),
	})
	h.run.On("docker exec falak-db-"+instID+" falak-db table-counts", runner.Result{Stdout: []byte(`{"tables":{"public.users":100,"public.orders":1000,"public.tiny":0}}`)})
	res, err := h.db.Backup(context.Background(), BackupPayload{Instance: instID, Engine: "postgres", Database: "app", Encryption: enc, TableCounts: true,
		Destination: Location{Kind: "presigned_url", URL: b.url("app.fkb")}}, stream())
	if err != nil {
		t.Fatal(err)
	}
	return res.(BackupResult)
}

func TestBackupRestoreThroughPresignedURLs(t *testing.T) {
	h := newHarness(t)
	b := newBucket(t)
	h.db.d.HTTP = b.srv.Client()
	dump := strings.Repeat("PGDMP row data\n", 5000)
	br := backedUp(t, h, b, testEnc, dump)

	var restored string
	h.run.OnFunc("docker exec -i falak-db-"+instID+" falak-db restore logical", func(c runnertest.Call) (runner.Result, error) {
		restored = c.Stdin
		return runner.Result{Stdout: []byte(`{}`)}, nil
	})
	src := Location{Kind: "url", URL: b.url("app.fkb")}
	if _, err := h.db.Restore(context.Background(), RestorePayload{Instance: instID, Engine: "postgres", Database: "app", Encryption: testEnc,
		Source: src, SHA256: br.SHA256, PlaintextSHA256: br.PlaintextSHA256}, stream()); err != nil {
		t.Fatal(err)
	}
	if restored != dump {
		t.Fatalf("restored %d bytes, want %d", len(restored), len(dump))
	}

	// Tampered in the bucket, the record's sha256 updated too (say, a compromised record): the cipher still refuses,
	// and falak-db is never fed a byte.
	obj := bytes.Clone(b.objects["/app.fkb"])
	obj[len(obj)/2] ^= 1
	b.objects["/app.fkb"] = obj
	sum := sha256.Sum256(obj)
	restored = ""
	h.run.Reset()
	_, err := h.db.Restore(context.Background(), RestorePayload{Instance: instID, Engine: "postgres", Database: "app", Encryption: testEnc,
		Source: src, SHA256: hex.EncodeToString(sum[:])}, stream())
	if err == nil || !strings.Contains(err.Error(), "corrupt") {
		t.Fatalf("tampered: %v", err)
	}
	// Verified before anything runs: falak-db never started.
	if restored != "" || len(h.run.Lines()) != 0 {
		t.Fatalf("falak-db ran for a tampered backup: %q", h.run.Lines())
	}
	// A restore without the stored file's sha256 is refused, and so is one the staging directory has no room for.
	if _, err := h.db.Restore(context.Background(), RestorePayload{Instance: instID, Engine: "postgres", Database: "app", Encryption: testEnc,
		Source: src}, stream()); !commands.IsPayloadError(err) {
		t.Fatalf("no sha256: %v", err)
	}
	h.db.d.FreeBytes = func(string) (int64, error) { return 1 << 20, nil }
	if _, err := h.db.Restore(context.Background(), RestorePayload{Instance: instID, Engine: "postgres", Database: "app", Encryption: testEnc,
		Source: src, SHA256: br.SHA256, ArchiveBytes: br.SizeBytes}, stream()); err == nil || !strings.Contains(err.Error(), "free") {
		t.Fatalf("no room: %v", err)
	}
	h.db.d.FreeBytes = freeBytes
	// The recorded sha256 of the object is checked first.
	if _, err := h.db.Restore(context.Background(), RestorePayload{Instance: instID, Engine: "postgres", Database: "app", Encryption: testEnc,
		Source: src, SHA256: br.SHA256}, stream()); err == nil {
		t.Fatal("a changed object was downloaded and accepted")
	}
}

func TestRestoreSecretsAreMasked(t *testing.T) {
	id, _ := age.GenerateX25519Identity()
	p := RestorePayload{Encryption: backupcrypt.Encryption{Mode: "age", KeyID: "k", Identity: id.String()}}
	if s := p.Secrets(); s[1] != id.String() {
		t.Fatalf("secrets %v", s)
	}
	bp := BackupPayload{Encryption: testEnc}
	if s := bp.Secrets(); s[0] != testEnc.Key {
		t.Fatalf("secrets %v", s)
	}
	dp := DrillPayload{Encryption: testEnc}
	if s := dp.Secrets(); s[0] != testEnc.Key {
		t.Fatalf("secrets %v", s)
	}
}

func TestCustomerHeldBackupRestores(t *testing.T) {
	h := newHarness(t)
	b := newBucket(t)
	h.db.d.HTTP = b.srv.Client()
	id, _ := age.GenerateX25519Identity()
	enc := backupcrypt.Encryption{Mode: "age", KeyID: "01hzybackup000000000000009", Recipient: id.Recipient().String()}
	br := backedUp(t, h, b, enc, "PGDMP customer")
	if br.Encryption != "age" {
		t.Fatalf("%+v", br)
	}
	h.run.On("docker exec -i falak-db-"+instID+" falak-db restore logical", runner.Result{Stdout: []byte(`{}`)})
	open := backupcrypt.Encryption{Mode: "age", KeyID: enc.KeyID, Identity: id.String()}
	if _, err := h.db.Restore(context.Background(), RestorePayload{Instance: instID, Engine: "postgres", Database: "app", Encryption: open,
		Source: Location{Kind: "url", URL: b.url("app.fkb")}, SHA256: br.SHA256}, stream()); err != nil {
		t.Fatal(err)
	}
	other, _ := age.GenerateX25519Identity()
	open.Identity = other.String()
	if _, err := h.db.Restore(context.Background(), RestorePayload{Instance: instID, Engine: "postgres", Database: "app", Encryption: open,
		Source: Location{Kind: "url", URL: b.url("app.fkb")}, SHA256: br.SHA256}, stream()); err == nil || !strings.Contains(err.Error(), "does not match") {
		t.Fatalf("other identity: %v", err)
	}
}

func drillPayload(b *bucket, br BackupResult) DrillPayload {
	return DrillPayload{
		Drill:    "01hzydrill0000000000000001",
		Instance: DrillInstance{Engine: "postgres", Version: "17", Image: "ghcr.io/falak-sh/falak-postgres:17", Digest: "sha256:" + strings.Repeat("a", 64), MemoryBytes: 256 << 20},
		Database: "app", Source: Location{Kind: "url", URL: b.url("app.fkb")}, SHA256: br.SHA256, PlaintextSHA256: br.PlaintextSHA256,
		ArchiveBytes: br.SizeBytes, UncompressedBytes: br.UncompressedBytes, Encryption: testEnc,
		Checks: DrillChecks{TableCounts: br.TableCounts, Query: "SELECT 1 FROM users"},
	}
}

func TestDrillRestoresIntoAThrowawayContainer(t *testing.T) {
	h := newHarness(t)
	b := newBucket(t)
	h.db.d.HTTP = b.srv.Client()
	h.db.d.MemAvailable = func() (int64, error) { return 4 << 30, nil }
	h.db.d.FreeBytes = func(string) (int64, error) { return 100 << 30, nil }
	br := backedUp(t, h, b, testEnc, "PGDMP drill")
	name := "falak-db-drill-01hzydrill0000000000000001"
	var restored string
	h.run.OnFunc("docker exec -i "+name+" falak-db restore logical --database app --in -", func(c runnertest.Call) (runner.Result, error) {
		if !h.dock.containers[name].State.Running {
			t.Error("restored into a stopped drill container")
		}
		restored = c.Stdin
		return runner.Result{Stdout: []byte(`{}`)}, nil
	})
	h.run.On("docker exec "+name+" falak-db database create --name app", runner.Result{Stdout: []byte(`{"changed":true}`)})
	counts := `{"tables":{"public.users":104,"public.orders":950,"public.tiny":0}}`
	h.run.OnFunc("docker exec "+name+" falak-db table-counts --database app", func(runnertest.Call) (runner.Result, error) {
		return runner.Result{Stdout: []byte(counts)}, nil
	})
	var query string
	rows := `{"rows":3}`
	h.run.OnFunc("docker exec -i "+name+" falak-db query --database app", func(c runnertest.Call) (runner.Result, error) {
		query = c.Stdin
		return runner.Result{Stdout: []byte(rows)}, nil
	})
	p := drillPayload(b, br)
	res, err := h.db.Drill(context.Background(), p, stream())
	if err != nil {
		t.Fatal(err)
	}
	r := res.(DrillResult)
	if r.Status != "passed" || r.Tables != 3 || len(r.Checks) != 4 || restored != "PGDMP drill" || query != "SELECT 1 FROM users" {
		t.Fatalf("%+v restored %q", r, restored)
	}
	body := h.dock.bodies[name]
	if body.HostConfig.NetworkMode != "none" || body.HostConfig.Memory != 256<<20 || len(body.HostConfig.PortBindings) != 0 ||
		body.Labels[LabelDrill] != p.Drill || body.HostConfig.RestartPolicy.Name != "" || !strings.HasSuffix(body.Image, "@"+p.Instance.Digest) ||
		body.HostConfig.NanoCPUs != 1e9 || body.HostConfig.PidsLimit != 512 || strings.Join(body.HostConfig.CapDrop, ",") != "ALL" ||
		strings.Join(body.HostConfig.CapAdd, ",") != "CHOWN,DAC_OVERRIDE,FOWNER,SETUID,SETGID" || strings.Join(body.HostConfig.SecurityOpt, ",") != "no-new-privileges" {
		t.Fatalf("drill container %+v", body)
	}
	// Gone, with its data and password.
	if _, ok := h.dock.containers[name]; ok {
		t.Fatal("the drill container was left behind")
	}
	for _, p := range []string{"/var/lib/falak/drills/" + p.Drill, "/run/falak/secrets/" + name} {
		if _, err := os.Stat(h.path(p)); !os.IsNotExist(err) {
			t.Fatalf("%s left behind", p)
		}
	}

	// Row counts out of tolerance and an empty check: failed, still cleaned up.
	counts = `{"tables":{"public.users":50,"public.tiny":0}}`
	rows = `{"rows":0}`
	res, err = h.db.Drill(context.Background(), p, stream())
	r = res.(DrillResult)
	if err != nil || r.Status != "failed" {
		t.Fatalf("%+v %v", r, err)
	}
	failed := map[string]string{}
	for _, c := range r.Checks {
		if !c.Passed {
			failed[c.Name] = c.Detail
		}
	}
	if !strings.Contains(failed["row_counts"], "public.orders: missing") || !strings.Contains(failed["row_counts"], "public.users: 50 rows") || failed["query"] != "0 rows" {
		t.Fatalf("failed checks %v", failed)
	}
	if _, ok := h.dock.containers[name]; ok {
		t.Fatal("the drill container was left behind after a failure")
	}

	// A restore that fails (falak-db exits 1): failed with the restore check.
	h2 := newHarness(t)
	h2.db.d = h.db.d
	h2.db.d.Runner = &runnertest.Fake{}
	h2.run = h2.db.d.Runner.(*runnertest.Fake)
	h2.run.On("docker exec "+name+" falak-db database create", runner.Result{Stdout: []byte(`{}`)})
	h2.run.On("docker exec -i "+name+" falak-db restore logical", runner.Result{ExitCode: 1, Stderr: []byte("pg_restore: error")})
	h = h2
	res, err = h.db.Drill(context.Background(), p, stream())
	if r := res.(DrillResult); err != nil || r.Status != "failed" || r.Checks[0].Name != "restore" {
		t.Fatalf("%+v %v", res, err)
	}
}

func TestDrillSkipsWithoutRoom(t *testing.T) {
	h := newHarness(t)
	b := newBucket(t)
	h.db.d.HTTP = b.srv.Client()
	br := backedUp(t, h, b, testEnc, "PGDMP")
	p := drillPayload(b, br)
	h.db.d.MemAvailable = func() (int64, error) { return 200 << 20, nil }
	h.db.d.FreeBytes = func(string) (int64, error) { return 100 << 30, nil }
	res, err := h.db.Drill(context.Background(), p, stream())
	if r := res.(DrillResult); err != nil || r.Status != "skipped" || !strings.Contains(r.Reason, "memory") {
		t.Fatalf("%+v %v", res, err)
	}
	h.db.d.MemAvailable = func() (int64, error) { return 8 << 30, nil }
	h.db.d.FreeBytes = func(string) (int64, error) { return 1 << 20, nil }
	res, err = h.db.Drill(context.Background(), p, stream())
	if r := res.(DrillResult); err != nil || r.Status != "skipped" || !strings.Contains(r.Reason, "free") {
		t.Fatalf("%+v %v", res, err)
	}
	if len(h.dock.pulls) != 0 {
		t.Fatal("a skipped drill pulled the image")
	}
}

func TestDrillPayloadValidation(t *testing.T) {
	h := newHarness(t)
	b := newBucket(t)
	base := drillPayload(b, BackupResult{SHA256: strings.Repeat("a", 64)})
	for name, mod := range map[string]func(*DrillPayload){
		"bad id":       func(p *DrillPayload) { p.Drill = "x" },
		"no digest":    func(p *DrillPayload) { p.Instance.Digest = "" },
		"bad database": func(p *DrillPayload) { p.Database = "a;b" },
		"http source":  func(p *DrillPayload) { p.Source.URL = "http://x" },
		"kv query":     func(p *DrillPayload) { p.Instance.Engine = "redis" },
		"no key":       func(p *DrillPayload) { p.Encryption = backupcrypt.Encryption{} },
		"tolerance":    func(p *DrillPayload) { p.Checks.TolerancePercent = 150 },
	} {
		p := base
		mod(&p)
		if _, err := h.db.Drill(context.Background(), p, stream()); !commands.IsPayloadError(err) {
			t.Errorf("%s: %v", name, err)
		}
	}
}

func TestCompareLargest(t *testing.T) {
	want := map[string]int64{"a": 1000, "b": 10, "c": 0}
	if ok, _ := compareLargest(map[string]int64{"a": 1100, "b": 11, "c": 0}, want, 10, 10); !ok {
		t.Error("within 10% refused")
	}
	if ok, _ := compareLargest(map[string]int64{"a": 1101, "b": 10, "c": 0}, want, 10, 10); ok {
		t.Error("over 10% accepted")
	}
	// Only the n largest are compared.
	if ok, _ := compareLargest(map[string]int64{"a": 1000}, want, 10, 1); !ok {
		t.Error("a small table outside the top 1 was compared")
	}
	if within(1, 0, 10) || !within(0, 0, 10) {
		t.Error("an empty table may not grow")
	}
}
