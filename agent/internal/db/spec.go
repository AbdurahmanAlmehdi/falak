package db

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/docker"
)

// InstanceSpec is the container an instance should be (db.instance.create / db.instance.update `instance`).
type InstanceSpec struct {
	ID      string `json:"id"`
	Engine  string `json:"engine"`
	Version string `json:"version"`
	Image   string `json:"image"`
	// Digest pins the image (pulled as <repository>@<digest> and checked against the pulled image's RepoDigests).
	Digest   string `json:"digest,omitempty"`
	VolumeID string `json:"volume_id"`
	// HostPort publishes the engine on 127.0.0.1 (native sites on the server) and on Publish.Addresses; 0: not at all.
	HostPort    int             `json:"host_port,omitempty"`
	MemoryBytes int64           `json:"memory_bytes"`
	CPUs        float64         `json:"cpus,omitempty"`
	Settings    json.RawMessage `json:"settings,omitempty"`
	// Network is the environment's network (falak-env-<id>), joined under Aliases (default: the container's name).
	Network string   `json:"network,omitempty"`
	Aliases []string `json:"aliases,omitempty"`
	Publish *Publish `json:"publish,omitempty"`
	TLS     *TLS     `json:"tls,omitempty"`
	// PITR: the spool is shipped (point-in-time recovery). Not part of the container: changing it touches nothing.
	PITR *PITRSpec `json:"pitr,omitempty"`
	// QueryInsights (feature db.observability) loads what top queries need (pg_stat_statements, MariaDB
	// performance_schema): a file next to the settings, read by falak-db at start. Changing it restarts the engine.
	QueryInsights bool `json:"query_insights,omitempty"`
}

// Publish is where else the engine's port is published: private / WireGuard addresses, or every address when Public.
type Publish struct {
	Addresses []string `json:"addresses,omitempty"`
	Public    bool     `json:"public,omitempty"`
	// AllowedSources are the IPv4 networks (CIDR) that may reach the published port on the private addresses or, with
	// Public, at all; the agent's DOCKER-USER rules drop everyone else. None: only loopback reaches it.
	AllowedSources []string `json:"allowed_sources,omitempty"`
}

// TLS is the instance's certificate from the Falak CA (PEM).
type TLS struct {
	Certificate string `json:"certificate"`
	PrivateKey  string `json:"private_key"`
	CA          string `json:"ca,omitempty"`
}

// In-container paths (docs/DB_IMAGES.md "Running an image").
const (
	secretsTarget = "/run/secrets"
	passwordPath  = secretsTarget + "/password"
	tlsTarget     = "/run/falak/db/tls"
	spoolTarget   = "/var/lib/falak/db/spool"
)

// port is the engine's port in the container.
func port(engine string) int {
	switch engine {
	case "postgres":
		return 5432
	case "mysql", "mariadb":
		return 3306
	}
	return 6379
}

// dataTarget is the engine's data directory (PostgreSQL 18 moved PGDATA below /var/lib/postgresql/18/docker).
func dataTarget(engine, version string) string {
	switch engine {
	case "postgres":
		if major, _ := strconv.Atoi(strings.SplitN(version, ".", 2)[0]); major >= 18 {
			return "/var/lib/postgresql"
		}
		return "/var/lib/postgresql/data"
	case "mysql", "mariadb":
		return "/var/lib/mysql"
	}
	return "/data"
}

// passwordEnv is the variable naming the password file for the engine's entrypoint (and falak-db).
func passwordEnv(engine string) string {
	switch engine {
	case "postgres":
		return "POSTGRES_PASSWORD_FILE"
	case "mysql":
		return "MYSQL_ROOT_PASSWORD_FILE"
	case "mariadb":
		return "MARIADB_ROOT_PASSWORD_FILE"
	}
	return "FALAK_DB_PASSWORD_FILE"
}

// shmSize is /dev/shm for PostgreSQL (parallel query workers use it): a quarter of the memory limit, 64 MiB to 1 GiB.
func shmSize(memory int64) int64 {
	return max(64<<20, min(memory/4, 1<<30))
}

// privateIPv4 accepts the addresses a database may be published on besides loopback: RFC 1918 and CGNAT (WireGuard,
// Tailscale-style overlays).
func privateIPv4(s string) bool {
	ip := net.ParseIP(s).To4()
	if ip == nil {
		return false
	}
	_, cgnat, _ := net.ParseCIDR("100.64.0.0/10")
	return ip.IsPrivate() || cgnat.Contains(ip)
}

