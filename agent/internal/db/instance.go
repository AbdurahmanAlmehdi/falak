package db

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
)

// stopGrace is how long an engine gets to shut down cleanly.
const stopGrace = 60 * time.Second

// InstancePayload is db.instance.create / db.instance.update.
type InstancePayload struct {
	Instance     InstanceSpec `json:"instance"`
	Password     string       `json:"password"`
	RegistryAuth *docker.Auth `json:"registry_auth,omitempty"`
	// ApplyQueryInsights (db.instance.update, feature db.observability) is the user's "enable query insights" action:
	// only then does a query_insights change restart a running engine. Any other update (network, certificate, PITR)
	// writes the file and leaves the change to the next restart the user causes.
	ApplyQueryInsights bool `json:"apply_query_insights,omitempty"`
}

// Secrets are the payload's secret values (masked in output).
func (p InstancePayload) Secrets() []string {
	out := []string{p.Password}
	if p.Instance.TLS != nil {
		out = append(out, p.Instance.TLS.PrivateKey)
	}
	if p.RegistryAuth != nil {
		out = append(out, p.RegistryAuth.Password)
	}
	return out
}

// InstanceResult is their result.
type InstanceResult struct {
	Changed     bool   `json:"changed"`
	ContainerID string `json:"container_id"`
	ImageDigest string `json:"image_digest,omitempty"`
	Health      string `json:"health"`
	// Restarted: settings, the certificate or the limits changed in place and the engine restarted to use them.
	Restarted bool `json:"restarted,omitempty"`
}

// InstanceCreate pulls the image, creates the container and starts it, then waits until it is healthy.
func (db *DB) InstanceCreate(ctx context.Context, p InstancePayload, st commands.Stream) (any, error) {
	return db.apply(ctx, p, st)
}

// InstanceUpdate converges an instance to the spec. Settings, a new certificate and the limits change in place (the
// limits with `docker update`, then a restart so falak-db renders the config again); the container is only recreated,
// on the same volume, when its image, ports, network or mounts change.
func (db *DB) InstanceUpdate(ctx context.Context, p InstancePayload, st commands.Stream) (any, error) {
	return db.apply(ctx, p, st)
}

