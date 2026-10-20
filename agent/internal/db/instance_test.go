package db

import (
	"context"
	"encoding/json"
	"os"
	"slices"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

var pinned = "ghcr.io/falak-sh/falak-postgres@sha256:" + strings.Repeat("a", 64)

func TestCreateBody(t *testing.T) {
	db := New(Deps{FS: hostfs.FS{}})
	s := spec()
	s.CPUs = 1.5
	s.Publish = &Publish{Addresses: []string{"10.0.0.5", "100.64.3.2"}}
	b := db.createBody(s)

	if b.Image != pinned || b.Labels[LabelInstance] != instID || b.Labels[LabelEngine] != "postgres" {
		t.Errorf("image/labels: %s %v", b.Image, b.Labels)
	}
	wantMounts := []docker.Mount{
		{Type: "bind", Source: "/var/lib/falak/volumes/" + volID + "/data", Target: "/var/lib/postgresql/data"},
		{Type: "bind", Source: "/var/lib/falak/volumes/" + volID + "/spool", Target: "/var/lib/falak/db/spool"},
		{Type: "bind", Source: "/run/falak/secrets/falak-db-" + instID, Target: "/run/secrets", ReadOnly: true},
		{Type: "bind", Source: "/etc/falak/db/" + instID + "/tls", Target: "/run/falak/db/tls", ReadOnly: true},
		{Type: "bind", Source: "/etc/falak/db/" + instID + "/conf", Target: "/run/falak/db/conf", ReadOnly: true},
	}
	if !slices.Equal(b.HostConfig.Mounts, wantMounts) || len(b.HostConfig.Binds) != 0 {
		t.Errorf("mounts %+v", b.HostConfig.Mounts)
	}
	if b.HostConfig.Memory != 512<<20 || b.HostConfig.NanoCPUs != 1_500_000_000 || b.HostConfig.ShmSize != 128<<20 {
		t.Errorf("limits %+v", b.HostConfig)
	}
	if b.HostConfig.RestartPolicy.Name != "unless-stopped" || b.StopTimeout == nil || *b.StopTimeout != 60 {
		t.Errorf("restart/stop %+v %v", b.HostConfig.RestartPolicy, b.StopTimeout)
	}
	if b.Healthcheck == nil || strings.Join(b.Healthcheck.Test, " ") != "CMD falak-db health" {
		t.Errorf("healthcheck %+v", b.Healthcheck)
	}
	binds := b.HostConfig.PortBindings["5432/tcp"]
	if len(binds) != 3 || binds[0] != (docker.PortBinding{HostIP: "127.0.0.1", HostPort: "20001"}) || binds[1].HostIP != "10.0.0.5" || binds[2].HostIP != "100.64.3.2" {
		t.Errorf("port bindings %+v", binds)
	}
	if b.HostConfig.NetworkMode != s.Network || !slices.Equal(b.NetworkingConfig.EndpointsConfig[s.Network].Aliases, []string{"falak-db-" + instID}) {
		t.Errorf("network %s %+v", b.HostConfig.NetworkMode, b.NetworkingConfig)
	}
	env := strings.Join(b.Env, "\n")
	// Settings come from a file (a change is a restart, not a new container); no secret in the environment.
	if !strings.Contains(env, "POSTGRES_PASSWORD_FILE=/run/secrets/password") || !strings.Contains(env, "FALAK_DB_SETTINGS_FILE=/run/falak/db/conf/settings.json") || strings.Contains(env, "max_connections") {
		t.Errorf("env %s", env)
	}

	// Settings, limits, the certificate and the allowed sources never change the hash (no recreation for them).
	s2 := s
	s2.Settings, s2.MemoryBytes, s2.CPUs, s2.TLS = []byte(`{"max_connections": 999}`), 2<<30, 4, &TLS{Certificate: "c", PrivateKey: "k"}
	s2.Publish = &Publish{Addresses: s.Publish.Addresses, AllowedSources: []string{"10.0.0.0/24"}}
	if s2.hash() != s.hash() {
		t.Error("a live change changes the spec hash")
	}
	s2.Publish = &Publish{Addresses: []string{"10.0.0.5"}}
	if s2.hash() == s.hash() {
		t.Error("published addresses do not change the hash")
	}

	// Redis: the previous password file stays valid across restarts during an overlap.
	s = spec()
	s.Engine, s.Version, s.HostPort, s.Network, s.Aliases = "redis", "8", 0, "falak-env-01hzyenv000000000000000001", []string{"falak-db-old", "cache"}
	b = db.createBody(s)
	if len(b.HostConfig.PortBindings) != 0 || b.HostConfig.ShmSize != 0 || len(b.HostConfig.Mounts) != 5 || b.HostConfig.Mounts[0].Target != "/data" {
		t.Errorf("redis body %+v", b.HostConfig)
	}
	if env := strings.Join(b.Env, " "); !strings.Contains(env, "FALAK_DB_PASSWORD_FILE=/run/secrets/password") || !strings.Contains(env, "FALAK_DB_PREVIOUS_PASSWORD_FILE=/run/secrets/password.previous") {
		t.Errorf("redis env %v", b.Env)
	}
	if got := b.NetworkingConfig.EndpointsConfig[s.Network].Aliases; !slices.Equal(got, []string{"falak-db-old", "cache"}) {
		t.Errorf("aliases %v", got)
	}

	// PostgreSQL 18's data directory; public access binds every address.
	s = spec()
	s.Version = "18"
	s.Publish = &Publish{Public: true}
	b = db.createBody(s)
	if b.HostConfig.Mounts[0].Target != "/var/lib/postgresql" || len(b.HostConfig.PortBindings["5432/tcp"]) != 1 || b.HostConfig.PortBindings["5432/tcp"][0].HostIP != "0.0.0.0" {
		t.Errorf("pg18 %+v", b.HostConfig)
	}
	if shmSize(16<<30) != 1<<30 || shmSize(128<<20) != 64<<20 {
		t.Error("shm bounds")
	}
}

func TestSpecValidation(t *testing.T) {
	for name, mut := range map[string]func(*InstanceSpec){
		"public address":   func(s *InstanceSpec) { s.Publish = &Publish{Addresses: []string{"203.0.113.9"}} },
		"any address":      func(s *InstanceSpec) { s.Publish = &Publish{Addresses: []string{"0.0.0.0"}} },
		"loopback publish": func(s *InstanceSpec) { s.Publish = &Publish{Addresses: []string{"127.0.0.1"}} },
		"publish no port":  func(s *InstanceSpec) { s.HostPort = 0; s.Publish = &Publish{Addresses: []string{"10.0.0.5"}} },
		"allowed source": func(s *InstanceSpec) {
			s.Publish = &Publish{Addresses: []string{"10.0.0.5"}, AllowedSources: []string{"10.0.0.1"}}
		},
		"foreign network": func(s *InstanceSpec) { s.Network = "bridge" },
		"engine":          func(s *InstanceSpec) { s.Engine = "sqlite" },
		"id":              func(s *InstanceSpec) { s.ID = "../x" },
		"memory":          func(s *InstanceSpec) { s.MemoryBytes = 1 << 20 },
		"digest":          func(s *InstanceSpec) { s.Digest = "sha256:nope" },
		"no digest":       func(s *InstanceSpec) { s.Digest = "" },
		"image with @":    func(s *InstanceSpec) { s.Image = "x@sha256:" + strings.Repeat("a", 64) },
		"settings":        func(s *InstanceSpec) { s.Settings = []byte(`[1]`) },
		"alias":           func(s *InstanceSpec) { s.Aliases = []string{"Bad_Alias"} },
	} {
		s := spec()
		mut(&s)
		if err := s.validate(); !commands.IsPayloadError(err) {
			t.Errorf("%s: %v", name, err)
		}
	}
	if err := spec().validate(); err != nil {
		t.Fatal(err)
	}
}

func createPayload() InstancePayload {
	s := spec()
	s.TLS = &TLS{Certificate: "CERT", PrivateKey: "KEY", CA: "CA"}
	return InstancePayload{Instance: s, Password: secret}
}

func TestInstanceCreateRunsTheContainerWithItsSecretsAsFiles(t *testing.T) {
	h := newHarness(t)
	res, err := h.db.InstanceCreate(context.Background(), createPayload(), stream())
	if err != nil {
		t.Fatal(err)
	}
	r := res.(InstanceResult)
	if !r.Changed || r.Health != "healthy" || r.ImageDigest != spec().Digest || h.dock.pulls[0] != pinned {
		t.Errorf("result %+v pulls %v", r, h.dock.pulls)
	}
	pw, err := os.ReadFile(h.path("/run/falak/secrets/falak-db-" + instID + "/password"))
	if err != nil || string(pw) != secret {
		t.Fatalf("password file %q %v", pw, err)
	}
	if st, _ := os.Stat(h.path("/run/falak/secrets/falak-db-" + instID)); st.Mode().Perm() != 0o555 {
		t.Errorf("secrets dir mode %v", st.Mode().Perm())
	}
	if st, _ := os.Stat(h.path("/run/falak/secrets")); st.Mode().Perm() != 0o700 {
		t.Errorf("secrets parent mode %v", st.Mode().Perm())
	}
	if key, _ := os.ReadFile(h.path("/etc/falak/db/" + instID + "/tls/server.key")); string(key) != "KEY" {
		t.Errorf("tls key %q", key)
	}
	if settings, _ := os.ReadFile(h.path("/etc/falak/db/" + instID + "/conf/settings.json")); string(settings) != `{"max_connections":200}` {
		t.Errorf("settings file %q", settings)
	}
	for _, sub := range []string{"data", "spool"} {
		if _, err := os.Stat(h.path("/var/lib/falak/volumes/" + volID + "/" + sub)); err != nil {
			t.Error(err)
		}
	}
	body := h.dock.bodies["falak-db-"+instID]
	b, _ := json.Marshal(body)
	if strings.Contains(string(b), secret) || strings.Contains(string(b), "KEY\"") {
		t.Errorf("a secret is in the container spec: %s", b)
	}
	if h.dock.netLabels[spec().Network]["falak.network"] != "environment" {
		t.Errorf("network not created: %v", h.dock.netLabels)
	}

	// Same spec again (an update without tls keeps the installed certificate): nothing restarted or recreated.
	p := createPayload()
	p.Instance.TLS = nil
	h.dock.calls = nil
	res, err = h.db.InstanceUpdate(context.Background(), p, stream())
	if err != nil || res.(InstanceResult).Changed || h.dock.called("create") || h.dock.called("stop") || h.dock.called("restart") {
		t.Fatalf("idempotent update: %+v %v %v", res, err, h.dock.calls)
	}
	if _, err := os.Stat(h.path("/etc/falak/db/" + instID + "/tls/server.crt")); err != nil {
		t.Error("an update without tls removed the certificate")
	}

	// Settings and limits change in place: docker update, the settings file, a restart; never a new container.
	p.Instance.Settings = []byte(`{"max_connections": 300}`)
	p.Instance.MemoryBytes = 1 << 30
	res, err = h.db.InstanceUpdate(context.Background(), p, stream())
	if err != nil || !res.(InstanceResult).Restarted || !h.dock.called("update falak-db-"+instID+" 1073741824") || !h.dock.called("restart falak-db-"+instID+" 1m0s") || h.dock.called("create") {
		t.Fatalf("settings update: %+v %v %v", res, err, h.dock.calls)
	}
	if settings, _ := os.ReadFile(h.path("/etc/falak/db/" + instID + "/conf/settings.json")); string(settings) != `{"max_connections":300}` {
		t.Errorf("settings file %q", settings)
	}

	// A renewed certificate restarts it too.
	h.dock.calls = nil
	p.Instance.TLS = &TLS{Certificate: "CERT2", PrivateKey: "KEY2"}
	if res, err := h.db.InstanceUpdate(context.Background(), p, stream()); err != nil || !res.(InstanceResult).Restarted || h.dock.called("create") {
		t.Fatalf("new certificate: %+v %v %v", res, err, h.dock.calls)
	}

	// New published addresses recreate it (Docker binds ports at creation).
	h.dock.calls = nil
	p.Instance.Publish = &Publish{Addresses: []string{"10.0.0.5"}, AllowedSources: []string{"10.0.0.7/32"}}
	if _, err := h.db.InstanceUpdate(context.Background(), p, stream()); err != nil || !h.dock.called("stop falak-db-"+instID+" 1m0s") || !h.dock.called("create") {
		t.Fatalf("new address: %v %v", err, h.dock.calls)
	}

	// The image behind the pinned digest differing from the container's recreates it too.
	h.dock.calls = nil
	h.dock.imageIDs[pinned] = "sha256:img2"
	if _, err := h.db.InstanceUpdate(context.Background(), p, stream()); err != nil || !h.dock.called("create") {
		t.Fatalf("new image: %v %v", err, h.dock.calls)
	}
}

func TestInstanceCreateRefusesAnotherDigest(t *testing.T) {
	h := newHarness(t)
	h.dock.images[pinned] = []string{"ghcr.io/falak-sh/falak-postgres@sha256:" + strings.Repeat("b", 64)}
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err == nil || !strings.Contains(err.Error(), "pinned digest") {
		t.Errorf("digest mismatch: %v", err)
	}
	p := createPayload()
	p.Instance.Digest = ""
	if _, err := newHarness(t).db.InstanceCreate(context.Background(), p, stream()); !commands.IsPayloadError(err) {
		t.Errorf("unpinned image accepted: %v", err)
	}
}

func TestInstanceCreateWaitsForTheVolumeAndFailsUnhealthy(t *testing.T) {
	h := newHarness(t)
	h.db.d.Mounted = func(string) bool { return false }
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err == nil || !strings.Contains(err.Error(), "not mounted") {
		t.Fatalf("unmounted volume: %v", err)
	}
	h = newHarness(t)
	h.dock.health = "unhealthy"
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err == nil || !strings.Contains(err.Error(), "not healthy") {
		t.Fatalf("unhealthy: %v", err)
	}
}

func TestPublishedPortsAreFiltered(t *testing.T) {
	h := newHarness(t)
	p := createPayload()
	p.Instance.Publish = &Publish{Addresses: []string{"10.0.0.5"}, AllowedSources: []string{"10.0.0.7/32", "10.90.0.0/24"}}
	if _, err := h.db.InstanceCreate(context.Background(), p, stream()); err != nil {
		t.Fatal(err)
	}
	chain := "FALAK-DB-" + instID[10:]
	lines := strings.Join(h.run.Lines(), "\n")
	for _, want := range []string{
		"iptables -w -N " + chain,
		"iptables -w -F " + chain,
		"iptables -w -A " + chain + " -p tcp -m conntrack --ctdir ORIGINAL --ctorigdstport 20001 --ctorigdst 10.0.0.5 -s 10.0.0.7/32 -j RETURN",
		"iptables -w -A " + chain + " -p tcp -m conntrack --ctdir ORIGINAL --ctorigdstport 20001 --ctorigdst 10.0.0.5 -s 10.90.0.0/24 -j RETURN",
		"iptables -w -A " + chain + " -p tcp -m conntrack --ctdir ORIGINAL --ctorigdstport 20001 --ctorigdst 10.0.0.5 -j DROP",
		"iptables -w -C DOCKER-USER -j " + chain,
	} {
		if !strings.Contains(lines, want) {
			t.Errorf("missing %q in\n%s", want, lines)
		}
	}
	// The jump is added once when missing.
	h2 := newHarness(t)
	h2.run.On("iptables -w -C DOCKER-USER", runner.Result{ExitCode: 1})
	p.Instance.Publish = &Publish{Public: true}
	if _, err := h2.db.InstanceCreate(context.Background(), p, stream()); err != nil {
		t.Fatal(err)
	}
	lines = strings.Join(h2.run.Lines(), "\n")
	if !strings.Contains(lines, "iptables -w -I DOCKER-USER -j "+chain) || !strings.Contains(lines, "-A "+chain+" -p tcp -m conntrack --ctdir ORIGINAL --ctorigdstport 20001 -j DROP") {
		t.Errorf("public without sources: everything dropped:\n%s", lines)
	}
	// Loopback only: no chain; deleting the instance removes it.
	h.run.Reset()
	if _, err := h.db.InstanceDelete(context.Background(), IDPayload{ID: instID}, stream()); err != nil {
		t.Fatal(err)
	}
	if !h.run.Ran("iptables -w -X " + chain) {
		t.Errorf("chain kept: %v", h.run.Lines())
	}
}

func TestCommandsOfOneInstanceRunOneAtATime(t *testing.T) {
	h := newHarness(t)
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	var running, most atomic.Int32
	h.run.OnFunc("docker exec", func(runnertest.Call) (runner.Result, error) {
		n := running.Add(1)
		for m := most.Load(); n > m && !most.CompareAndSwap(m, n); m = most.Load() {
		}
		time.Sleep(20 * time.Millisecond)
		running.Add(-1)
		return runner.Result{Stdout: []byte(`{"changed":true}`)}, nil
	})
	var wg sync.WaitGroup
	for i := range 4 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			_, _ = h.db.Create(context.Background(), CreatePayload{Instance: instID, Engine: "postgres", Name: "db" + string(rune('a'+i))}, stream())
		}()
	}
	wg.Wait()
	if most.Load() != 1 {
		t.Errorf("%d commands of one instance ran at once", most.Load())
	}
}

