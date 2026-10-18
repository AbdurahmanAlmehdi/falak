package db

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// Location is a backup destination / restore source.
type Location struct {
	Kind    string            `json:"kind"` // local | presigned_url | url
	Path    string            `json:"path"`
	URL     string            `json:"url"`
	Headers map[string]string `json:"headers"`
}

// BackupPayload is db.backup.
type BackupPayload struct {
	Instance    string                 `json:"instance"`
	Engine      string                 `json:"engine"`
	Database    string                 `json:"database"`
	Encryption  backupcrypt.Encryption `json:"encryption"`
	Destination Location               `json:"destination"`
	// TableCounts: record the row count of every table (Redis / Valkey: the key count) before the dump, for restore
	// drills to compare with.
	TableCounts bool `json:"table_counts,omitempty"`
}

// Secrets are the payload's secret values (masked in output).
func (p BackupPayload) Secrets() []string { return p.Encryption.Secrets() }

// BackupResult is its result.
type BackupResult struct {
	// SizeBytes and SHA256 are the stored (encrypted) file's.
	SizeBytes  int64  `json:"size_bytes"`
	SHA256     string `json:"sha256"`
	Location   string `json:"location"`
	DurationMS int64  `json:"duration_ms"`
	// RDB is a Redis / Valkey snapshot's format, e.g. REDIS0011 or VALKEY080.
	RDB string `json:"rdb,omitempty"`
	// UncompressedBytes and PlaintextSHA256 are the dump's (authenticated in the file's trailer).
	UncompressedBytes int64  `json:"uncompressed_bytes,omitempty"`
	PlaintextSHA256   string `json:"plaintext_sha256"`
	Encryption        string `json:"encryption"`
	KeyID             string `json:"key_id"`
	Cipher            string `json:"cipher"`
	Compression       string `json:"compression"`
	// TableCounts are the row counts taken just before the dump (close to what it holds).
	TableCounts map[string]int64 `json:"table_counts,omitempty"`
}

