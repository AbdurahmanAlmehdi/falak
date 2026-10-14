package runtime

import (
	"context"
	"fmt"
	"regexp"
	"sort"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/cgroup"
	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// FPMPoolPayload is runtime.fpm.pool.
type FPMPoolPayload struct {
	PHPVersion      string            `json:"php_version"`
	Pool            string            `json:"pool"`
	User            string            `json:"user"`
	Group           string            `json:"group"`
	Listen          string            `json:"listen"`
	PM              string            `json:"pm"`
	MaxChildren     int               `json:"max_children"`
	StartServers    int               `json:"start_servers"`
	MinSpareServers int               `json:"min_spare_servers"`
	MaxSpareServers int               `json:"max_spare_servers"`
	MaxRequests     *int              `json:"max_requests"`
	PHPAdminValues  map[string]string `json:"php_admin_values"`
	Env             map[string]string `json:"env"`
	State           string            `json:"state"`
	// Slice runs the pool in its own master inside falak-<slice>.slice (the site's limits).
	Slice string `json:"slice"`
	// OomScoreAdj is the own master's OOM preference (OOMScoreAdjust=).
	OomScoreAdj int `json:"oom_score_adj"`
}

// ListenResult is {changed, listen}.
type ListenResult struct {
	Changed bool   `json:"changed"`
	Listen  string `json:"listen,omitempty"`
}

func def(v, d int) int {
	if v == 0 {
		return d
	}
	return v
}

// FPMPoolName is the name of a site's PHP-FPM pool (its section in the pool file): PHP-FPM titles the pool's workers
// "php-fpm: pool <name>", which the workload collector attributes them by.
func FPMPoolName(pool string) string { return "falak-" + pool }

// RenderPool renders a pool file; listenGroup is the edge user's group so Caddy can connect.
func RenderPool(p FPMPoolPayload, listenGroup string) string {
	group := p.Group
	if group == "" {
		group = p.User
	}
	pm := p.PM
	if pm == "" {
		pm = "dynamic"
	}
	maxReq := 500
	if p.MaxRequests != nil {
		maxReq = *p.MaxRequests
	}
	var b strings.Builder
	fmt.Fprintf(&b, "; Managed by Falak — do not edit\n[%s]\nuser = %s\ngroup = %s\n", FPMPoolName(p.Pool), p.User, group)
	fmt.Fprintf(&b, "listen = %s\nlisten.owner = %s\nlisten.group = %s\nlisten.mode = 0660\n", p.Listen, p.User, listenGroup)
	fmt.Fprintf(&b, "pm = %s\npm.max_children = %d\n", pm, def(p.MaxChildren, 5))
	if pm == "dynamic" {
		fmt.Fprintf(&b, "pm.start_servers = %d\npm.min_spare_servers = %d\npm.max_spare_servers = %d\n",
			def(p.StartServers, 2), def(p.MinSpareServers, 1), def(p.MaxSpareServers, 3))
	}
	if pm == "ondemand" {
		b.WriteString("pm.process_idle_timeout = 10s\n")
	}
	fmt.Fprintf(&b, "pm.max_requests = %d\ncatch_workers_output = yes\ndecorate_workers_output = no\n", maxReq)
	for _, k := range sortedKeys(p.PHPAdminValues) {
		fmt.Fprintf(&b, "php_admin_value[%s] = %s\n", k, p.PHPAdminValues[k])
	}
	for _, k := range sortedKeys(p.Env) {
		fmt.Fprintf(&b, "env[%s] = \"%s\"\n", k, strings.ReplaceAll(p.Env[k], `"`, `\"`))
	}
	return b.String()
}

func sortedKeys(m map[string]string) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

// FPMPool converges a per-site PHP-FPM pool. A pool with a slice (the site has memory, CPU or process limits) runs
// in its own PHP-FPM master, falak-fpm-<pool>.service in that slice: the pools of one shared master are forked by it
// and share its cgroup, so no per-pool limit is possible there. The socket path is the same either way (the edge
// needs no change); switching moves the pool out of one master before the other starts, so the socket is free.
func (rt *Runtime) FPMPool(ctx context.Context, p FPMPoolPayload, st commands.Stream) (any, error) {
	if p.Pool == "" || p.PHPVersion == "" || (p.User == "" && p.State != "absent") {
		return nil, &commands.PayloadError{Err: fmt.Errorf("php_version, pool and user are required")}
	}
	if !poolRe.MatchString(p.Pool) || (p.Slice != "" && !cgroup.NameRe.MatchString(p.Slice)) || p.OomScoreAdj < -1000 || p.OomScoreAdj > 1000 {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid pool, slice or oom_score_adj")}
	}
	if p.Listen == "" {
		p.Listen = fmt.Sprintf("/run/php/falak-%s-%s.sock", p.Pool, p.PHPVersion)
	}
	shared := fmt.Sprintf("/etc/php/%s/fpm/pool.d/falak-%s.conf", p.PHPVersion, p.Pool)
	svc := "php" + p.PHPVersion + "-fpm"
	if p.State == "absent" {
		ownChanged, err := rt.removeOwnFPM(ctx, st, p.Pool)
		if err != nil {
			return nil, err
		}
		removed, err := rt.d.FS.Remove(shared)
		if err != nil {
			return ListenResult{}, err
		}
		if removed {
			return ListenResult{Changed: true}, rt.run(ctx, st, "systemctl", "reload", svc)
		}
		return ListenResult{Changed: ownChanged}, nil
	}
	if !rt.d.FS.Exists(fmt.Sprintf("/etc/php/%s/fpm", p.PHPVersion)) {
		return nil, fmt.Errorf("php%s-fpm is not installed", p.PHPVersion)
	}
	if p.Slice != "" {
		return rt.ownFPM(ctx, st, p, shared, svc)
	}
	ownChanged, err := rt.removeOwnFPM(ctx, st, p.Pool)
	if err != nil {
		return nil, err
	}
	old, oldErr := rt.d.FS.ReadFile(shared)
	changed, err := rt.d.FS.WriteFile(shared, []byte(RenderPool(p, rt.d.EdgeUser)), 0o644)
	if err != nil {
		return nil, err
	}
	res := ListenResult{Changed: changed || ownChanged, Listen: p.Listen}
	if !changed {
		return res, nil
	}
	if err := rt.run(ctx, st, "php-fpm"+p.PHPVersion, "-t"); err != nil {
		restore(rt.d.FS, shared, old, oldErr)
		return nil, fmt.Errorf("php-fpm config test failed, reverted: %w", err)
	}
	return res, rt.run(ctx, st, "systemctl", "reload", svc)
}

var poolRe = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)