func TestInstancePasswordRotatesThroughAFile(t *testing.T) {
	h := newHarness(t)
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	var seen string
	h.run.OnFunc("docker exec", func(c runnertest.Call) (runner.Result, error) {
		b, _ := os.ReadFile(h.path("/run/falak/secrets/falak-db-" + instID + "/.password.new"))
		seen = string(b)
		return runner.Result{Stdout: []byte(`{"changed":true}`)}, nil
	})
	h.run.Reset()
	if _, err := h.db.InstancePassword(context.Background(), PasswordPayload{ID: instID, Engine: "postgres", Password: "n3w"}, stream()); err != nil {
		t.Fatal(err)
	}
	line := h.run.Lines()[0]
	if line != "docker exec falak-db-"+instID+" falak-db password set --file /run/secrets/.password.new" || seen != "n3w" {
		t.Errorf("exec %q, file %q", line, seen)
	}
	pw, _ := os.ReadFile(h.path("/run/falak/secrets/falak-db-" + instID + "/password"))
	if string(pw) != "n3w" {
		t.Errorf("password file %q", pw)
	}
	if _, err := os.Stat(h.path("/run/falak/secrets/falak-db-" + instID + "/.password.new")); err == nil {
		t.Error(".password.new left behind")
	}
	// A retry of the same rotation is done already.
	h.run.Reset()
	if res, err := h.db.InstancePassword(context.Background(), PasswordPayload{ID: instID, Engine: "postgres", Password: "n3w"}, stream()); err != nil || res.(ChangedResult).Changed || len(h.run.Lines()) != 0 {
		t.Errorf("retry %+v %v %v", res, err, h.run.Lines())
	}

	// falak-db failing keeps the current password file.
	h2 := newHarness(t)
	h2.db.InstanceCreate(context.Background(), createPayload(), stream())
	h2.run.OnFunc("docker exec", func(runnertest.Call) (runner.Result, error) {
		return runner.Result{ExitCode: 1, Stderr: []byte("falak-db: no")}, nil
	})
	if _, err := h2.db.InstancePassword(context.Background(), PasswordPayload{ID: instID, Engine: "postgres", Password: "other"}, stream()); err == nil {
		t.Fatal("a failed rotation succeeded")
	}
	if pw, _ := os.ReadFile(h2.path("/run/falak/secrets/falak-db-" + instID + "/password")); string(pw) != secret {
		t.Errorf("password file after a failure %q", pw)
	}
}

