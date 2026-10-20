package agent

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
	"time"

	"github.com/santhosh-tekuri/jsonschema/v6"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/db"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/facts"
	"github.com/OthmanHaba/falak/agent/internal/fngateway"
	"github.com/OthmanHaba/falak/agent/internal/functions"
	"github.com/OthmanHaba/falak/agent/internal/metrics"
	"github.com/OthmanHaba/falak/agent/internal/resources"
	"github.com/OthmanHaba/falak/agent/internal/sandbox"
	"github.com/OthmanHaba/falak/agent/internal/security"
	"github.com/OthmanHaba/falak/agent/internal/transport"
	"github.com/OthmanHaba/falak/agent/internal/volumes"
)

// Catalogue is the v1 command catalogue from ARCHITECTURE.md §3.
var Catalogue = []string{
	"system.facts", "system.exec", "system.write_file", "system.package.install", "system.user.create", "system.ssh_key.sync", "system.upgrade_agent",
	"provision.apply", "provision.inspect",
	"runtime.php.install", "runtime.php.configure", "runtime.node.install", "runtime.bun.install", "runtime.deno.install", "runtime.frankenphp.configure", "runtime.fpm.pool",
	"edge.caddy.apply", "edge.cert.install",
	"deploy.fetch", "deploy.prepare", "deploy.hook", "deploy.activate", "deploy.rollback", "deploy.prune", "deploy.container.swap", "site.env.write",
	"proc.apply", "proc.restart", "proc.status",
	"cron.apply",
	"db.instance.create", "db.instance.update", "db.instance.restart", "db.instance.stop", "db.instance.delete", "db.instance.password", "db.instance.secrets", "db.instance.upgrade",
	"db.create", "db.drop", "db.user.apply", "db.backup", "db.restore", "db.drill", "db.pitr.base", "db.pitr.restore", "db.pitr.promote",
	"net.firewall.apply", "net.wireguard.apply", "net.tunnel.apply", "net.tailscale.apply", "net.connections.configure", "net.tool", "net.protection.apply", "net.capture", "net.ebpf.configure",
	"fn.release.apply", "fn.release.remove", "fn.run", "fn.status",
	"docker.pull", "docker.run", "docker.stop", "docker.compose.up", "docker.compose.down", "docker.compose.pull", "docker.compose.ps", "docker.compose.restart", "docker.prune", "docker.update",
	"telemetry.configure",
	"volume.create", "volume.resize", "volume.delete", "volume.inventory", "volume.archive", "volume.restore", "volume.clone", "volume.browse", "volume.download", "volume.drill",
	"terminal.open", "terminal.input", "terminal.resize", "terminal.close",
	"security.audit", "security.fix", "security.undo",
	"sandbox.prepare", "sandbox.create", "sandbox.exec", "sandbox.files.read", "sandbox.files.list", "sandbox.files.write", "sandbox.files.delete",
	"sandbox.extend", "sandbox.kill", "sandbox.inventory",
	"sandbox.snapshot", "sandbox.snapshot.delete", "sandbox.pause", "sandbox.resume", "sandbox.db.import",
}

const (
	contractsDir = "../../../contracts/agent-protocol"
	examplesDir  = "../../testdata/command-examples"
	idBase       = "https://falak.sh/agent-protocol/"
)

func compiler(t *testing.T) *jsonschema.Compiler {
	t.Helper()
	c := jsonschema.NewCompiler()
	c.DefaultDraft(jsonschema.Draft2020)
	c.AssertFormat()
	add := func(path, id string) {
		f, err := os.Open(path)
		if err != nil {
			t.Fatal(err)
		}
		defer f.Close()
		doc, err := jsonschema.UnmarshalJSON(f)
		if err != nil {
			t.Fatalf("%s: %v", path, err)
		}
		if err := c.AddResource(id, doc); err != nil {
			t.Fatalf("%s: %v", path, err)
		}
	}
	top, _ := filepath.Glob(filepath.Join(contractsDir, "*.schema.json"))
	for _, p := range top {
		add(p, idBase+filepath.Base(p))
	}
	cmds, _ := filepath.Glob(filepath.Join(contractsDir, "commands", "*.schema.json"))
	for _, p := range cmds {
		add(p, idBase+"commands/"+filepath.Base(p))
	}
	return c
}

func load(t *testing.T, path string) any {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	v, err := jsonschema.UnmarshalJSON(bytes.NewReader(b))
	if err != nil {
		t.Fatalf("%s: %v", path, err)
	}
	return v
}

func TestCatalogueSchemasRegistryAndExamplesAgree(t *testing.T) {
	files, _ := filepath.Glob(filepath.Join(contractsDir, "commands", "*.schema.json"))
	var schemaTypes []string
	for _, f := range files {
		schemaTypes = append(schemaTypes, strings.TrimSuffix(filepath.Base(f), ".schema.json"))
	}
	want := append([]string(nil), Catalogue...)
	sort.Strings(want)
	sort.Strings(schemaTypes)
	if strings.Join(schemaTypes, ",") != strings.Join(want, ",") {
		t.Fatalf("schema files != catalogue\n files: %v\n want:  %v", schemaTypes, want)
	}
	reg, cleanup := buildTestRegistry(t)
	defer cleanup()
	if got := reg.Types(); strings.Join(got, ",") != strings.Join(want, ",") {
		t.Fatalf("registered executors != catalogue\n got:  %v\n want: %v", got, want)
	}
}