func (db *DB) apply(ctx context.Context, p InstancePayload, st commands.Stream) (any, error) {
	s := p.Instance
	if err := s.validate(); err != nil {
		return nil, err
	}
	if p.Password == "" {
		return nil, payloadErr("password is required")
	}
	defer db.lock(s.ID)()
	name := Container(s.ID)
	if err := db.awaitVolume(ctx, s.VolumeID, st); err != nil {
		return nil, err
	}
	vol := db.d.FS.P(db.volumeDir(s.VolumeID))
	if err := os.MkdirAll(filepath.Join(vol, "data"), 0o700); err != nil {
		return nil, err
	}
	if err := ensureRootSpool(filepath.Join(vol, "spool")); err != nil {
		return nil, err
	}
	digest, err := db.ensureImage(ctx, s, p.RegistryAuth, st)
	if err != nil {
		return nil, err
	}
	dir, _, err := db.ensureSecretsDir(s.ID)
	if err != nil {
		return nil, err
	}
	// The current password file stays as it is on an update (db.instance.password rotates it).
	if _, err := os.Stat(filepath.Join(dir, "password")); err != nil {
		if err := writeSecret(dir, "password", []byte(p.Password)); err != nil {
			return nil, fmt.Errorf("password file: %w", err)
		}
	}
	tlsChanged, tls, err := db.writeTLS(s)
	if err != nil {
		return nil, err
	}
	prevSettings, settingsChanged, err := db.writeSettings(s, tls)
	if err != nil {
		return nil, err
	}
	qiChanged, err := db.writeQueryInsights(s)
	if err != nil {
		return nil, err
	}
	if err := db.writePITR(s); err != nil {
		return nil, fmt.Errorf("pitr: %w", err)
	}
	if s.Network != "" {
		if err := db.ensureNetwork(ctx, s.Network); err != nil {
			return nil, err
		}
	}
	if err := db.applyFirewall(ctx, s); err != nil {
		return nil, fmt.Errorf("firewall: %w", err)
	}

	cur, exists, err := db.d.Docker.ContainerInspect(ctx, name)
	if err != nil {
		return nil, err
	}
	if exists && cur.Image != "" {
		if imageID, ok, err := db.d.Docker.ImageInspect(ctx, s.ref()); err == nil && ok && imageID != cur.Image {
			if err := db.recreate(ctx, cur, name, "its image changed", st); err != nil {
				return nil, err
			}
			exists = false
		}
	}
	if exists && cur.Config.Labels[docker.LabelSpecHash] == s.hash() {
		res := InstanceResult{ContainerID: cur.ID, ImageDigest: digest}
		limits := cur.HostConfig.Memory != s.MemoryBytes || cur.HostConfig.NanoCpus != int64(s.CPUs*1e9)
		if limits {
			if err := db.d.Docker.ContainerUpdate(ctx, cur.ID, s.MemoryBytes, int64(s.CPUs*1e9)); err != nil {
				return nil, fmt.Errorf("new limits for %s: %w", name, err)
			}
		}
		qiApply := p.ApplyQueryInsights && (qiChanged || db.queryInsightsPending(s.ID, cur))
		switch {
		case cur.State.Running && settingsChanged && !limits && !tlsChanged && !qiApply && !isKeyValue(s.Engine) &&
			onlySlowQueryChanged(prevSettings, s.settingsJSON(tls)) && db.reload(ctx, s.ID, st):
			// The slow query threshold applies without a restart (falak-db reload); an older image restarts instead.
			res.Changed = true
		case cur.State.Running && (limits || settingsChanged || tlsChanged || qiApply):
			// falak-db renders the config (tuned to the memory limit) and installs the certificate at every start.
			fmt.Fprintf(st.Stdout(), "restarting %s for its new settings\n", name)
			if err := db.d.Docker.ContainerRestart(ctx, cur.ID, stopGrace); err != nil {
				return nil, err
			}
			res.Changed, res.Restarted = true, true
		case !cur.State.Running:
			if err := db.d.Docker.ContainerStart(ctx, cur.ID); err != nil {
				return nil, err
			}
			res.Changed = true
		}
		res.Health, err = db.awaitHealthy(ctx, name)
		return res, err
	}
	if exists {
		if err := db.recreate(ctx, cur, name, "its ports, network or mounts changed", st); err != nil {
			return nil, err
		}
	}
	id, err := db.d.Docker.ContainerCreate(ctx, name, db.createBody(s))
	if err != nil {
		return nil, err
	}
	if err := db.d.Docker.ContainerStart(ctx, id); err != nil {
		return nil, err
	}
	fmt.Fprintf(st.Stdout(), "started %s (%s %s)\n", name, s.Engine, s.Version)
	health, err := db.awaitHealthy(ctx, name)
	return InstanceResult{Changed: true, ContainerID: id, ImageDigest: digest, Health: health}, err
}

// recreate stops (60 s to shut down cleanly) and removes the container; the data stays on the volume.
func (db *DB) recreate(ctx context.Context, cur *docker.Container, name, why string, st commands.Stream) error {
	fmt.Fprintf(st.Stdout(), "recreating %s (%s)\n", name, why)
	if _, err := db.d.Docker.ContainerStop(ctx, cur.ID, stopGrace); err != nil && !docker.IsNotFound(err) {
		return err
	}
	return db.d.Docker.ContainerRemove(ctx, cur.ID)
}

// writeSettings writes the settings file falak-db renders the config from at every start (TLS off when no certificate
// is installed: falak-db refuses TLS without one) and reports whether it changed, with the previous content.
func (db *DB) writeSettings(s InstanceSpec, tls bool) (string, bool, error) {
	dir := db.d.FS.P(db.confDir(s.ID))
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", false, err
	}
	content := []byte(s.settingsJSON(tls))
	p := filepath.Join(dir, "settings.json")
	cur, err := os.ReadFile(p)
	if err == nil && bytes.Equal(cur, content) {
		return string(cur), false, nil
	}
	if err := os.WriteFile(p+".tmp", content, 0o644); err != nil {
		return "", false, err
	}
	return string(cur), true, os.Rename(p+".tmp", p)
}