func TestRedisPasswordsOverlapUntilRetired(t *testing.T) {
	h := newHarness(t)
	p := createPayload()
	p.Instance.Engine, p.Instance.Version, p.Instance.Image = "redis", "8", "ghcr.io/falak-sh/falak-redis:8"
	if _, err := h.db.InstanceCreate(context.Background(), p, stream()); err != nil {
		t.Fatal(err)
	}
	dir := h.path("/run/falak/secrets/falak-db-" + instID)
	h.run.Reset()
	if _, err := h.db.InstancePassword(context.Background(), PasswordPayload{ID: instID, Engine: "redis", Password: "n3w", Mode: "add"}, stream()); err != nil {
		t.Fatal(err)
	}
	if !h.run.Ran("docker exec falak-db-" + instID + " falak-db password set --file /run/secrets/.password.new --keep-current") {
		t.Errorf("exec %v", h.run.Lines())
	}
	cur, _ := os.ReadFile(dir + "/password")
	prev, _ := os.ReadFile(dir + "/password.previous")
	if string(cur) != "n3w" || string(prev) != secret {
		t.Errorf("files %q %q", cur, prev)
	}
	h.run.Reset()
	if _, err := h.db.InstancePassword(context.Background(), PasswordPayload{ID: instID, Engine: "redis", Mode: "retire"}, stream()); err != nil {
		t.Fatal(err)
	}
	if !h.run.Ran("docker exec falak-db-"+instID+" falak-db password set --file /run/secrets/password") || fileExists(dir+"/password.previous") {
		t.Errorf("retire %v", h.run.Lines())
	}
	if err := (PasswordPayload{ID: instID, Engine: "postgres", Password: "x", Mode: "add"}).validate(); !commands.IsPayloadError(err) {
		t.Errorf("add for postgres: %v", err)
	}
}