func TestEveryExampleValidatesAndDecodes(t *testing.T) {
	c := compiler(t)
	reg, cleanup := buildTestRegistry(t)
	defer cleanup()
	for _, typ := range Catalogue {
		t.Run(typ, func(t *testing.T) {
			id := idBase + "commands/" + typ + ".schema.json"
			sch, err := c.Compile(id)
			if err != nil {
				t.Fatalf("schema does not compile: %v", err)
			}
			if raw, _ := os.ReadFile(filepath.Join(contractsDir, "commands", typ+".schema.json")); bytes.Contains(raw, []byte(`"result"`)) {
				if _, err := c.Compile(id + "#/$defs/result"); err != nil {
					t.Fatalf("$defs.result does not compile: %v", err)
				}
			}
			path := filepath.Join(examplesDir, typ+".json")
			if err := sch.Validate(load(t, path)); err != nil {
				t.Fatalf("example %s invalid: %v", path, err)
			}
			raw, _ := os.ReadFile(path)
			ex, ok := reg.Get(typ)
			if !ok {
				t.Fatalf("no executor")
			}
			pc, ok := ex.(commands.PayloadChecker)
			if !ok {
				t.Fatalf("executor for %s is not built with commands.Typed (cannot check payload decoding)", typ)
			}
			if err := pc.CheckPayload(raw); err != nil {
				t.Fatalf("example does not decode into the executor payload type: %v", err)
			}
			// The envelope carrying it must be valid too.
			env, _ := json.Marshal(commands.Envelope{ID: "01J9Z8Y7X6W5V4T3S2R1Q0P9NA", Type: typ, TimeoutS: 600, IdempotencyKey: "k", Payload: raw})
			es, err := c.Compile(idBase + "envelope.schema.json")
			if err != nil {
				t.Fatal(err)
			}
			v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(env))
			if err := es.Validate(v); err != nil {
				t.Fatalf("envelope invalid: %v", err)
			}
		})
	}
}

func TestSchemasRejectInvalidPayloads(t *testing.T) {
	c := compiler(t)
	bad := map[string]string{
		"deploy.fetch":           `{"site":"shop","release_id":"x","artifact":{"url":"http://x","sha256":"nope"}}`,
		"proc.apply":             `{"programs":[{"name":"w","command":[],"restart":"sometimes"}]}`,
		"edge.caddy.apply":       `{"sites":[{"id":"a","domains":[],"kind":"php_fpm"}]}`,
		"net.firewall.apply":     `{"rules":[{"id":"x","ports":["http"]}]}`,
		"net.protection.apply":   `{"blocks":[{"cidr":"1.2.3.4; flush ruleset"}]}`,
		"system.exec":            `{"script":"true","unexpected":1}`,
		"docker.compose.up":      `{"project":"shop","directory":"/srv/x","project_env_file":"../.env"}`,
		"docker.compose.ps":      `{"project":"Shop!"}`,
		"docker.compose.restart": `{"project":"shop","services":["a b"]}`,
		"docker.compose.pull":    `{"project":"shop"}`,
		"docker.update":          `{"site":"shop","project":"shop","service":"app","memory_bytes":1}`,
		"runtime.fpm.pool":       `{"php_version":"8.4","pool":"shop","user":"shop","slice":"site-shop"}`,
		"docker.run":             `{"name":"web","image":"nginx","log":{"max_size_mb":0}}`,
		"fn.release.apply":       `{"site":"hello","release":"r1","image":"i","entrypoint":"../index.ts","files":[{"path":"../index.ts","content":""}]}`,
		"fn.status":              `{"site":"Hello World"}`,
		"fn.run":                 `{"site":"hello","schedule":"../x"}`,
		"volume.browse":          `{"volume":{"id":"01j9z8y7x6w5v4t3s2r1q0p9na","kind":"sized"},"path":"/etc"}`,
		"volume.create":          `{"volume":{"id":"01j9z8y7x6w5v4t3s2r1q0p9na","kind":"sized"}}`,
		"volume.delete":          `{"volume":{"id":"01j9z8y7x6w5v4t3s2r1q0p9na","kind":"docker"}}`,
		"volume.archive":         `{"volume":{"id":"01J9Z8Y7X6W5V4T3S2R1Q0P9NA","kind":"sized"},"destination":{"kind":"presigned_url","url":"https://s3.example.com/k"}}`,
		"volume.download":        `{"volume":{"id":"01j9z8y7x6w5v4t3s2r1q0p9na","kind":"bind","path":"/srv/data"},"destination":{"kind":"presigned_url","url":"http://s3.example.com/k"},"max_bytes":1}`,
		"volume.resize":          `{"volume":{"id":"01j9z8y7x6w5v4t3s2r1q0p9na","kind":"sized"},"size_bytes":1024}`,
		"volume.restore":         `{"volume":{"id":"01j9z8y7x6w5v4t3s2r1q0p9na","kind":"sized"},"size_bytes":16777216,"source":{"kind":"url","url":"https://s3.example.com/k"},"sha256":"9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08","archive_bytes":0}`,
		"volume.clone":           `{"source":{"id":"01j9z8y7x6w5v4t3s2r1q0p9na","kind":"sized"},"target":{"id":"01j9z8y7x6w5v4t3s2r1q0p9nb","kind":"docker"}}`,
		"security.audit":         `{"expected_ports":["tcp/http"]}`,
		"security.fix":           `{"fix_id":"sh -c reboot"}`,
		"net.tool":               `{"tool":"ping","target":"-oProxyCommand=x"}`,
		"net.capture":            `{"capture_id":"01JA2B3C4D5E6F7G8H9J0K1M2N","filter":{"host":"-w /etc/x"}}`,
		"security.undo":          `{"fix_id":"ssh.harden","backup_id":"../../../etc"}`,
	}
	for typ, payload := range bad {
		sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json")
		if err != nil {
			t.Fatal(err)
		}
		v, _ := jsonschema.UnmarshalJSON(strings.NewReader(payload))
		if sch.Validate(v) == nil {
			t.Errorf("%s: invalid payload accepted", typ)
		}
	}
}