// Backup streams `falak-db backup logical` out of the instance's container (pg_dump -Fc, mysqldump, an RDB
// snapshot) through zstd and the backup cipher (FKB1, internal/backupcrypt), and stores it locally or PUTs it to a
// presigned URL.
func (db *DB) Backup(ctx context.Context, p BackupPayload, st commands.Stream) (any, error) {
	start := time.Now()
	if err := checkID("instance", p.Instance); err != nil {
		return nil, err
	}
	defer db.lock(p.Instance)()
	if err := checkEngine(p.Engine); err != nil {
		return nil, err
	}
	args := []string{"backup", "logical", "--out", "-"}
	if !isKeyValue(p.Engine) {
		if err := checkIdent("database", p.Database); err != nil {
			return nil, err
		}
		args = []string{"backup", "logical", "--database", p.Database, "--out", "-"}
	}
	if err := checkDestination(p.Destination); err != nil {
		return nil, err
	}
	if err := p.Encryption.Check(true); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	var counts map[string]int64
	if p.TableCounts {
		// Best effort: a drill without counts still checks that the backup restores.
		var err error
		if counts, err = db.containerCounts(ctx, Container(p.Instance), p.Engine, p.Database); err != nil {
			fmt.Fprintf(st.Stderr(), "row counts of %s: %v\n", p.Database, err)
		}
	}
	var head headWriter
	res, err := db.ship(ctx, p, start, st, func(out io.Writer) error {
		_, stderr, err := db.exec(ctx, p.Instance, nil, io.MultiWriter(out, &head), nil, args...)
		if err != nil {
			return fmt.Errorf("dump %s: %w", p.Database, err)
		}
		var r struct {
			Bytes int64 `json:"bytes"`
		}
		if err := streamResult(stderr, &r); err != nil {
			return fmt.Errorf("dump %s: %w", p.Database, err)
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	if isKeyValue(p.Engine) {
		res.RDB = head.rdb()
	}
	res.TableCounts = counts
	return res, nil
}

// Dump streams a database's unencrypted logical dump (`falak-db backup logical`, as db.backup: pg_dump -Fc for
// postgres, SQL for mysql/mariadb) into w, under the instance's lock: sandbox.db.import, which pipes it into a sandbox
// so it never touches the host's disk. Redis and Valkey have no per-database dump.
func (db *DB) Dump(ctx context.Context, instance, engine, database string, w io.Writer) error {
	if err := checkID("instance", instance); err != nil {
		return err
	}
	if err := checkEngine(engine); err != nil {
		return err
	}
	if isKeyValue(engine) {
		return payloadErr("engine %s has no database dump", engine)
	}
	if err := checkIdent("database", database); err != nil {
		return err
	}
	defer db.lock(instance)()
	_, stderr, err := db.exec(ctx, instance, nil, w, nil, "backup", "logical", "--database", database, "--out", "-")
	if err == nil {
		var r struct {
			Bytes int64 `json:"bytes"`
		}
		err = streamResult(stderr, &r)
	}
	if err != nil {
		return fmt.Errorf("dump %s: %w", database, err)
	}
	return nil
}

// containerCounts runs `falak-db table-counts` in a container: the row count of every table of the database (Redis /
// Valkey: the keys of every logical database).
func (db *DB) containerCounts(ctx context.Context, container, engine, database string) (map[string]int64, error) {
	args := []string{"table-counts", "--database", database}
	if isKeyValue(engine) {
		args = []string{"table-counts"}
	}
	out, _, err := db.execIn(ctx, container, nil, nil, nil, args...)
	if err != nil {
		return nil, err
	}
	var r struct {
		Tables map[string]int64 `json:"tables"`
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(out)), &r); err != nil {
		return nil, fmt.Errorf("table-counts: %w", err)
	}
	if r.Tables == nil {
		r.Tables = map[string]int64{}
	}
	return r.Tables, nil
}

// headWriter keeps the first bytes of a stream (an RDB file's header: REDIS0011, VALKEY080).
type headWriter struct{ b []byte }

func (h *headWriter) Write(p []byte) (int, error) {
	if n := 16 - len(h.b); n > 0 {
		h.b = append(h.b, p[:min(n, len(p))]...)
	}
	return len(p), nil
}

func (h *headWriter) rdb() string {
	s := string(h.b)
	for _, prefix := range []string{"REDIS", "VALKEY"} {
		if strings.HasPrefix(s, prefix) {
			n := len(prefix)
			for n < len(s) && n < len(prefix)+4 && s[n] >= '0' && s[n] <= '9' {
				n++
			}
			return s[:n]
		}
	}
	return ""
}

func checkDestination(d Location) error {
	switch d.Kind {
	case "local":
		if !strings.HasPrefix(d.Path, "/") {
			return &commands.PayloadError{Err: errors.New("destination.path must be absolute")}
		}
	case "presigned_url":
		if !strings.HasPrefix(d.URL, "https://") {
			return &commands.PayloadError{Err: errors.New("destination.url must be https")}
		}
	default:
		return &commands.PayloadError{Err: fmt.Errorf("unknown destination kind %q", d.Kind)}
	}
	return nil
}

// ship runs write into a staging file through zstd and the backup cipher (the file hashed as stored), then moves it
// to the local destination or PUTs it to the presigned URL.
func (db *DB) ship(ctx context.Context, p BackupPayload, start time.Time, st commands.Stream, write func(io.Writer) error) (BackupResult, error) {
	var file string
	if p.Destination.Kind == "local" {
		if err := db.d.FS.MkdirAll(filepath.Dir(p.Destination.Path), 0o700); err != nil {
			return BackupResult{}, err
		}
		file = db.d.FS.P(p.Destination.Path) + ".partial"
	} else {
		if err := os.MkdirAll(db.d.TempDir, 0o700); err != nil {
			return BackupResult{}, err
		}
		f, err := os.CreateTemp(db.d.TempDir, "falak-backup-*")
		if err != nil {
			return BackupResult{}, err
		}
		f.Close()
		file = f.Name()
	}
	defer os.Remove(file)

	f, err := os.OpenFile(file, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return BackupResult{}, err
	}
	h := sha256.New()
	sealed, err := p.Encryption.Seal(io.MultiWriter(f, h))
	if err != nil {
		f.Close()
		return BackupResult{}, err
	}
	err = write(sealed)
	if cerr := sealed.Close(); err == nil {
		err = cerr
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return BackupResult{}, err
	}
	fi, err := os.Stat(file)
	if err != nil {
		return BackupResult{}, err
	}
	sum := sealed.Summary()
	result := BackupResult{SizeBytes: fi.Size(), SHA256: hex.EncodeToString(h.Sum(nil)), UncompressedBytes: sum.Bytes,
		PlaintextSHA256: hex.EncodeToString(sum.SHA256[:]), Encryption: p.Encryption.Mode, KeyID: p.Encryption.KeyID,
		Cipher: "aes-256-gcm", Compression: "zstd"}
	if p.Destination.Kind == "local" {
		if err := os.Rename(file, db.d.FS.P(p.Destination.Path)); err != nil {
			return BackupResult{}, err
		}
		result.Location = p.Destination.Path
	} else {
		if err := db.put(ctx, file, fi.Size(), p.Destination); err != nil {
			return BackupResult{}, err
		}
		u, _ := url.Parse(p.Destination.URL)
		u.RawQuery = "" // never echo the signature back
		result.Location = u.String()
	}
	fmt.Fprintf(st.Stdout(), "backup of %s: %d bytes encrypted (%d before compression), sha256 %s\n", p.Database, result.SizeBytes, result.UncompressedBytes, result.SHA256)
	result.DurationMS = time.Since(start).Milliseconds()
	return result, nil
}

func (db *DB) put(ctx context.Context, file string, size int64, d Location) error {
	f, err := os.Open(file)
	if err != nil {
		return err
	}
	defer f.Close()
	req, err := http.NewRequestWithContext(ctx, http.MethodPut, d.URL, f)
	if err != nil {
		return err
	}
	req.ContentLength = size
	req.Header.Set("Content-Type", "application/octet-stream")
	for k, v := range d.Headers {
		req.Header.Set(k, v)
	}
	resp, err := db.d.HTTP.Do(req)
	if err != nil {
		return fmt.Errorf("upload backup: %w", redact(err))
	}
	defer resp.Body.Close()
	if resp.StatusCode/100 != 2 {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return fmt.Errorf("upload backup: %s: %s", resp.Status, b)
	}
	return nil
}

// redact strips the query string (signature) from url errors.
func redact(err error) error {
	var ue *url.Error
	if errors.As(err, &ue) {
		if u, perr := url.Parse(ue.URL); perr == nil {
			u.RawQuery = ""
			ue.URL = u.String()
		}
	}
	return err
}

// RestorePayload is db.restore.
type RestorePayload struct {
	Instance   string                 `json:"instance"`
	Engine     string                 `json:"engine"`
	Database   string                 `json:"database"`
	Encryption backupcrypt.Encryption `json:"encryption"`
	Source     Location               `json:"source"`
	// SHA256 is the stored file's (checked before anything is restored); PlaintextSHA256 the dump's (checked against
	// the file's authenticated trailer).
	SHA256          string `json:"sha256"`
	PlaintextSHA256 string `json:"plaintext_sha256,omitempty"`
	// ArchiveBytes is the stored file's size: the staging directory must have room for it.
	ArchiveBytes int64 `json:"archive_bytes,omitempty"`
	// Owner (postgres) gets the restored database and every object in it: the application keeps migrating it.
	Owner string `json:"owner,omitempty"`
}

// Secrets are the payload's secret values (masked in output).
func (p RestorePayload) Secrets() []string { return p.Encryption.Secrets() }

// RestoreResult is its result.
type RestoreResult struct {
	Bytes      int64    `json:"bytes"`
	DurationMS int64    `json:"duration_ms"`
	Warnings   []string `json:"warnings,omitempty"`
}

type countingWriter struct {
	w io.Writer
	n int64
}

func (c *countingWriter) Write(p []byte) (int, error) {
	n, err := c.w.Write(p)
	c.n += int64(n)
	return n, err
}

// Restore loads a backup into a database of the instance (created if missing; postgres objects are dropped first).
// Redis / Valkey: the container is stopped, a one-off container of the same image replaces the data directory's
// snapshot, and the instance starts again.
func (db *DB) Restore(ctx context.Context, p RestorePayload, st commands.Stream) (any, error) {
	start := time.Now()
	if err := checkID("instance", p.Instance); err != nil {
		return nil, err
	}
	defer db.lock(p.Instance)()
	if err := checkEngine(p.Engine); err != nil {
		return nil, err
	}
	if !isKeyValue(p.Engine) {
		if err := checkIdent("database", p.Database); err != nil {
			return nil, err
		}
	}
	if err := p.Encryption.Check(false); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	if !sha256Re.MatchString(p.SHA256) {
		return nil, payloadErr("sha256 (of the stored file) is required")
	}
	if p.ArchiveBytes < 0 {
		return nil, payloadErr("archive_bytes must be positive")
	}
	if err := db.stagingRoom(p.ArchiveBytes); err != nil {
		return nil, err
	}
	if p.Engine == "postgres" && p.Owner != "" {
		if err := checkIdent("owner", p.Owner); err != nil {
			return nil, err
		}
	}
	file, cleanup, err := db.fetchSource(ctx, p.Source, p.SHA256)
	if err != nil {
		return nil, err
	}
	defer cleanup()
	n, err := db.restoreInto(ctx, Container(p.Instance), p.Engine, p.Database, p.Owner, true, file, p.Encryption, p.PlaintextSHA256, st)
	if err != nil {
		return nil, err
	}
	return RestoreResult{Bytes: n, DurationMS: time.Since(start).Milliseconds()}, nil
}

// restoreInto opens a backup file and loads it into a container: a SQL database (created first on MySQL / MariaDB;
// postgres with swap: into a scratch database renamed in on success), or a Redis / Valkey container's snapshot. It
// returns the dump's size.
//
// The whole file is authenticated first (every segment, the trailer, the recorded SHA-256), decrypting to nothing: an
// engine is only ever fed a backup known to be complete and intact. While it streams, a read error (the disk) cancels
// the exec, so falak-db is killed rather than seeing an early end of input it could take for a complete dump.
func (db *DB) restoreInto(ctx context.Context, container, engine, database, owner string, swap bool, file string, enc backupcrypt.Encryption, want string, st commands.Stream) (int64, error) {
	if err := verifyDump(file, enc, want); err != nil {
		return 0, err
	}
	fmt.Fprintf(st.Stdout(), "backup verified (every segment and its SHA-256)\n")
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	in, err := openDump(file, enc, want)
	if err != nil {
		return 0, err
	}
	in.cancel = cancel
	defer in.Close()
	if isKeyValue(engine) {
		return in.n, in.check(db.restoreKeyValue(ctx, container, in, st))
	}
	args := []string{"restore", "logical", "--database", database, "--in", "-"}
	if engine == "postgres" {
		if swap {
			// Into a scratch database, swapped in only once complete: a failed restore leaves the database as it was.
			args = append(args, "--swap")
		}
		if owner != "" {
			args = append(args, "--owner", owner)
		}
	}
	if engine != "postgres" || !swap {
		if _, _, err := db.execIn(ctx, container, nil, nil, nil, "database", "create", "--name", database); err != nil {
			return 0, err
		}
	}
	_, _, err = db.execIn(ctx, container, in, st.Stdout(), nil, args...)
	if err := in.check(err); err != nil {
		return 0, fmt.Errorf("restore into %s: %w", database, err)
	}
	return in.n, nil
}

// restoreKeyValue replaces a stopped Redis / Valkey container's snapshot through a one-off container with the same
// image and data mount (`falak-db restore logical` refuses a running server), then starts it again.
func (db *DB) restoreKeyValue(ctx context.Context, name string, in io.Reader, st commands.Stream) error {
	cur, ok, err := db.d.Docker.ContainerInspect(ctx, name)
	if err != nil {
		return err
	}
	if !ok {
		return fmt.Errorf("%s does not exist", name)
	}
	var data string
	for _, m := range cur.Mounts {
		if m.Destination == "/data" {
			data = m.Source
		}
	}
	if data == "" {
		return fmt.Errorf("%s has no /data mount", name)
	}
	if _, err := db.d.Docker.ContainerStop(ctx, cur.ID, stopGrace); err != nil {
		return err
	}
	fmt.Fprintf(st.Stdout(), "stopped %s\n", name)
	var errBuf bytes.Buffer
	args := []string{"run", "--rm", "-i", "--name", name + "-restore", "--network", "none", "--entrypoint", "falak-db"}
	if docker.Local.WorkloadSlice != "" {
		args = append(args, "--cgroup-parent", docker.Local.WorkloadSlice)
	}
	res, runErr := db.d.Runner.Run(ctx, runner.Cmd{Name: "docker", Args: append(args,
		"--mount", "type=bind,source="+data+",target=/data", cur.Image, "restore", "logical", "--in", "-",
	), Stdin: in, Stdout: st.Stdout(), Stderr: &errBuf})
	if runErr == nil && res.ExitCode != 0 {
		runErr = fmt.Errorf("falak-db restore logical: exit %d: %s", res.ExitCode, lastLines(errBuf.String(), 5))
	}
	// The instance runs again whatever happened (falak-db only replaces the snapshot once it is complete).
	if err := db.d.Docker.ContainerStart(ctx, cur.ID); err != nil {
		return errors.Join(runErr, fmt.Errorf("starting %s: %w", name, err))
	}
	if runErr != nil {
		return runErr
	}
	_, err = db.awaitHealthy(ctx, name)
	return err
}

var sha256Re = regexp.MustCompile(`^[a-f0-9]{64}$`)

// stagingRoom fails when the staging directory (on the volume store) has less than need bytes (+512 MiB) free.
func (db *DB) stagingRoom(need int64) error {
	if err := os.MkdirAll(db.d.TempDir, 0o700); err != nil {
		return err
	}
	if need <= 0 {
		return nil
	}
	free, err := db.d.FreeBytes(db.d.TempDir)
	if err != nil {
		return fmt.Errorf("free space of %s: %w", db.d.TempDir, err)
	}
	if free < need+drillDiskMargin {
		return fmt.Errorf("%s has %d MiB free, the backup needs %d MiB", db.d.TempDir, free>>20, (need+drillDiskMargin)>>20)
	}
	return nil
}

// fetchSource gives the backup file locally: a local source checked against sha256, or a URL downloaded (and checked)
// into a temporary file that cleanup removes.
func (db *DB) fetchSource(ctx context.Context, src Location, sha string) (file string, cleanup func(), err error) {
	cleanup = func() {}
	switch src.Kind {
	case "local":
		file = db.d.FS.P(src.Path)
		if sha != "" {
			sum, err := system.FileSHA256(file)
			if err != nil {
				return "", cleanup, err
			}
			if !strings.EqualFold(sum, sha) {
				return "", cleanup, fmt.Errorf("sha256 mismatch: got %s, want %s", sum, sha)
			}
		}
		return file, cleanup, nil
	case "url":
		tmp, err := os.CreateTemp(db.d.TempDir, "falak-restore-*")
		if err != nil {
			return "", cleanup, err
		}
		tmp.Close()
		cleanup = func() { os.Remove(tmp.Name()) }
		if _, _, err := system.Download(ctx, db.d.HTTP, src.URL, sha, tmp.Name(), 0o600, src.Headers); err != nil {
			cleanup()
			return "", func() {}, redact(err)
		}
		return tmp.Name(), cleanup, nil
	}
	return "", cleanup, &commands.PayloadError{Err: fmt.Errorf("unknown source kind %q", src.Kind)}
}

// dumpReader is a backup being opened: it decrypts and decompresses, counts, and keeps the first error it met, so a
// consumer that stops at a read error (it only sees the end of its input) still fails.
type dumpReader struct {
	f      *os.File
	r      *backupcrypt.Reader
	want   string
	n      int64
	err    error
	closed bool
	cancel context.CancelFunc
}

// verifyDump authenticates a whole backup file without keeping its content.
func verifyDump(file string, enc backupcrypt.Encryption, want string) error {
	f, err := os.Open(file)
	if err != nil {
		return err
	}
	defer f.Close()
	if _, err := enc.Verify(f, want); err != nil {
		return fmt.Errorf("backup verification: %w", err)
	}
	return nil
}

// openDump opens a backup file (FKB1) with its key. want, when set, is the dump's expected SHA-256.
func openDump(file string, enc backupcrypt.Encryption, want string) (*dumpReader, error) {
	f, err := os.Open(file)
	if err != nil {
		return nil, err
	}
	r, err := enc.Open(f)
	if err != nil {
		f.Close()
		return nil, fmt.Errorf("open backup: %w", err)
	}
	return &dumpReader{f: f, r: r, want: strings.ToLower(want)}, nil
}

func (d *dumpReader) Read(p []byte) (int, error) {
	n, err := d.r.Read(p)
	d.n += int64(n)
	if errors.Is(err, io.EOF) && d.want != "" {
		if got := d.r.Summary().SHA256; hex.EncodeToString(got[:]) != d.want {
			err = fmt.Errorf("the backup's content is not the one recorded (sha256 %x, want %s)", got, d.want)
		}
	}
	if err != nil && !errors.Is(err, io.EOF) && d.err == nil {
		d.err = err
		if d.cancel != nil {
			d.cancel() // kill the consumer before it sees the end of its input
		}
	}
	return n, err
}

// check combines a consumer's error with the stream's: what failed to decrypt or verify always fails the restore.
func (d *dumpReader) check(err error) error {
	if d.err != nil {
		return fmt.Errorf("backup: %w", d.err)
	}
	return err
}

func (d *dumpReader) Close() error {
	if d.closed {
		return nil
	}
	d.closed = true
	d.r.Close()
	return d.f.Close()
}
