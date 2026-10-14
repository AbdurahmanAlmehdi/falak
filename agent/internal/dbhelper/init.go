package dbhelper

import (
	"bufio"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
)

// detectMemory finds the instance's memory limit: FALAK_DB_MEMORY_BYTES, else the container's cgroup limit (v2,
// then v1), else the host's RAM. A restart after `docker update --memory` therefore re-tunes the config.
func (h *Helper) detectMemory() (int64, string, error) {
	if v := h.Env("FALAK_DB_MEMORY_BYTES"); v != "" {
		n, err := strconv.ParseInt(v, 10, 64)
		if err != nil || n <= 0 {
			return 0, "", usageErr("FALAK_DB_MEMORY_BYTES: not a positive integer")
		}
		return n, "env", nil
	}
	if b, err := os.ReadFile(h.path("/sys/fs/cgroup/memory.max")); err == nil {
		if s := strings.TrimSpace(string(b)); s != "max" {
			if n, err := strconv.ParseInt(s, 10, 64); err == nil && n > 0 {
				return n, "cgroup", nil
			}
		}
	}
	if b, err := os.ReadFile(h.path("/sys/fs/cgroup/memory/memory.limit_in_bytes")); err == nil {
		// v1 reports "unlimited" as a huge page-aligned number.
		if n, err := strconv.ParseInt(strings.TrimSpace(string(b)), 10, 64); err == nil && n > 0 && n < 1<<60 {
			return n, "cgroup", nil
		}
	}
	f, err := os.Open(h.path("/proc/meminfo"))
	if err != nil {
		return 0, "", fmt.Errorf("memory limit: %w", err)
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		if fields := strings.Fields(sc.Text()); len(fields) >= 2 && fields[0] == "MemTotal:" {
			if kb, err := strconv.ParseInt(fields[1], 10, 64); err == nil {
				return kb << 10, "host", nil
			}
		}
	}
	return 0, "", errors.New("memory limit: MemTotal not found in /proc/meminfo")
}

func (h *Helper) settings() (Settings, error) {
	return ParseSettings([]byte(h.settingsJSON()))
}

// settingsJSON is the settings: FALAK_DB_SETTINGS_FILE (a file the agent rewrites, so a change only needs a restart)
// when set, else FALAK_DB_SETTINGS.
func (h *Helper) settingsJSON() string {
	if f := h.Env("FALAK_DB_SETTINGS_FILE"); f != "" {
		if b, err := os.ReadFile(filepath.Clean(f)); err == nil && len(bytes.TrimSpace(b)) > 0 {
			return string(b)
		}
	}
	return h.Env("FALAK_DB_SETTINGS")
}

// queryInsights reports whether the agent asked for query insights: query-insights.json ({"enabled": true}) next to
// FALAK_DB_SETTINGS_FILE, else FALAK_DB_QUERY_INSIGHTS (1 / true).
func (h *Helper) queryInsights() bool {
	if f := h.Env("FALAK_DB_SETTINGS_FILE"); f != "" {
		if b, err := os.ReadFile(filepath.Join(filepath.Dir(filepath.Clean(f)), queryInsightsFile)); err == nil {
			var v struct {
				Enabled bool `json:"enabled"`
			}
			return json.Unmarshal(b, &v) == nil && v.Enabled
		}
	}
	switch strings.ToLower(h.Env("FALAK_DB_QUERY_INSIGHTS")) {
	case "1", "true", "on", "yes":
		return true
	}
	return false
}

// queryInsightsFile is written by the agent next to the settings file (agent/internal/db).
const queryInsightsFile = "query-insights.json"

func (h *Helper) tlsSource() string { return h.Env.or("FALAK_DB_TLS_DIR", defaultTLS) }

func fileExists(p string) bool {
	st, err := os.Stat(p)
	return err == nil && st.Mode().IsRegular()
}