// terminal.open's terminal.v2 fields: the schema and the executor's decoding agree.
func TestTerminalOpenV2Payloads(t *testing.T) {
	c := compiler(t)
	sch, err := c.Compile(idBase + "commands/terminal.open.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	reg, cleanup := buildTestRegistry(t)
	defer cleanup()
	ex, _ := reg.Get("terminal.open")
	tok := `"relay_token":"eyJ2IjoxfQ.c2lnbmF0dXJlLXNpZ25hdHVyZQ"` // gitleaks:allow (fake token)
	for _, p := range []string{
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W",` + tok + `}`,
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W",` + tok + `,"container":{"name":"falak-db-01jdb000000000000000000000"}}`,
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W","env":{"APP_ENV":"production"},"container":{"labels":{"com.docker.compose.project":"shop","com.docker.compose.service":"web"},"user":"www-data:www-data","workdir":"/app"}}`,
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W",` + tok + `,"sandbox":{"id":"01j9z8y7x6w5v4t3s2r1q0p9na"}}`,
	} {
		v, _ := jsonschema.UnmarshalJSON(strings.NewReader(p))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s: %v", p, err)
		}
		if err := ex.(commands.PayloadChecker).CheckPayload([]byte(p)); err != nil {
			t.Errorf("%s does not decode: %v", p, err)
		}
	}
	for _, p := range []string{
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W","relay_token":"short"}`,
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W","container":{}}`,
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W","container":{"name":"web","labels":{"falak.site":"shop"}}}`,
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W","container":{"name":"-web"}}`,
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W","container":{"labels":{"falak.site":"a b"}}}`,
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W","container":{"name":"web","user":"root -v"}}`,
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W","container":{"name":"web","workdir":"app"}}`,
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W","container":{"name":"web","image":"x"}}`,
		`{"session_id":"01J9ZT8K3M4N5P6Q7R8S9T0V1W","sandbox":{"id":"NOT-AN-ID"}}`,
	} {
		v, _ := jsonschema.UnmarshalJSON(strings.NewReader(p))
		if sch.Validate(v) == nil {
			t.Errorf("%s: invalid payload accepted", p)
		}
	}
}

// Results of the compose executors validate against their schemas' $defs.result.
func TestComposeResultsValidate(t *testing.T) {
	c := compiler(t)
	cpu, mem := 12.5, int64(64<<20)
	code := 137
	svc := docker.ServiceStatus{Service: "app", ContainerID: "abc", ContainerName: "shop-app-1", State: "exited", ExitCode: &code, Health: "unhealthy",
		Image: "registry.falak.local/falak/shop/app@sha256:" + strings.Repeat("a", 64), ImageDigest: "sha256:" + strings.Repeat("a", 64),
		Ports: []docker.PortStatus{{HostIP: "127.0.0.1", HostPort: 3001, ContainerPort: 8080, Protocol: "tcp"}}, Restarts: 3,
		StartedAt: "2026-09-27T10:00:00Z", CPUPercent: &cpu, MemoryBytes: &mem, MemoryLimit: &mem}
	for typ, res := range map[string]any{
		"docker.compose.up":      docker.ComposeUpResult{ExitCode: 1, Services: []docker.ServiceStatus{svc}},
		"docker.compose.ps":      docker.ComposePsResult{Services: []docker.ServiceStatus{svc}},
		"docker.compose.restart": docker.ComposeRestartResult{Restarted: []string{"app"}},
		"docker.compose.pull":    docker.ExitResult{ExitCode: 0},
		"docker.update":          docker.UpdateResult{Changed: true, Containers: []string{"falak-shop-blue"}},
	} {
		sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json#/$defs/result")
		if err != nil {
			t.Fatal(err)
		}
		b, _ := json.Marshal(res)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s result invalid: %v\n%s", typ, err, b)
		}
	}
}