// ownFPMDir holds the configuration of pools running in their own master (pools with a slice).
const ownFPMDir = "/etc/falak/fpm"

func ownFPMUnit(pool string) string { return "falak-fpm-" + pool + ".service" }

func ownFPMConf(pool string) string { return ownFPMDir + "/" + pool + ".conf" }

// RenderOwnFPM renders the configuration of a pool's own master: a [global] section, then the pool.
func RenderOwnFPM(p FPMPoolPayload, listenGroup string) string {
	_, pool, _ := strings.Cut(RenderPool(p, listenGroup), "\n") // without the pool's own header line
	return fmt.Sprintf("; Managed by Falak — do not edit\n[global]\npid = /run/php/falak-fpm-%[1]s.pid\nerror_log = /var/log/falak/fpm-%[1]s.log\ndaemonize = no\n\n%[2]s", p.Pool, pool)
}

// RenderOwnFPMUnit renders falak-fpm-<pool>.service. OOMPolicy=continue: when the slice's memory limit OOM-kills a
// worker, the master replaces it instead of systemd stopping the whole site.
func RenderOwnFPMUnit(p FPMPoolPayload) string {
	oom := ""
	if p.OomScoreAdj != 0 {
		oom = fmt.Sprintf("OOMScoreAdjust=%d\n", p.OomScoreAdj)
	}
	return fmt.Sprintf(`# Managed by Falak — do not edit
[Unit]
Description=PHP %[1]s FastCGI for site %[2]s (Falak)
After=network.target

[Service]
Type=notify
Slice=%[3]s
ExecStart=/usr/sbin/php-fpm%[1]s --nodaemonize --fpm-config %[4]s
ExecReload=/bin/kill -USR2 $MAINPID
RuntimeDirectory=php
RuntimeDirectoryPreserve=yes
OOMPolicy=continue
%[5]sRestart=on-failure
RestartSec=2s

[Install]
WantedBy=multi-user.target
`, p.PHPVersion, p.Pool, cgroup.Unit(p.Slice), ownFPMConf(p.Pool), oom)
}