func fileExists(p string) bool { _, err := os.Stat(p); return err == nil }

func TestInstanceSecretsAfterAReboot(t *testing.T) {
	h := newHarness(t)
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	dir := h.path("/run/falak/secrets/falak-db-" + instID)
	os.Chmod(dir, 0o700)
	os.RemoveAll(dir)
	h.dock.containers["falak-db-"+instID].State.Running = false
	if r := h.db.Report(context.Background()); len(r) != 1 || !r[0].SecretsMissing || r[0].ID != instID {
		t.Fatalf("report %+v", r)
	}
	res, err := h.db.InstanceSecrets(context.Background(), PasswordPayload{ID: instID, Engine: "postgres", Password: secret}, stream())
	if err != nil || !res.(SecretsResult).Restored || !res.(SecretsResult).Started {
		t.Fatalf("%+v %v", res, err)
	}
	if pw, _ := os.ReadFile(dir + "/password"); string(pw) != secret {
		t.Errorf("password %q", pw)
	}
	// Present: left alone.
	res, _ = h.db.InstanceSecrets(context.Background(), PasswordPayload{ID: instID, Engine: "postgres", Password: "other"}, stream())
	if res.(SecretsResult).Restored {
		t.Error("a present secrets directory was rewritten")
	}
	if r := h.db.Report(context.Background()); r[0].SecretsMissing || r[0].State != "running" || r[0].Health != "healthy" {
		t.Errorf("report %+v", r)
	}
}