// queryInsightsFile is read by falak-db v0.16.0+ next to the settings file (dbhelper.queryInsightsFile); older images
// never look at it.
const queryInsightsFile = "query-insights.json"

// writeQueryInsights writes (or removes) the query insights file and reports whether that changed anything.
func (db *DB) writeQueryInsights(s InstanceSpec) (bool, error) {
	p := filepath.Join(db.d.FS.P(db.confDir(s.ID)), queryInsightsFile)
	_, err := os.Stat(p)
	exists := err == nil
	if !s.QueryInsights {
		if !exists {
			return false, nil
		}
		return true, os.Remove(p)
	}
	content := []byte(`{"enabled":true}` + "\n")
	if cur, err := os.ReadFile(p); err == nil && bytes.Equal(cur, content) {
		return false, nil
	}
	if err := os.WriteFile(p+".tmp", content, 0o644); err != nil {
		return false, err
	}
	return true, os.Rename(p+".tmp", p)
}

// queryInsightsPending reports whether the query insights file was written after the running engine started (an
// earlier update deferred it to the next restart): the user's enable action then still restarts it.
func (db *DB) queryInsightsPending(id string, cur *docker.Container) bool {
	st, err := os.Stat(filepath.Join(db.d.FS.P(db.confDir(id)), queryInsightsFile))
	if err != nil {
		return false
	}
	started, err := time.Parse(time.RFC3339Nano, cur.State.StartedAt)
	return err != nil || st.ModTime().After(started)
}

// onlySlowQueryChanged reports whether two settings documents differ in slow_query_ms alone.
func onlySlowQueryChanged(prev, next string) bool {
	var a, b map[string]any
	if json.Unmarshal([]byte(prev), &a) != nil || json.Unmarshal([]byte(next), &b) != nil {
		return false
	}
	delete(a, "slow_query_ms")
	delete(b, "slow_query_ms")
	pa, _ := json.Marshal(a)
	pb, _ := json.Marshal(b)
	return bytes.Equal(pa, pb)
}

// reload applies a new slow query threshold to the running engine (`falak-db reload`); false when it could not (an
// older image without the command, or a failure): the caller restarts instead.
func (db *DB) reload(ctx context.Context, id string, st commands.Stream) bool {
	if _, _, err := db.exec(ctx, id, nil, nil, nil, "reload"); err != nil {
		fmt.Fprintf(st.Stdout(), "reload failed (%v): restarting instead\n", err)
		return false
	}
	fmt.Fprintf(st.Stdout(), "reloaded %s: new slow query threshold, no restart\n", Container(id))
	return true
}

// awaitVolume waits for the instance's sized volume to be mounted (volume.create runs concurrently with the first
// db.instance.create).
func (db *DB) awaitVolume(ctx context.Context, volumeID string, st commands.Stream) error {
	path := db.d.FS.P(db.volumeDir(volumeID))
	deadline := time.Now().Add(db.d.VolumeWait)
	for said := false; !db.d.Mounted(path); said = true {
		if time.Now().After(deadline) {
			return fmt.Errorf("volume %s is not mounted at %s", volumeID, db.volumeDir(volumeID))
		}
		if !said {
			fmt.Fprintf(st.Stdout(), "waiting for volume %s\n", volumeID)
		}
		if err := sleep(ctx, db.d.Poll); err != nil {
			return err
		}
	}
	return nil
}

// ensureImage pulls the instance's image by its pinned digest (the control plane ships the digests CI published;
// validate refuses a spec without one) and checks it against the image's RepoDigests. A tag is never trusted as pulled.
//
// The cosign signature (keyless, GitHub OIDC; docs/DB_IMAGES.md) is not verified on the server: the digest list comes
// from the release the control plane runs, built from the signed images.
func (db *DB) ensureImage(ctx context.Context, s InstanceSpec, auth *docker.Auth, st commands.Stream) (string, error) {
	ref := s.ref()
	digests, ok, err := db.d.Docker.ImageRepoDigests(ctx, ref)
	if err != nil {
		return "", err
	}
	if !ok {
		fmt.Fprintf(st.Stdout(), "pulling %s\n", ref)
		if err := db.d.Docker.ImagePull(ctx, ref, auth, st.Stdout()); err != nil {
			return "", fmt.Errorf("pull %s: %w", ref, err)
		}
		if digests, ok, err = db.d.Docker.ImageRepoDigests(ctx, ref); err != nil || !ok {
			return "", fmt.Errorf("image %s missing after the pull: %v", ref, err)
		}
	}
	if !slices.Contains(digests, repository(s.Image)+"@"+s.Digest) {
		return "", fmt.Errorf("image %s does not carry the pinned digest %s", ref, s.Digest)
	}
	return s.Digest, nil
}