func (rt *Runtime) ownFPM(ctx context.Context, st commands.Stream, p FPMPoolPayload, shared, svc string) (any, error) {
	conf, unit := ownFPMConf(p.Pool), "/etc/systemd/system/"+ownFPMUnit(p.Pool)
	oldConf, oldConfErr := rt.d.FS.ReadFile(conf)
	if err := rt.d.FS.MkdirAll(ownFPMDir, 0o755); err != nil {
		return nil, err
	}
	confChanged, err := rt.d.FS.WriteFile(conf, []byte(RenderOwnFPM(p, rt.d.EdgeUser)), 0o644)
	if err != nil {
		return nil, err
	}
	if confChanged {
		if err := rt.run(ctx, st, "php-fpm"+p.PHPVersion, "-t", "--fpm-config", conf); err != nil {
			restore(rt.d.FS, conf, oldConf, oldConfErr)
			return nil, fmt.Errorf("php-fpm config test failed, reverted: %w", err)
		}
	}
	unitChanged, err := rt.d.FS.WriteFile(unit, []byte(RenderOwnFPMUnit(p)), 0o644)
	if err != nil {
		return nil, err
	}
	// Out of the shared master first: it releases the socket.
	movedOut, err := rt.d.FS.Remove(shared)
	if err != nil {
		return nil, err
	}
	if movedOut {
		if err := rt.run(ctx, st, "systemctl", "reload", svc); err != nil {
			return nil, err
		}
	}
	name := ownFPMUnit(p.Pool)
	var startErr error
	switch {
	case unitChanged:
		for _, args := range [][]string{{"daemon-reload"}, {"enable", name}, {"restart", name}} {
			if startErr = rt.run(ctx, st, "systemctl", args...); startErr != nil {
				break
			}
		}
	case confChanged:
		startErr = rt.run(ctx, st, "systemctl", "reload-or-restart", name)
	}
	if startErr != nil {
		// The site must keep being served: its pool goes back into the shared master.
		if err := rt.rollbackOwnFPM(ctx, st, p, shared, svc); err != nil {
			return nil, fmt.Errorf("own php-fpm master for %s failed (%w) and moving the pool back failed: %v", p.Pool, startErr, err)
		}
		return nil, fmt.Errorf("own php-fpm master for %s failed to start, the pool runs in the shared php%s-fpm again (without limits): %w", p.Pool, p.PHPVersion, startErr)
	}
	return ListenResult{Changed: confChanged || unitChanged || movedOut, Listen: p.Listen}, nil
}

// rollbackOwnFPM removes a pool's own master that failed to start and puts the pool back into the shared master.
func (rt *Runtime) rollbackOwnFPM(ctx context.Context, st commands.Stream, p FPMPoolPayload, shared, svc string) error {
	_, _ = rt.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"disable", "--now", ownFPMUnit(p.Pool)}})
	_, _ = rt.d.FS.Remove("/etc/systemd/system/" + ownFPMUnit(p.Pool))
	_, _ = rt.d.FS.Remove(ownFPMConf(p.Pool))
	_ = rt.run(ctx, st, "systemctl", "daemon-reload")
	if _, err := rt.d.FS.WriteFile(shared, []byte(RenderPool(p, rt.d.EdgeUser)), 0o644); err != nil {
		return err
	}
	return rt.run(ctx, st, "systemctl", "reload", svc)
}

// removeOwnFPM stops and removes a pool's own master (its pool goes back to the shared one, or away).
func (rt *Runtime) removeOwnFPM(ctx context.Context, st commands.Stream, pool string) (bool, error) {
	unit := "/etc/systemd/system/" + ownFPMUnit(pool)
	if !rt.d.FS.Exists(unit) {
		_, err := rt.d.FS.Remove(ownFPMConf(pool))
		return false, err
	}
	if err := rt.run(ctx, st, "systemctl", "disable", "--now", ownFPMUnit(pool)); err != nil {
		return false, err
	}
	if _, err := rt.d.FS.Remove(unit); err != nil {
		return false, err
	}
	if _, err := rt.d.FS.Remove(ownFPMConf(pool)); err != nil {
		return false, err
	}
	return true, rt.run(ctx, st, "systemctl", "daemon-reload")
}