// validate checks the spec (shape is the schema's job; this is what the agent must never trust blindly).
func (s InstanceSpec) validate() error {
	if err := checkID("instance.id", s.ID); err != nil {
		return err
	}
	if err := checkEngine(s.Engine); err != nil {
		return err
	}
	if err := checkID("instance.volume_id", s.VolumeID); err != nil {
		return err
	}
	if !versionRe.MatchString(s.Version) {
		return payloadErr("invalid version %q", s.Version)
	}
	if s.Image == "" || strings.ContainsAny(s.Image, " \t\n@") {
		return payloadErr("invalid image %q", s.Image)
	}
	// Database images only run pinned by digest (the control plane ships the list CI produced): never a tag as pulled.
	if !digestRe.MatchString(s.Digest) {
		return payloadErr("instance.digest: a pinned sha256 digest is required, got %q", s.Digest)
	}
	if s.MemoryBytes < 32<<20 {
		return payloadErr("memory_bytes must be at least 32 MiB")
	}
	if s.HostPort < 0 || s.HostPort > 65535 {
		return payloadErr("invalid host_port %d", s.HostPort)
	}
	if s.Network != "" && !envNetRe.MatchString(s.Network) {
		return payloadErr("invalid network %q", s.Network)
	}
	for _, a := range s.Aliases {
		if !aliasRe.MatchString(a) {
			return payloadErr("invalid alias %q", a)
		}
	}
	if s.Publish != nil {
		if s.HostPort == 0 {
			return payloadErr("publish needs host_port")
		}
		for _, a := range s.Publish.Addresses {
			if !privateIPv4(a) {
				return payloadErr("publish.addresses: %q is not a private IPv4 address (public access is publish.public)", a)
			}
		}
		for _, c := range s.Publish.AllowedSources {
			if _, n, err := net.ParseCIDR(c); err != nil || n.IP.To4() == nil || n.String() != c {
				return payloadErr("publish.allowed_sources: %q is not an IPv4 network in CIDR form", c)
			}
		}
	}
	if s.TLS != nil && (s.TLS.Certificate == "" || s.TLS.PrivateKey == "") {
		return payloadErr("tls needs certificate and private_key")
	}
	if len(s.Settings) > 0 {
		var m map[string]any
		if err := json.Unmarshal(s.Settings, &m); err != nil {
			return payloadErr("settings must be a JSON object")
		}
	}
	return nil
}

// settingsJSON is FALAK_DB_SETTINGS: the settings, with TLS off when the instance has no certificate (falak-db refuses
// to start with TLS on and nothing mounted).
func (s InstanceSpec) settingsJSON(tls bool) string {
	m := map[string]any{}
	if len(s.Settings) > 0 {
		_ = json.Unmarshal(s.Settings, &m)
	}
	if !tls {
		m["tls"] = false
		delete(m, "require_tls")
	}
	b, _ := json.Marshal(m)
	return string(b)
}

// aliases are the instance's DNS names on its network.
func (s InstanceSpec) aliases() []string {
	if len(s.Aliases) > 0 {
		return s.Aliases
	}
	return []string{Container(s.ID)}
}

// ref is the image the container runs: <repository>@<digest> when pinned.
func (s InstanceSpec) ref() string {
	if s.Digest == "" {
		return s.Image
	}
	return repository(s.Image) + "@" + s.Digest
}

// repository strips the tag from an image reference.
func repository(ref string) string {
	if i := strings.Index(ref, "@"); i >= 0 {
		ref = ref[:i]
	}
	if i := strings.LastIndex(ref, ":"); i > strings.LastIndex(ref, "/") {
		return ref[:i]
	}
	return ref
}