// writeTLS installs the instance's certificate (/etc/falak/db/<id>/tls, root only, mounted read-only) when the payload
// has one. It reports whether the installed files changed and whether a certificate is installed (TLS on).
func (db *DB) writeTLS(s InstanceSpec) (changed, installed bool, err error) {
	dir := db.d.FS.P(db.tlsDir(s.ID))
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return false, false, err
	}
	before := tlsFingerprint(dir)
	if s.TLS != nil {
		for name, content := range map[string]string{"server.crt": s.TLS.Certificate, "server.key": s.TLS.PrivateKey, "ca.crt": s.TLS.CA} {
			p := filepath.Join(dir, name)
			if content == "" {
				_ = os.Remove(p)
				continue
			}
			if err := os.WriteFile(p+".tmp", []byte(content), 0o600); err != nil {
				return false, false, err
			}
			if err := os.Rename(p+".tmp", p); err != nil {
				return false, false, err
			}
		}
	}
	after := tlsFingerprint(dir)
	return before != after, after != "", nil
}

// tlsFingerprint hashes the installed certificate files ("" without a certificate and key).
func tlsFingerprint(dir string) string {
	h := sha256.New()
	for _, name := range []string{"server.crt", "server.key", "ca.crt"} {
		b, err := os.ReadFile(filepath.Join(dir, name))
		if err != nil && name != "ca.crt" {
			return ""
		}
		h.Write([]byte(name + "\x00"))
		h.Write(b)
	}
	return hex.EncodeToString(h.Sum(nil))
}

// ensureNetwork creates an environment's network when missing.
func (db *DB) ensureNetwork(ctx context.Context, name string) error {
	ok, err := db.d.Docker.NetworkExists(ctx, name)
	if err != nil || ok {
		return err
	}
	return db.d.Docker.NetworkCreate(ctx, name, map[string]string{docker.LabelManaged: "true", "falak.network": "environment"})
}

// awaitHealthy waits for the container's healthcheck; a container that stops (or stays unhealthy) fails with the end
// of its log.
func (db *DB) awaitHealthy(ctx context.Context, name string) (string, error) {
	deadline := time.Now().Add(db.d.HealthWait)
	for {
		c, ok, err := db.d.Docker.ContainerInspect(ctx, name)
		if err != nil {
			return "", err
		}
		if !ok {
			return "", fmt.Errorf("%s is gone", name)
		}
		health := healthOf(c)
		switch {
		case health == "healthy" || health == "none" && c.State.Running:
			return health, nil
		case !c.State.Running && c.State.Status != "restarting":
			return health, fmt.Errorf("%s stopped (exit %d): %s", name, c.State.ExitCode, db.logTail(ctx, name))
		case time.Now().After(deadline):
			return health, fmt.Errorf("%s is not healthy after %s (%s): %s", name, db.d.HealthWait, health, db.logTail(ctx, name))
		}
		if err := sleep(ctx, db.d.Poll); err != nil {
			return health, err
		}
	}
}

func healthOf(c *docker.Container) string {
	if c.State.Health == nil || c.State.Health.Status == "" {
		return "none"
	}
	return c.State.Health.Status
}

// logTail is the end of a container's log (for errors; the engines never log passwords).
func (db *DB) logTail(ctx context.Context, name string) string {
	var b strings.Builder
	_ = db.d.Docker.ContainerLogs(ctx, name, false, 20, &b)
	return lastLines(b.String(), 8)
}

// ---- restart / stop / delete ----

// ---- restart / stop / delete ----

// IDPayload names an instance.
type IDPayload struct {
	ID string `json:"id"`
}