// renderInput gathers what Render needs from the environment.
func (h *Helper) renderInput(mem int64, s Settings) (RenderInput, error) {
	in := RenderInput{Engine: h.Engine, MemoryBytes: mem, Settings: s, DataDir: dataDir(h.Engine, h.Env), QueryInsights: h.queryInsights()}
	if s.tls() {
		in.TLSCA = fileExists(filepath.Join(h.path(h.tlsSource()), "ca.crt"))
	}
	if h.Engine.kv() {
		pw, err := readPassword(h.Engine, h.Env)
		if err != nil {
			return in, err
		}
		sum := sha256.Sum256([]byte(pw))
		in.PasswordSHA256 = hex.EncodeToString(sum[:])
		// A rotation in its overlap: the previous password stays valid across restarts until the agent retires it.
		if f := h.Env("FALAK_DB_PREVIOUS_PASSWORD_FILE"); f != "" {
			if prev, err := os.ReadFile(filepath.Clean(f)); err == nil && len(bytes.TrimRight(prev, "\r\n")) > 0 {
				ps := sha256.Sum256(bytes.TrimRight(prev, "\r\n"))
				in.PreviousPasswordSHA256 = hex.EncodeToString(ps[:])
			}
		}
	}
	return in, nil
}

// writeConfig writes rendered files (readable by the engine's user) and returns their paths.
func (h *Helper) writeConfig(files []File) error {
	for _, f := range files {
		p := h.path(f.Path)
		dir := filepath.Dir(p)
		if err := os.MkdirAll(dir, 0o755); err != nil {
			return err
		}
		if err := writeFileAtomic(p, []byte(f.Content), f.Mode); err != nil {
			return fmt.Errorf("write %s: %w", f.Path, err)
		}
		// Files the server must read but others must not (the ACL) belong to the engine's user, with their dir.
		if f.Mode&0o004 == 0 {
			if err := h.chown(dir, p); err != nil {
				return err
			}
			if err := os.Chmod(dir, 0o750); err != nil {
				return err
			}
		}
	}
	return nil
}

// installTLS copies the mounted certificate and key to where the server reads them, owned by its user with the key at
// 0600: the mount itself (a read-only bind or secret) can't be chowned, and postgres refuses group-readable keys.
func (h *Helper) installTLS() error {
	src := h.path(h.tlsSource())
	dst := h.path(tlsDir)
	if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
		return err
	}
	if err := os.MkdirAll(dst, 0o750); err != nil {
		return err
	}
	for _, f := range []struct {
		name     string
		mode     os.FileMode
		required bool
	}{{"server.crt", 0o644, true}, {"server.key", 0o600, true}, {"ca.crt", 0o644, false}} {
		b, err := os.ReadFile(filepath.Join(src, f.name))
		if errors.Is(err, fs.ErrNotExist) && !f.required {
			continue
		}
		if err != nil {
			return fmt.Errorf("tls is on but %s is missing in %s (mount the certificate or set \"tls\": false): %w", f.name, h.tlsSource(), err)
		}
		p := filepath.Join(dst, f.name)
		if err := writeFileAtomic(p, b, f.mode); err != nil {
			return err
		}
		if err := h.chown(p); err != nil {
			return err
		}
	}
	return h.chown(dst)
}

// RenderResult is the JSON result of `config render` and the first half of `init`.
type RenderResult struct {
	Engine       Engine   `json:"engine"`
	MemoryBytes  int64    `json:"memory_bytes"`
	MemorySource string   `json:"memory_source,omitempty"`
	Files        []string `json:"files"`
	Tuning       Tuning   `json:"tuning"`
}

// renderAndWrite renders the config for mem and writes it.
func (h *Helper) renderAndWrite(mem int64, s Settings) (RenderResult, error) {
	in, err := h.renderInput(mem, s)
	if err != nil {
		return RenderResult{}, err
	}
	files, tuning, err := Render(in)
	if err != nil {
		return RenderResult{}, err
	}
	if err := h.writeConfig(files); err != nil {
		return RenderResult{}, err
	}
	res := RenderResult{Engine: h.Engine, MemoryBytes: mem, Tuning: tuning}
	for _, f := range files {
		res.Files = append(res.Files, f.Path)
	}
	return res, nil
}

// ConfigRender is `falak-db config render`.
func (h *Helper) ConfigRender(mem int64, settingsJSON string) error {
	s, err := ParseSettings([]byte(settingsJSON))
	if err != nil {
		return err
	}
	if mem == 0 {
		if mem, _, err = h.detectMemory(); err != nil {
			return err
		}
	}
	res, err := h.renderAndWrite(mem, s)
	if err != nil {
		return err
	}
	return h.result(res)
}