// hash covers what Docker can only change by recreating the container: image, mounts, ports, network, environment.
// Settings (a file falak-db renders at start), the certificate (files installed at start), the limits (changed in
// place) and the firewall's allowed sources are not part of it: changing them restarts the container or nothing.
// The password lives in the secrets directory (db.instance.password rotates it).
func (s InstanceSpec) hash() string {
	q := s
	q.TLS, q.Settings, q.MemoryBytes, q.CPUs, q.PITR, q.QueryInsights = nil, nil, 0, 0, nil, false
	if s.Publish != nil {
		p := *s.Publish
		p.AllowedSources = nil
		q.Publish = &p
	}
	b, _ := json.Marshal(q)
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

// Host paths of an instance.
func (db *DB) volumeDir(volumeID string) string { return filepath.Join(db.d.VolumesRoot, volumeID) }
func (db *DB) secretsDir(id string) string      { return filepath.Join(db.d.SecretsDir, Container(id)) }
func (db *DB) tlsDir(id string) string          { return filepath.Join(db.d.EtcDir, "db", id, "tls") }
func (db *DB) confDir(id string) string         { return filepath.Join(db.d.EtcDir, "db", id, "conf") }

// In-container paths of the agent's per-instance files.
const (
	confTarget       = "/run/falak/db/conf"
	settingsFile     = confTarget + "/settings.json"
	previousPassword = secretsTarget + "/password.previous"
)

// createBody builds the container (pure: tested without Docker). Secrets only ever appear as the mounted directory;
// settings come from a file next to the certificate, so changing them is a restart, not a new container.
func (db *DB) createBody(s InstanceSpec) docker.CreateBody {
	vol := db.d.FS.P(db.volumeDir(s.VolumeID))
	stop := 60
	env := []string{
		"FALAK_DB_SETTINGS_FILE=" + settingsFile,
		"FALAK_DB_SPOOL=" + spoolTarget,
		passwordEnv(s.Engine) + "=" + passwordPath,
	}
	if isKeyValue(s.Engine) {
		// A password rotation's overlap: the previous password stays valid across restarts until it is retired.
		env = append(env, "FALAK_DB_PREVIOUS_PASSWORD_FILE="+previousPassword)
	}
	b := docker.CreateBody{
		Image: s.ref(),
		Env:   env,
		Labels: map[string]string{
			docker.LabelManaged:  "true",
			docker.LabelSpecHash: s.hash(),
			LabelInstance:        s.ID,
			LabelEngine:          s.Engine,
		},
		Healthcheck: &docker.Healthcheck{
			Test:        []string{"CMD", "falak-db", "health"},
			Interval:    int64(10 * time.Second),
			Timeout:     int64(25 * time.Second),
			Retries:     3,
			StartPeriod: int64(120 * time.Second),
		},
		StopTimeout: &stop,
		HostConfig: docker.HostConfig{
			Mounts: []docker.Mount{
				{Type: "bind", Source: filepath.Join(vol, "data"), Target: dataTarget(s.Engine, s.Version)},
				{Type: "bind", Source: filepath.Join(vol, "spool"), Target: spoolTarget},
				{Type: "bind", Source: db.d.FS.P(db.secretsDir(s.ID)), Target: secretsTarget, ReadOnly: true},
				{Type: "bind", Source: db.d.FS.P(db.tlsDir(s.ID)), Target: tlsTarget, ReadOnly: true},
				{Type: "bind", Source: db.d.FS.P(db.confDir(s.ID)), Target: confTarget, ReadOnly: true},
			},
			RestartPolicy: docker.RestartPolicy{Name: "unless-stopped"},
			Memory:        s.MemoryBytes,
			NanoCPUs:      int64(s.CPUs * 1e9),
		},
	}
	if s.Engine == "postgres" {
		b.HostConfig.ShmSize = shmSize(s.MemoryBytes)
	}
	if s.HostPort > 0 {
		key := strconv.Itoa(port(s.Engine)) + "/tcp"
		hp := strconv.Itoa(s.HostPort)
		b.ExposedPorts = map[string]struct{}{key: {}}
		binds := []docker.PortBinding{{HostIP: "127.0.0.1", HostPort: hp}}
		if s.Publish != nil && s.Publish.Public {
			binds = []docker.PortBinding{{HostIP: "0.0.0.0", HostPort: hp}}
		} else if s.Publish != nil {
			for _, a := range s.Publish.Addresses {
				binds = append(binds, docker.PortBinding{HostIP: a, HostPort: hp})
			}
		}
		b.HostConfig.PortBindings = map[string][]docker.PortBinding{key: binds}
	}
	if s.Network != "" {
		b.HostConfig.NetworkMode = s.Network
		b.NetworkingConfig = &docker.NetworkingConfig{EndpointsConfig: map[string]docker.EndpointConfig{s.Network: {Aliases: s.aliases()}}}
	}
	return b
}