func TestProtocolDocumentsValidate(t *testing.T) {
	c := compiler(t)
	evs, _ := c.Compile(idBase + "event.schema.json")
	p := 0.5
	code := 0
	for _, ev := range []commands.Event{
		{CommandID: "c", Seq: 0, Kind: commands.KindStarted, At: time.Now()},
		{CommandID: "c", Seq: 1, Kind: commands.KindOutput, At: time.Now(), Stream: "stdout", Data: "hi"},
		{CommandID: "c", Seq: 2, Kind: commands.KindProgress, At: time.Now(), Progress: &p},
		{CommandID: "c", Seq: 3, Kind: commands.KindFinished, At: time.Now(), ExitCode: &code, Result: map[string]any{"changed": true}},
	} {
		b, _ := json.Marshal(ev)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := evs.Validate(v); err != nil {
			t.Fatalf("event %s invalid: %v", ev.Kind, err)
		}
	}
	hbs, _ := c.Compile(idBase + "heartbeat.schema.json")
	b, _ := json.Marshal(transport.Heartbeat{At: time.Now(), UptimeS: 5, Load: [3]float64{0.1, 0.2, 0.3}, RunningCommands: []string{}})
	v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
	if err := hbs.Validate(v); err != nil {
		t.Fatalf("heartbeat invalid: %v", err)
	}
	// With OOM kills and restarts.
	var q resources.Queue
	q.Add(resources.Event{Kind: resources.KindOOMKill, Source: resources.SourceContainer, Name: "falak-shop-blue", Site: "shop", Count: 1})
	q.Add(resources.Event{Kind: resources.KindRestart, Source: resources.SourceContainer, Name: "stack-db-1", Project: "stack", Service: "db", Count: 3})
	q.Add(resources.Event{Kind: resources.KindOOMKill, Source: resources.SourceSlice, Name: "worker_01j9z8y7x6w5v4t3s2r1q0p9na", Count: 2})
	q.Add(resources.Event{Kind: resources.KindRestart, Source: resources.SourceProgram, Name: "shop.worker-01j9z8y7x6w5v4t3s2r1q0p9na", Site: "shop", Count: 1})
	q.Add(resources.Event{Kind: resources.KindOOMKill, Source: resources.SourceContainer, Name: "falak-db-01hzyinst00000000000000001", Instance: "01hzyinst00000000000000001", Count: 1})
	ev, _ := q.Pending()
	b, _ = json.Marshal(transport.Heartbeat{At: time.Now(), UptimeS: 5, Load: [3]float64{0.1, 0.2, 0.3}, RunningCommands: []string{}, ServiceEvents: ev})
	v, _ = jsonschema.UnmarshalJSON(bytes.NewReader(b))
	if err := hbs.Validate(v); err != nil {
		t.Fatalf("heartbeat with service events invalid: %v\n%s", err, b)
	}
}

// Results of the fn.* executors validate against their schemas' $defs.result.
func TestFunctionResultsValidate(t *testing.T) {
	c := compiler(t)
	at := time.Date(2026, 9, 30, 12, 0, 0, 0, time.UTC)
	for typ, res := range map[string]any{
		"fn.release.apply":  functions.ApplyResult{Release: "r2", PreviousRelease: "r1", Installed: true, BootMS: 240},
		"fn.release.remove": map[string]bool{"removed": true},
		"fn.run":            functions.RunResult{ExitCode: 0, DurationMS: 812},
		"fn.status": functions.StatusResult{Functions: []fngateway.Status{
			{Site: "hello", Release: "r2", Running: 1, InFlight: 3, ColdStarts: 2, Requests: 40, LastRequestAt: &at},
			{Site: "idle", Release: "r1"},
		}},
	} {
		sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json#/$defs/result")
		if err != nil {
			t.Fatal(err)
		}
		b, _ := json.Marshal(res)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s result invalid: %v\n%s", typ, err, b)
		}
	}
}