// ChangedResult is {changed}.
type ChangedResult struct {
	Changed bool   `json:"changed"`
	Health  string `json:"health,omitempty"`
}

// InstanceRestart restarts the container (60 s to shut down) and waits until it is healthy.
func (db *DB) InstanceRestart(ctx context.Context, p IDPayload, _ commands.Stream) (any, error) {
	if err := checkID("id", p.ID); err != nil {
		return nil, err
	}
	defer db.lock(p.ID)()
	if err := db.d.Docker.ContainerRestart(ctx, Container(p.ID), stopGrace); err != nil {
		return nil, err
	}
	health, err := db.awaitHealthy(ctx, Container(p.ID))
	return ChangedResult{Changed: true, Health: health}, err
}

// InstanceStop stops the container (it stays, with restart unless-stopped keeping it down).
func (db *DB) InstanceStop(ctx context.Context, p IDPayload, _ commands.Stream) (any, error) {
	if err := checkID("id", p.ID); err != nil {
		return nil, err
	}
	defer db.lock(p.ID)()
	stopped, err := db.d.Docker.ContainerStop(ctx, Container(p.ID), stopGrace)
	if docker.IsNotFound(err) {
		return ChangedResult{}, nil
	}
	return ChangedResult{Changed: stopped}, err
}

// InstanceDelete removes the container, its firewall chain, its secret files, its certificate and settings. The data
// volume is the control plane's (volume.delete).
func (db *DB) InstanceDelete(ctx context.Context, p IDPayload, st commands.Stream) (any, error) {
	if err := checkID("id", p.ID); err != nil {
		return nil, err
	}
	defer db.lock(p.ID)()
	name := Container(p.ID)
	cur, exists, err := db.d.Docker.ContainerInspect(ctx, name)
	if err != nil {
		return nil, err
	}
	if exists {
		if _, err := db.d.Docker.ContainerStop(ctx, cur.ID, stopGrace); err != nil && !docker.IsNotFound(err) {
			return nil, err
		}
		if err := db.d.Docker.ContainerRemove(ctx, cur.ID); err != nil {
			return nil, err
		}
		fmt.Fprintf(st.Stdout(), "removed %s\n", name)
	}
	if err := db.removeFirewall(ctx, p.ID); err != nil {
		return nil, fmt.Errorf("firewall: %w", err)
	}
	dir := db.d.FS.P(db.secretsDir(p.ID))
	_ = os.Chmod(dir, 0o700)
	if err := os.RemoveAll(dir); err != nil {
		return nil, err
	}
	if err := os.RemoveAll(filepath.Dir(db.d.FS.P(db.tlsDir(p.ID)))); err != nil {
		return nil, err
	}
	return ChangedResult{Changed: exists}, nil
}

// ---- password / secrets ----

// PasswordPayload is db.instance.password and db.instance.secrets.
type PasswordPayload struct {
	ID       string `json:"id"`
	Engine   string `json:"engine"`
	Password string `json:"password"`
	// Mode (db.instance.password): replace (default) sets the new password; add (Redis / Valkey) adds it next to the
	// current one, which stays valid (also across restarts) until retire; retire drops every password but the current.
	Mode string `json:"mode,omitempty"`
	// Previous (db.instance.secrets, during an overlap): the password that stays valid until it is retired.
	Previous string `json:"previous,omitempty"`
}

// Secrets are the payload's secret values.
func (p PasswordPayload) Secrets() []string { return []string{p.Password, p.Previous} }

func (p PasswordPayload) validate() error {
	if err := checkID("id", p.ID); err != nil {
		return err
	}
	if err := checkEngine(p.Engine); err != nil {
		return err
	}
	if p.Password == "" && p.Mode != "retire" {
		return payloadErr("password is required")
	}
	switch p.Mode {
	case "", "replace", "retire":
	case "add":
		if !isKeyValue(p.Engine) {
			return payloadErr("mode add is for redis / valkey")
		}
	default:
		return payloadErr("unknown mode %q", p.Mode)
	}
	return nil
}