func TestInstanceRestartStopDelete(t *testing.T) {
	h := newHarness(t)
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	if res, err := h.db.InstanceRestart(context.Background(), IDPayload{ID: instID}, stream()); err != nil || res.(ChangedResult).Health != "healthy" {
		t.Fatalf("restart %+v %v", res, err)
	}
	if res, err := h.db.InstanceStop(context.Background(), IDPayload{ID: instID}, stream()); err != nil || !res.(ChangedResult).Changed {
		t.Fatalf("stop %+v %v", res, err)
	}
	res, err := h.db.InstanceDelete(context.Background(), IDPayload{ID: instID}, stream())
	if err != nil || !res.(ChangedResult).Changed || h.dock.containers["falak-db-"+instID] != nil {
		t.Fatalf("delete %+v %v", res, err)
	}
	for _, p := range []string{"/run/falak/secrets/falak-db-" + instID, "/etc/falak/db/" + instID} {
		if _, err := os.Stat(h.path(p)); err == nil {
			t.Errorf("%s left behind", p)
		}
	}
	if _, err := os.Stat(h.path("/var/lib/falak/volumes/" + volID + "/data")); err != nil {
		t.Error("the data volume was touched")
	}
	// Never created: nothing to do.
	if res, err := h.db.InstanceDelete(context.Background(), IDPayload{ID: instID2}, stream()); err != nil || res.(ChangedResult).Changed {
		t.Errorf("delete of nothing %+v %v", res, err)
	}
	if res, err := h.db.InstanceStop(context.Background(), IDPayload{ID: instID2}, stream()); err != nil || res.(ChangedResult).Changed {
		t.Errorf("stop of nothing %+v %v", res, err)
	}
}