// db.* payloads name an instance; SQL commands only take SQL engines, and every db.* result validates.
func TestDatabaseSchemas(t *testing.T) {
	c := compiler(t)
	inst := `"instance":"01hzyinst00000000000000001"`
	dest := `"encryption":{"mode":"cp","key_id":"01hzybackup000000000000001","key":"q6urq6urq6urq6urq6urq6urq6urq6urq6urq6urq6s="},"destination":{"kind":"presigned_url","url":"https://s3.example.com/b/k?X-Amz-Signature=x"}`
	plainDest := `"destination":{"kind":"presigned_url","url":"https://s3.example.com/b/k?X-Amz-Signature=x"}`
	source := `"source":{"kind":"url","url":"https://s3.example.com/b/k"},"sha256":"` + strings.Repeat("a", 64) + `"`
	recipient := `"recipient":"age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p"`
	identity := `"identity":"AGE-SECRET-KEY-1` + strings.Repeat("Q", 58) + `"`
	for _, tc := range []struct {
		typ, body string
		valid     bool
	}{
		{"db.backup", `{` + inst + `,"engine":"redis","database":"cache-1",` + dest + `}`, true},
		{"db.backup", `{` + inst + `,"engine":"mariadb","database":"shop_db",` + dest + `}`, true},
		{"db.backup", `{` + inst + `,"engine":"mysql","database":"shop-db",` + dest + `}`, false},
		{"db.backup", `{"engine":"mysql","database":"shop",` + dest + `}`, false},
		// Always encrypted: no unencrypted backup, and no compression choice.
		{"db.backup", `{` + inst + `,"engine":"mysql","database":"shop",` + plainDest + `}`, false},
		{"db.backup", `{` + inst + `,"engine":"mysql","database":"shop","compression":"gzip",` + dest + `}`, false},
		{"db.backup", `{` + inst + `,"engine":"mysql","database":"shop","encryption":{"mode":"age","key_id":"k",` + recipient + `},` + plainDest + `}`, true},
		{"db.backup", `{` + inst + `,"engine":"mysql","database":"shop","encryption":{"mode":"age","key_id":"k","recipient":"ssh-ed25519 AAAA"},` + plainDest + `}`, false},
		{"db.backup", `{` + inst + `,"engine":"mysql","database":"shop","encryption":{"mode":"age","key_id":"k",` + identity + `},` + plainDest + `}`, false},
		{"db.restore", `{` + inst + `,"engine":"mysql","database":"shop","encryption":{"mode":"age","key_id":"k",` + identity + `},` + source + `}`, true},
		{"db.restore", `{` + inst + `,"engine":"mysql","database":"shop","encryption":{"mode":"age","key_id":"k",` + recipient + `},` + source + `}`, false},
		{"db.restore", `{` + inst + `,"engine":"mysql","database":"shop",` + source + `}`, false},
		{"db.drill", `{"drill":"01hzydrill0000000000000001","instance":{"engine":"postgres","version":"17","image":"i","digest":"sha256:` + strings.Repeat("a", 64) + `","memory_bytes":1024},"database":"shop","encryption":{"mode":"age","key_id":"k",` + identity + `},` + source + `,"checks":{}}`, false},
		{"db.drill", `{"drill":"01hzydrill0000000000000001","instance":{"engine":"postgres","version":"17","image":"i","digest":"sha256:` + strings.Repeat("a", 64) + `","memory_bytes":268435456},"database":"shop","encryption":{"mode":"age","key_id":"k",` + identity + `},` + source + `,"checks":{"tolerance_percent":200}}`, false},
		{"db.create", `{` + inst + `,"engine":"redis","name":"x"}`, false},
		{"db.create", `{"instance":"../x","engine":"postgres","name":"x"}`, false},
		{"db.user.apply", `{` + inst + `,"engine":"postgres","username":"app","remote":true}`, false},
		{"db.instance.create", `{"instance":{"id":"01hzyinst00000000000000001","engine":"postgres","version":"17","image":"ghcr.io/falak-sh/falak-postgres:17","volume_id":"01hzyvol000000000000000001","memory_bytes":1024},"password":"x"}`, false},
		{"db.instance.create", `{"instance":{"id":"01hzyinst00000000000000001","engine":"postgres","version":"17","image":"ghcr.io/falak-sh/falak-postgres:17","volume_id":"01hzyvol000000000000000001","memory_bytes":536870912,"network":"bridge"},"password":"x"}`, false},
		{"db.instance.create", `{"instance":{"id":"01hzyinst00000000000000001","engine":"postgres","version":"17","image":"ghcr.io/falak-sh/falak-postgres:17","volume_id":"01hzyvol000000000000000001","memory_bytes":536870912},"password":""}`, false},
		{"db.instance.upgrade", `{"mode":"minor","source":{"id":"01hzyinst00000000000000001","engine":"postgres"},"target":{"id":"01hzyinst00000000000000002","engine":"postgres"},"databases":[],"alias":"a"}`, false},
		{"db.instance.upgrade", `{"mode":"major","source":{"id":"01hzyinst00000000000000001","engine":"redis"},"target":{"id":"01hzyinst00000000000000002","engine":"redis"},"databases":[],"alias":"a"}`, false},
	} {
		sch, err := c.Compile(idBase + "commands/" + tc.typ + ".schema.json")
		if err != nil {
			t.Fatal(err)
		}
		v, _ := jsonschema.UnmarshalJSON(strings.NewReader(tc.body))
		if err := sch.Validate(v); (err == nil) != tc.valid {
			t.Errorf("%s %s: valid=%v, err=%v", tc.typ, tc.body, tc.valid, err)
		}
	}
	for typ, res := range map[string]any{
		"db.backup": db.BackupResult{SizeBytes: 10, SHA256: strings.Repeat("a", 64), Location: "https://s3.example.com/b/k", DurationMS: 5, RDB: "VALKEY080", UncompressedBytes: 42,
			PlaintextSHA256: strings.Repeat("b", 64), Encryption: "cp", KeyID: "01hzybackup000000000000001", Cipher: "aes-256-gcm", Compression: "zstd", TableCounts: map[string]int64{"db0": 4}},
		"db.drill":             db.DrillResult{Status: "failed", Checks: []db.DrillCheck{{Name: "restore", Passed: true, Detail: "ok"}, {Name: "row_counts", Passed: false}}, DownloadMS: 1, RestoreMS: 2, DurationMS: 3, Tables: 4},
		"db.restore":           db.RestoreResult{Bytes: 10, DurationMS: 5, Warnings: []string{"x"}},
		"db.create":            db.ChangedResult{Changed: true},
		"db.user.apply":        db.ChangedResult{Changed: true},
		"db.instance.create":   db.InstanceResult{Changed: true, ContainerID: "abc", ImageDigest: "sha256:" + strings.Repeat("a", 64), Health: "healthy"},
		"db.instance.update":   db.InstanceResult{ContainerID: "abc", Health: "starting"},
		"db.instance.restart":  db.ChangedResult{Changed: true, Health: "healthy"},
		"db.instance.delete":   db.ChangedResult{},
		"db.instance.password": db.ChangedResult{Changed: true},
		"db.instance.secrets":  db.SecretsResult{Restored: true, Started: true},
		"db.instance.upgrade":  db.UpgradeResult{Databases: []db.CopiedDatabase{{Name: "shop", Bytes: 10}}, DurationMS: 4},
	} {
		sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json#/$defs/result")
		if err != nil {
			t.Fatal(err)
		}
		b, _ := json.Marshal(res)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s result invalid: %v\n%s", typ, err, b)
		}
	}
	// The heartbeat's database report.
	hbs, _ := c.Compile(idBase + "heartbeat.schema.json")
	hb := transport.Heartbeat{At: time.Now(), UptimeS: 5, Load: [3]float64{0.1, 0.2, 0.3}, RunningCommands: []string{},
		Databases: []db.InstanceReport{{ID: "01hzyinst00000000000000001", State: "exited", Health: "none", SecretsMissing: true},
			{ID: "01hzyinst00000000000000002", State: "running", Health: "healthy", Connections: &db.Connections{Used: 85, Max: 100}}},
		Disks: []metrics.DiskUsage{{Mount: "/", UsedBytes: 80, AvailableBytes: 20, TotalBytes: 105}, {Mount: "/mnt/data", UsedBytes: 1, AvailableBytes: 9, TotalBytes: 10}}}
	b, _ := json.Marshal(hb)
	v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
	if err := hbs.Validate(v); err != nil {
		t.Fatalf("heartbeat with databases invalid: %v\n%s", err, b)
	}
}