// InstancePassword rotates the superuser / root / default password through files in the secrets directory, never
// argv. replace: falak-db sets the new one, then it replaces the password file. add: the new one becomes the password
// file, the current one password.previous (both valid). retire: password.previous goes and the engine forgets it.
//
// It is idempotent: a retry after a failed file swap finds the engine already on the new password (falak-db then
// connects with it) and finishes the swap.
func (db *DB) InstancePassword(ctx context.Context, p PasswordPayload, _ commands.Stream) (any, error) {
	if err := p.validate(); err != nil {
		return nil, err
	}
	defer db.lock(p.ID)()
	dir := db.d.FS.P(db.secretsDir(p.ID))
	if _, err := os.Stat(dir); err != nil {
		return nil, fmt.Errorf("the secret files of %s are missing (restore them first): %w", Container(p.ID), err)
	}
	if p.Mode == "retire" {
		if _, _, err := db.exec(ctx, p.ID, nil, nil, nil, "password", "set", "--file", passwordPath); err != nil {
			return nil, err
		}
		removeSecret(dir, "password.previous")
		return ChangedResult{Changed: true}, nil
	}
	if cur, err := os.ReadFile(filepath.Join(dir, "password")); err == nil && string(cur) == p.Password {
		return ChangedResult{}, nil // already done (a retry)
	}
	const next = ".password.new"
	if err := writeSecret(dir, next, []byte(p.Password)); err != nil {
		return nil, err
	}
	args := []string{"password", "set", "--file", secretsTarget + "/" + next}
	if p.Mode == "add" {
		args = append(args, "--keep-current")
	}
	if _, _, err := db.exec(ctx, p.ID, nil, nil, nil, args...); err != nil {
		removeSecret(dir, next)
		return nil, err
	}
	err := writable(dir, func() error {
		if p.Mode == "add" {
			if err := os.Rename(filepath.Join(dir, "password"), filepath.Join(dir, "password.previous")); err != nil {
				return err
			}
		}
		return os.Rename(filepath.Join(dir, next), filepath.Join(dir, "password"))
	})
	if err != nil {
		return nil, err
	}
	return ChangedResult{Changed: true}, nil
}

// SecretsResult is db.instance.secrets' result.
type SecretsResult struct {
	Restored bool `json:"restored"`
	Started  bool `json:"started"`
}

// InstanceSecrets puts the password file back after a reboot emptied /run (the container could not start without
// its secrets directory) and starts the container. A present directory is left alone.
func (db *DB) InstanceSecrets(ctx context.Context, p PasswordPayload, st commands.Stream) (any, error) {
	if err := p.validate(); err != nil {
		return nil, err
	}
	defer db.lock(p.ID)()
	name := Container(p.ID)
	cur, exists, err := db.d.Docker.ContainerInspect(ctx, name)
	if err != nil {
		return nil, err
	}
	if !exists {
		return nil, fmt.Errorf("%s does not exist", name)
	}
	var res SecretsResult
	dir, created, err := db.ensureSecretsDir(p.ID)
	if err != nil {
		return nil, err
	}
	if created {
		if err := writeSecret(dir, "password", []byte(p.Password)); err != nil {
			return nil, err
		}
		if p.Previous != "" && isKeyValue(p.Engine) {
			if err := writeSecret(dir, "password.previous", []byte(p.Previous)); err != nil {
				return nil, err
			}
		}
		res.Restored = true
		fmt.Fprintf(st.Stdout(), "secret files of %s written\n", name)
	}
	if !cur.State.Running {
		if err := db.d.Docker.ContainerStart(ctx, cur.ID); err != nil {
			return res, fmt.Errorf("starting %s: %w", name, err)
		}
		res.Started = true
	}
	return res, nil
}

// ---- major upgrade ----

// UpgradeEnd is the source or target instance of an upgrade.
type UpgradeEnd struct {
	ID     string `json:"id"`
	Engine string `json:"engine"`
}

// UpgradeDatabase is a database copied by an upgrade.
type UpgradeDatabase struct {
	Name      string `json:"name"`
	Charset   string `json:"charset,omitempty"`
	Collation string `json:"collation,omitempty"`
	// Owner (postgres) gets the copied database and its objects (the application's user, applied first).
	Owner string `json:"owner,omitempty"`
}