func upgradeHarness(t *testing.T) (*harness, UpgradePayload) {
	h := newHarness(t)
	ctx := context.Background()
	if _, err := h.db.InstanceCreate(ctx, createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	target := createPayload()
	target.Instance.ID, target.Instance.Version, target.Instance.Image = instID2, "18", "ghcr.io/falak-sh/falak-postgres:18"
	if _, err := h.db.InstanceCreate(ctx, target, stream()); err != nil {
		t.Fatal(err)
	}
	h.run.Reset()
	return h, UpgradePayload{Mode: "major", Source: UpgradeEnd{instID, "postgres"}, Target: UpgradeEnd{instID2, "postgres"},
		Databases: []UpgradeDatabase{{Name: "app", Owner: "app"}}, Users: []UserSpec{{Username: "app", Password: "u-pw", Grants: []Grant{{Database: "app"}}}},
		Network: spec().Network, Alias: "falak-db-" + instID}
}

func TestInstanceUpgradeCopiesReadOnlyChecksCountsThenMovesTheAlias(t *testing.T) {
	h, p := upgradeHarness(t)
	ctx := context.Background()
	var restored, specSeen string
	h.run.OnFunc("docker exec falak-db-"+instID+" falak-db backup", func(runnertest.Call) (runner.Result, error) {
		return runner.Result{Stdout: []byte("PGDMP-data"), Stderr: []byte(`falak-db-result: {"bytes":10}` + "\n")}, nil
	})
	h.run.OnFunc("docker exec -i falak-db-"+instID2+" falak-db restore", func(c runnertest.Call) (runner.Result, error) {
		restored = c.Stdin
		return runner.Result{Stdout: []byte(`{}`)}, nil
	})
	h.run.OnFunc("docker exec falak-db-"+instID2+" falak-db user apply", func(c runnertest.Call) (runner.Result, error) {
		path := c.Args[len(c.Args)-1]
		b, _ := os.ReadFile(h.path("/run/falak/secrets/falak-db-" + instID2 + strings.TrimPrefix(path, "/run/secrets")))
		specSeen = string(b)
		return runner.Result{Stdout: []byte(`{"changed":true}`)}, nil
	})
	h.run.On("docker exec falak-db-"+instID+" falak-db table-counts", runner.Result{Stdout: []byte(`{"tables":{"public.items":1000,"public.users":3}}`)})
	h.run.On("docker exec falak-db-"+instID2+" falak-db table-counts", runner.Result{Stdout: []byte(`{"tables":{"public.items":1000,"public.users":3}}`)})
	res, err := h.db.InstanceUpgrade(ctx, p, stream())
	if err != nil {
		t.Fatal(err)
	}
	r := res.(UpgradeResult)
	if len(r.Databases) != 1 || r.Databases[0].Bytes != 10 || r.Databases[0].Rows != 1003 || r.Databases[0].Tables != 2 || restored != "PGDMP-data" {
		t.Errorf("result %+v restored %q", r, restored)
	}
	if !strings.Contains(specSeen, `"password":"u-pw"`) {
		t.Errorf("user spec %q", specSeen)
	}
	lines := h.run.Lines()
	all := strings.Join(lines, "\n")
	if strings.Contains(all, "u-pw") {
		t.Error("a password is in argv")
	}
	// In order: databases, users, read-only source, copy (owned by the app user), counts.
	order := []string{
		"falak-db-" + instID2 + " falak-db database create --name app",
		"falak-db-" + instID2 + " falak-db user apply --spec /run/secrets/.spec-",
		"falak-db-" + instID + " falak-db readonly on",
		"falak-db restore logical --database app --in - --owner app",
		"falak-db-" + instID + " falak-db table-counts --database app",
	}
	at := 0
	for _, want := range order {
		i := strings.Index(all[at:], want)
		if i < 0 {
			t.Fatalf("missing (in order) %q in\n%s", want, all)
		}
		at += i
	}
	if strings.Contains(all, "readonly off") {
		t.Error("the retired source was made writable again")
	}
	if got := h.dock.networks[spec().Network]["falak-db-"+instID2]; !slices.Equal(got, []string{"falak-db-" + instID, "falak-db-" + instID2}) {
		t.Errorf("target aliases %v", got)
	}
	if !h.dock.called("disconnect "+spec().Network+" falak-db-"+instID) || !h.dock.called("stop falak-db-"+instID+" 1m0s") {
		t.Errorf("source not retired: %v", h.dock.calls)
	}
	p.Source.Engine = "redis"
	if _, err := h.db.InstanceUpgrade(ctx, p, stream()); !commands.IsPayloadError(err) {
		t.Errorf("key-value upgrade accepted: %v", err)
	}
}

func TestInstanceUpgradeWithDifferentCountsLeavesTheSourceServing(t *testing.T) {
	h, p := upgradeHarness(t)
	h.run.On("docker exec falak-db-"+instID+" falak-db table-counts", runner.Result{Stdout: []byte(`{"tables":{"public.items":1000}}`)})
	h.run.On("docker exec falak-db-"+instID2+" falak-db table-counts", runner.Result{Stdout: []byte(`{"tables":{"public.items":999}}`)})
	_, err := h.db.InstanceUpgrade(context.Background(), p, stream())
	if err == nil || !strings.Contains(err.Error(), "public.items: 1000 rows, copied 999") {
		t.Fatalf("mismatch: %v", err)
	}
	if !h.run.Ran("docker exec falak-db-" + instID + " falak-db readonly off") {
		t.Errorf("source left read-only: %v", h.run.Lines())
	}
	if h.dock.called("stop falak-db-"+instID) || h.dock.called("disconnect "+spec().Network+" falak-db-"+instID) {
		t.Errorf("source stopped or moved after a failed copy: %v", h.dock.calls)
	}
}