// Results of the sandbox.* executors validate against their schemas' $defs.result, and sandbox.reaped's body against
// its request schema.
func TestSandboxResultsValidate(t *testing.T) {
	c := compiler(t)
	for typ, res := range map[string]any{
		"sandbox.prepare":      sandbox.PrepareResult{RunscVersion: "release-20261005.0", Platform: "systrap"},
		"sandbox.create":       sandbox.CreateResult{Status: "running", IP: "10.88.0.2", ImageDigest: "sha256:" + strings.Repeat("a", 64), StartedAt: "2026-10-16T19:00:00Z"},
		"sandbox.exec":         sandbox.ExecResult{ExitCode: 0, Stdout: "aGk=", Stderr: "", DurationMS: 70},
		"sandbox.files.read":   sandbox.ReadResult{Content: "aGk=", Size: 2, Mode: "0644"},
		"sandbox.files.list":   sandbox.ListResult{Entries: []sandbox.Entry{{Name: "a", Type: "dir", Size: 4096, Mode: "0755", MTime: 1760000000}}},
		"sandbox.files.write":  sandbox.WriteResult{Size: 2},
		"sandbox.files.delete": struct{}{},
		"sandbox.extend":       sandbox.ExtendResult{ExpiresAt: 1760648400},
		"sandbox.kill":         struct{}{},
		"sandbox.inventory": sandbox.InventoryResult{Sandboxes: []sandbox.InventoryItem{
			{ID: "01j9z8y7x6w5v4t3s2r1q0p9na", Status: "running", StartedAt: "2026-10-16T19:00:00Z", ExpiresAt: 1760644800, MemoryBytes: 17 << 20, CPUSeconds: 1.5},
			{ID: "01j9z8y7x6w5v4t3s2r1q0p9nb", Status: "stopped", OOMKilled: true, ExpiresAt: 1760644800},
			{ID: "01j9z8y7x6w5v4t3s2r1q0p9nc", Status: "creating", ExpiresAt: 1760644800},
			{ID: "01j9z8y7x6w5v4t3s2r1q0p9nd", Status: "paused", StartedAt: "2026-10-16T19:00:00Z", ExpiresAt: 1760644800, MemoryBytes: 17 << 20},
		}, Snapshots: []sandbox.InventorySnapshot{{SnapshotID: "01j9z8y7x6w5v4t3s2r1q0p9sn", SizeBytes: 31457280}}},
		"sandbox.snapshot": sandbox.SnapshotResult{SnapshotID: "01j9z8y7x6w5v4t3s2r1q0p9sn", SizeBytes: 31457280,
			ImageDigest: "sha256:" + strings.Repeat("a", 64), CreatedAt: "2026-10-17T10:00:00Z"},
		"sandbox.snapshot.delete": struct{}{},
		"sandbox.pause":           sandbox.StatusResult{Status: "paused"},
		"sandbox.resume":          sandbox.StatusResult{Status: "running"},
		"sandbox.db.import":       sandbox.DBImportResult{Path: "/workspace/db/shop.dump", SizeBytes: 1094580},
	} {
		sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json#/$defs/result")
		if err != nil {
			t.Fatal(err)
		}
		b, _ := json.Marshal(res)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s result invalid: %v\n%s", typ, err, b)
		}
	}
	// A create from a snapshot (v0.21) is a valid sandbox.create payload, and decodes.
	create, err := c.Compile(idBase + "commands/sandbox.create.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	fromSnapshot := `{"id":"01j9z8y7x6w5v4t3s2r1q0p9nb","image":"node:22","cpus":1,"memory_mb":1024,"disk_mb":10240,"pids":1024,"network":"internet",` +
		`"allow_hosts":[],"env":{"FORK":"1"},"expires_at":1760644800,"from_snapshot":"01j9z8y7x6w5v4t3s2r1q0p9sn"}`
	v, _ := jsonschema.UnmarshalJSON(strings.NewReader(fromSnapshot))
	if err := create.Validate(v); err != nil {
		t.Errorf("sandbox.create from_snapshot invalid: %v", err)
	}
	var p sandbox.CreatePayload
	if err := json.Unmarshal([]byte(fromSnapshot), &p); err != nil || p.FromSnapshot != "01j9z8y7x6w5v4t3s2r1q0p9sn" {
		t.Errorf("from_snapshot decodes to %+v: %v", p, err)
	}
	f, err := os.Open(filepath.Join(contractsDir, "requests", "sandbox.reaped.schema.json"))
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	doc, _ := jsonschema.UnmarshalJSON(f)
	if err := c.AddResource(idBase+"requests/sandbox.reaped.schema.json", doc); err != nil {
		t.Fatal(err)
	}
	sch, err := c.Compile(idBase + "requests/sandbox.reaped.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	for _, reason := range []string{sandbox.ReasonExpired, sandbox.ReasonOOM, sandbox.ReasonExited} {
		v, _ := jsonschema.UnmarshalJSON(strings.NewReader(`{"id":"01j9z8y7x6w5v4t3s2r1q0p9na","reason":"` + reason + `"}`))
		if err := sch.Validate(v); err != nil {
			t.Errorf("sandbox.reaped %s: %v", reason, err)
		}
	}
}