// UpgradePayload is db.instance.upgrade (major versions of the SQL engines: a new instance, filled by dump and
// restore, takes over the old one's DNS name).
type UpgradePayload struct {
	Mode      string            `json:"mode"`
	Source    UpgradeEnd        `json:"source"`
	Target    UpgradeEnd        `json:"target"`
	Databases []UpgradeDatabase `json:"databases"`
	Users     []UserSpec        `json:"users"`
	Network   string            `json:"network,omitempty"`
	Alias     string            `json:"alias"`
}

// Secrets are the users' passwords.
func (p UpgradePayload) Secrets() []string {
	var out []string
	for _, u := range p.Users {
		out = append(out, u.Password)
	}
	return out
}

// UpgradeResult is its result.
type UpgradeResult struct {
	Databases  []CopiedDatabase `json:"databases"`
	DurationMS int64            `json:"duration_ms"`
}

// CopiedDatabase is one database moved by an upgrade.
type CopiedDatabase struct {
	Name   string `json:"name"`
	Bytes  int64  `json:"bytes"`
	Tables int    `json:"tables"`
	Rows   int64  `json:"rows"`
}

// InstanceUpgrade copies every database from the source to the target instance without losing a write: the source is
// put in read-only mode first (PostgreSQL ends its sessions), each database is piped from `falak-db backup logical`
// into `restore logical` (ownership to the application's user, applied before) and its row counts compared table by
// table. Only then does the target take over the DNS name and the source stop, read-only. Any failure puts the source
// back in read-write mode and leaves it serving.
func (db *DB) InstanceUpgrade(ctx context.Context, p UpgradePayload, st commands.Stream) (any, error) {
	start := time.Now()
	if p.Mode != "major" {
		return nil, payloadErr("unknown mode %q", p.Mode)
	}
	for _, e := range []UpgradeEnd{p.Source, p.Target} {
		if err := checkID("id", e.ID); err != nil {
			return nil, err
		}
		if err := checkEngine(e.Engine); err != nil {
			return nil, err
		}
	}
	if p.Source.ID == p.Target.ID || isKeyValue(p.Source.Engine) || p.Source.Engine != p.Target.Engine {
		return nil, payloadErr("a major upgrade copies between two instances of one SQL engine")
	}
	if p.Network != "" && !envNetRe.MatchString(p.Network) {
		return nil, payloadErr("invalid network %q", p.Network)
	}
	if !aliasRe.MatchString(p.Alias) {
		return nil, payloadErr("invalid alias %q", p.Alias)
	}
	for _, d := range p.Databases {
		if err := checkIdent("database", d.Name); err != nil {
			return nil, err
		}
		if d.Owner != "" {
			if err := checkIdent("owner", d.Owner); err != nil {
				return nil, err
			}
		}
	}
	defer db.lock(p.Source.ID, p.Target.ID)()

	// Databases, then users (granted on them): the restore hands ownership to the users.
	for _, d := range p.Databases {
		args := []string{"database", "create", "--name", d.Name}
		if d.Charset != "" {
			args = append(args, "--charset", d.Charset)
		}
		if d.Collation != "" {
			args = append(args, "--collation", d.Collation)
		}
		if _, _, err := db.exec(ctx, p.Target.ID, nil, nil, nil, args...); err != nil {
			return nil, err
		}
	}
	for _, u := range p.Users {
		if _, err := db.userApply(ctx, p.Target.ID, u); err != nil {
			return nil, err
		}
	}
	if _, _, err := db.exec(ctx, p.Source.ID, nil, nil, nil, "readonly", "on"); err != nil {
		return nil, fmt.Errorf("read-only mode on the source: %w", err)
	}
	fmt.Fprintf(st.Stdout(), "%s is read-only while its data is copied\n", Container(p.Source.ID))
	ok := false
	defer func() {
		if !ok {
			if _, _, err := db.exec(context.WithoutCancel(ctx), p.Source.ID, nil, nil, nil, "readonly", "off"); err != nil {
				fmt.Fprintf(st.Stderr(), "could not end read-only mode on %s: %v\n", Container(p.Source.ID), err)
			} else {
				fmt.Fprintf(st.Stdout(), "%s is writable again\n", Container(p.Source.ID))
			}
		}
	}()

	res := UpgradeResult{Databases: []CopiedDatabase{}}
	for _, d := range p.Databases {
		n, err := db.copyDatabase(ctx, p.Source.ID, p.Target.ID, d, p.Target.Engine)
		if err != nil {
			return nil, err
		}
		tables, rows, err := db.compareCounts(ctx, p.Source.ID, p.Target.ID, d.Name)
		if err != nil {
			return nil, err
		}
		fmt.Fprintf(st.Stdout(), "copied %s: %d bytes, %d tables, %d rows (counts match)\n", d.Name, n, tables, rows)
		res.Databases = append(res.Databases, CopiedDatabase{Name: d.Name, Bytes: n, Tables: tables, Rows: rows})
	}
	if p.Network != "" {
		if err := db.d.Docker.NetworkDisconnect(ctx, p.Network, Container(p.Target.ID)); err != nil {
			return nil, err
		}
		if err := db.d.Docker.NetworkConnect(ctx, p.Network, Container(p.Target.ID), []string{p.Alias, Container(p.Target.ID)}); err != nil {
			return nil, err
		}
		if err := db.d.Docker.NetworkDisconnect(ctx, p.Network, Container(p.Source.ID)); err != nil {
			return nil, err
		}
		fmt.Fprintf(st.Stdout(), "%s now answers as %s\n", Container(p.Target.ID), p.Alias)
	}
	ok = true
	// The source stays as it was copied (read-only), stopped, with its volume: deleting it is the user's call.
	if _, err := db.d.Docker.ContainerStop(ctx, Container(p.Source.ID), stopGrace); err != nil && !docker.IsNotFound(err) {
		return nil, err
	}
	res.DurationMS = time.Since(start).Milliseconds()
	return res, nil
}