// Init is the image's entrypoint: check the environment, render the config, prepare the directories, then replace
// itself with the official entrypoint so its first-run initialisation (POSTGRES_PASSWORD_FILE,
// MYSQL_ROOT_PASSWORD_FILE, ...) works unchanged.
func (h *Helper) Init(extra []string) error {
	if err := checkEnv(h.Env); err != nil {
		return usageErr("%v", err)
	}
	if !h.Engine.kv() && passwordFile(h.Engine, h.Env) == "" {
		return usageErr("no superuser password file (%s or FALAK_DB_PASSWORD_FILE)", entrypointVar[h.Engine])
	}
	s, err := h.settings()
	if err != nil {
		return err
	}
	mem, source, err := h.detectMemory()
	if err != nil {
		return err
	}
	if s.tls() {
		if err := h.installTLS(); err != nil {
			return err
		}
	}
	res, err := h.renderAndWrite(mem, s)
	if err != nil {
		return err
	}
	res.MemorySource = source

	spool := h.path(spoolDir(h.Env))
	kind := spoolWAL
	if h.Engine.mysqlFamily() {
		kind = spoolBinlog
	}
	// The spool itself stays root's (0755): only spool/<kind> is the engine's, so the engine's user (a superuser who
	// got a shell through COPY ... PROGRAM) can't swap spool/<kind> for a symlink the host agent would follow.
	if !h.Engine.kv() {
		if err := os.MkdirAll(spool, 0o755); err != nil {
			return err
		}
		if err := os.Chmod(spool, 0o755); err != nil {
			return err
		}
		if h.Chown != nil {
			if err := h.Chown(spool, 0, 0); err != nil {
				return err
			}
		}
	}
	dirs := []string{filepath.Join(spool, kind)}
	if h.Engine.mysqlFamily() {
		dirs = append(dirs, h.path(filepath.Dir(mySlowLog)))
	}
	if h.Engine.kv() {
		// The official entrypoint only fixes the ownership of its working directory, /data.
		dirs = []string{h.path(runDir), h.path(dataDir(h.Engine, h.Env))}
	}
	for _, d := range dirs {
		// Parents stay traversable (0755); the spool itself is private to the engine's user.
		if err := os.MkdirAll(filepath.Dir(d), 0o755); err != nil {
			return err
		}
		if err := os.MkdirAll(d, 0o700); err != nil {
			return err
		}
		if err := h.chown(d); err != nil {
			return err
		}
	}

	if h.Engine == Postgres {
		if err := h.pgDropFinishedRecovery(); err != nil {
			return err
		}
	}

	argv := h.serverArgv(extra)
	path, err := exec.LookPath(argv[0])
	if err != nil {
		return err
	}
	fmt.Fprintf(h.Stderr, "falak-db: %s %s, memory %d MiB (%s), starting %s\n", h.Engine, strings.Join(res.Files, " "),
		mib(mem), source, h.Engine.server())
	return h.Exec(path, argv, h.entrypointEnv(os.Environ()))
}

// entrypointVar is the official entrypoint's password-file variable.
var entrypointVar = map[Engine]string{
	Postgres: "POSTGRES_PASSWORD_FILE", MySQL: "MYSQL_ROOT_PASSWORD_FILE", MariaDB: "MARIADB_ROOT_PASSWORD_FILE",
}

// entrypointEnv hands FALAK_DB_PASSWORD_FILE to the official entrypoint under its own name when only ours is set, so
// first-time initialisation sets the superuser password from the same file.
func (h *Helper) entrypointEnv(env []string) []string {
	v, ok := entrypointVar[h.Engine]
	if !ok || h.Env("FALAK_DB_PASSWORD_FILE") == "" {
		return env
	}
	if h.Env(v) != "" || (h.Engine == MariaDB && h.Env("MYSQL_ROOT_PASSWORD_FILE") != "") {
		return env
	}
	return append(env, v+"="+h.Env("FALAK_DB_PASSWORD_FILE"))
}

// pgDropFinishedRecovery removes falak-recovery.conf once recovery is over (no recovery.signal left: postgres
// promoted), so its restore_command and target do not linger.
func (h *Helper) pgDropFinishedRecovery() error {
	dir := h.path(dataDir(Postgres, h.Env))
	if fileExists(filepath.Join(dir, "recovery.signal")) {
		return nil
	}
	if err := os.Remove(filepath.Join(dir, recoveryConf)); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return err
	}
	return nil
}

// serverArgv is the official entrypoint's command line for our config.
func (h *Helper) serverArgv(extra []string) []string {
	argv := []string{"docker-entrypoint.sh", h.Engine.server()}
	switch h.Engine {
	case Postgres:
		argv = append(argv, "-c", "config_file="+pgConfPath)
	case Redis, Valkey:
		argv = append(argv, kvConfPath)
	}
	return append(argv, extra...)
}