// Results of the volume.* executors validate against their schemas' $defs.result.
func TestVolumeResultsValidate(t *testing.T) {
	c := compiler(t)
	used, size, avail, yes := int64(10), int64(100), int64(90), true
	sha := strings.Repeat("a", 64)
	for typ, res := range map[string]any{
		"volume.create": volumes.CreateResult{Path: "/var/lib/falak/volumes/x", SizeBytes: 1 << 30, Created: true},
		"volume.resize": volumes.ResizeResult{SizeBytes: 2 << 30, PreviousBytes: 1 << 30, Grown: true},
		"volume.delete": volumes.DeleteResult{Deleted: true, Existed: true},
		"volume.inventory": volumes.InventoryResult{Volumes: []volumes.VolumeUsage{
			{ID: "01j9z8y7x6w5v4t3s2r1q0p9na", Kind: "sized", Exists: true, UsedBytes: &used, SizeBytes: &size, AvailableBytes: &avail, Mounted: &yes, Containers: []string{"falak-shop-blue"}},
			{ID: "01j9z8y7x6w5v4t3s2r1q0p9nb", Kind: "docker", Exists: false},
		}, Docker: []volumes.DockerVolume{{Name: "shop_pgdata", Driver: "local", Labels: map[string]string{"a": "b"}, Containers: []string{"shop-db-1"}}}, DurationMS: 3},
		"volume.archive": volumes.ArchiveResult{SizeBytes: 10, SHA256: sha, Location: "https://s3.example.com/k", UncompressedBytes: 20, Files: 2, DurationMS: 1, Containers: []string{"c"},
			PlaintextSHA256: sha, Encryption: "age", KeyID: "k", Cipher: "aes-256-gcm", Compression: "zstd"},
		"volume.drill":    volumes.DrillResult{Status: "skipped", Reason: "no room", Checks: []volumes.DrillCheck{}},
		"volume.restore":  volumes.RestoreResult{Bytes: 20, Files: 2, DurationMS: 1},
		"volume.clone":    volumes.RestoreResult{Bytes: 20, Files: 2, DurationMS: 1, Containers: []string{"c"}},
		"volume.browse":   volumes.BrowseResult{Path: "", Entries: []volumes.Entry{{Name: "a", Path: "a", Type: "dir", Size: 0, MTime: "2026-10-06T12:00:00Z"}}, Total: 1},
		"volume.download": volumes.DownloadResult{SizeBytes: 10, SHA256: sha, Location: "https://s3.example.com/k", Format: "tar.zst", Name: "uploads.tar.zst", Files: 3},
	} {
		sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json#/$defs/result")
		if err != nil {
			t.Fatal(err)
		}
		b, _ := json.Marshal(res)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s result invalid: %v\n%s", typ, err, b)
		}
	}
}