// tableCounts is `falak-db table-counts` of a database.
func (db *DB) tableCounts(ctx context.Context, id, database string) (map[string]int64, error) {
	out, _, err := db.exec(ctx, id, nil, nil, nil, "table-counts", "--database", database)
	if err != nil {
		return nil, err
	}
	var r struct {
		Tables map[string]int64 `json:"tables"`
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(out)), &r); err != nil {
		return nil, fmt.Errorf("table counts of %s: %w", database, err)
	}
	return r.Tables, nil
}

// compareCounts checks that every table of the source has as many rows in the target.
func (db *DB) compareCounts(ctx context.Context, source, target, database string) (int, int64, error) {
	want, err := db.tableCounts(ctx, source, database)
	if err != nil {
		return 0, 0, err
	}
	got, err := db.tableCounts(ctx, target, database)
	if err != nil {
		return 0, 0, err
	}
	var rows int64
	var diff []string
	for t, n := range want {
		rows += n
		if g, ok := got[t]; !ok || g != n {
			diff = append(diff, fmt.Sprintf("%s: %d rows, copied %d", t, n, g))
		}
	}
	if len(diff) > 0 {
		slices.Sort(diff)
		return 0, 0, fmt.Errorf("the copy of %s differs from the source: %s", database, strings.Join(diff, "; "))
	}
	return len(want), rows, nil
}

// copyDatabase pipes a logical backup of the source into a restore on the target and returns the bytes moved.
func (db *DB) copyDatabase(ctx context.Context, source, target string, d UpgradeDatabase, engine string) (int64, error) {
	pr, pw := io.Pipe()
	cw := &countingWriter{w: pw}
	errSrc := make(chan error, 1)
	go func() {
		_, _, err := db.exec(ctx, source, nil, cw, nil, "backup", "logical", "--database", d.Name, "--out", "-")
		pw.CloseWithError(err)
		errSrc <- err
	}()
	args := []string{"restore", "logical", "--database", d.Name, "--in", "-"}
	if engine == "postgres" && d.Owner != "" {
		args = append(args, "--owner", d.Owner)
	}
	_, _, errDst := db.exec(ctx, target, pr, nil, nil, args...)
	pr.CloseWithError(io.ErrClosedPipe)
	errS := <-errSrc
	if errS != nil {
		return 0, fmt.Errorf("dump %s: %w", d.Name, errS)
	}
	if errDst != nil {
		return 0, fmt.Errorf("restore %s: %w", d.Name, errDst)
	}
	return cw.n, nil
}