// Results of the security executors validate against their schemas' $defs.result.
func TestSecurityResultsValidate(t *testing.T) {
	c := compiler(t)
	for typ, res := range map[string]any{
		"security.audit": security.AuditResult{Checks: []security.Check{
			{ID: "ssh.password_authentication", Title: "Password logins are off", Area: "ssh", Status: "fail", Severity: "high", Evidence: "PasswordAuthentication yes", FixID: "ssh.harden", Disruptive: true},
			{ID: "firewall.port.tcp.8080", Title: "Port tcp/8080 listens on a public interface", Area: "firewall", Status: "warn", Severity: "low", Evidence: "node", FixID: "firewall.close_port:tcp:8080"},
			{ID: "accounts.unknown_users", Title: "Users with a login shell", Area: "accounts", Status: "info", Severity: "info", Evidence: "ubuntu"},
		}, DurationMS: 1234},
		"security.fix":  security.FixResult{FixID: "kernel.sysctl", Changed: true, BackupID: "20261009T120000Z-1a2b3c4d", Undoable: true, Message: "hardened"},
		"security.undo": security.UndoResult{FixID: "kernel.sysctl", BackupID: "20261009T120000Z-1a2b3c4d", Restored: 3},
	} {
		sch, err := c.Compile(idBase + "commands/" + typ + ".schema.json#/$defs/result")
		if err != nil {
			t.Fatal(err)
		}
		b, _ := json.Marshal(res)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s result invalid: %v\n%s", typ, err, b)
		}
	}
	// Every fix of the allowlist the agent applies fits the payload pattern.
	sch, err := c.Compile(idBase + "commands/security.fix.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	for _, id := range security.FixIDs() {
		v, _ := jsonschema.UnmarshalJSON(strings.NewReader(`{"fix_id":"` + id + `"}`))
		if err := sch.Validate(v); err != nil {
			t.Errorf("fix %s does not fit the schema: %v", id, err)
		}
	}
}

// volume.archive keep_stopped only goes with consistency stop (moves).
func TestVolumeArchiveKeepStoppedNeedsStop(t *testing.T) {
	c := compiler(t)
	sch, err := c.Compile(idBase + "commands/volume.archive.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	base := `{"volume":{"id":"01j9z8y7x6w5v4t3s2r1q0p9na","kind":"sized"},"encryption":{"mode":"cp","key_id":"k","key":"q6urq6urq6urq6urq6urq6urq6urq6urq6urq6urq6s="},"destination":{"kind":"presigned_url","url":"https://s3.example.com/k"}`
	for body, valid := range map[string]bool{
		base + `,"consistency":"stop","keep_stopped":true}`:   true,
		base + `,"consistency":"pause","keep_stopped":true}`:  false,
		base + `,"keep_stopped":true}`:                        false,
		base + `,"consistency":"pause","keep_stopped":false}`: true,
	} {
		v, _ := jsonschema.UnmarshalJSON(strings.NewReader(body))
		if err := sch.Validate(v); (err == nil) != valid {
			t.Errorf("%s: valid=%v, err=%v", body, valid, err)
		}
	}
}

// Facts with a pending reboot and the edge's ACME certificates validate against facts.schema.json.
func TestFactsValidate(t *testing.T) {
	c := compiler(t)
	sch, err := c.Compile(idBase + "facts.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	f := facts.Facts{Hostname: "web-1", OS: facts.OS{ID: "ubuntu", Version: "24.04"}, Arch: "amd64", CPUs: 2, MemoryBytes: 1 << 30, DiskBytes: 1 << 34,
		AgentVersion: "v0.10.0", Runtimes: map[string][]string{}, Features: []string{}, RebootRequired: true, LocalEdge: true,
		TLSCertificates: []facts.TLSCertificate{{Name: "example.com", NotAfter: "2026-12-30T00:00:00Z"}}}
	b, _ := json.Marshal(f)
	v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
	if err := sch.Validate(v); err != nil {
		t.Fatalf("facts invalid: %v\n%s", err, b)
	}
}

// edge.caddy.apply rate limits (edge.rate_limit): the schema and the executor's decoding agree.
func TestEdgeRateLimitPayloads(t *testing.T) {
	c := compiler(t)
	sch, err := c.Compile(idBase + "commands/edge.caddy.apply.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	reg, cleanup := buildTestRegistry(t)
	defer cleanup()
	ex, _ := reg.Get("edge.caddy.apply")
	site := `{"id":"shop","domains":["shop.example.com"],"kind":"static","root":"/srv/shop"`
	sum := strings.Repeat("a", 64)
	for _, p := range []string{
		`{"sites":[` + site + `,"rate_limits":[{"path":"/login","events":10,"window_s":60},{"path":"/api/*","events":600,"window_s":60}]}],"caddy_build":{"url":"https://cp.example.com/install/edge/caddy-linux-amd64","sha256":"` + sum + `"}}`,
	} {
		v, _ := jsonschema.UnmarshalJSON(strings.NewReader(p))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s: %v", p, err)
		}
		if err := ex.(commands.PayloadChecker).CheckPayload([]byte(p)); err != nil {
			t.Errorf("%s does not decode: %v", p, err)
		}
	}
	for _, p := range []string{
		`{"sites":[` + site + `,"rate_limits":[{"path":"login","events":10,"window_s":60}]}]}`,
		`{"sites":[` + site + `,"rate_limits":[{"path":"/{http.request.uri}","events":10,"window_s":60}]}]}`,
		`{"sites":[` + site + `,"rate_limits":[{"path":"/x","events":0,"window_s":60}]}]}`,
		`{"sites":[` + site + `}],"caddy_build":{"url":"http://cp.example.com/x","sha256":"` + sum + `"}}`,
		`{"sites":[` + site + `}],"caddy_build":{"url":"https://cp.example.com/x","sha256":"nope"}}`,
	} {
		v, _ := jsonschema.UnmarshalJSON(strings.NewReader(p))
		if sch.Validate(v) == nil {
			t.Errorf("%s: invalid payload accepted", p)
		}
	}
}
