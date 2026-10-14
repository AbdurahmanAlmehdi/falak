package db

import (
	"context"
	"encoding/json"
	"sort"
	"strings"
	"sync"
	"time"
)

// InstanceReport is one database container in the heartbeat (`databases`).
type InstanceReport struct {
	ID     string `json:"id"`
	State  string `json:"state"`
	Health string `json:"health"`
	// SecretsMissing: the secrets directory is gone (a reboot emptied /run), so the container cannot start until the
	// control plane sends db.instance.secrets.
	SecretsMissing bool `json:"secrets_missing"`
	// PITR is the shipping of its spool (instances with point-in-time recovery on).
	PITR *PITRReport `json:"pitr,omitempty"`
	// Connections are the open client connections and the server's limit (`falak-db stats`, at most once per
	// StatsEvery); nil until known, or when the image predates the command.
	Connections *Connections `json:"connections,omitempty"`
}

// Connections is `falak-db stats`.
type Connections struct {
	Used int64 `json:"used"`
	Max  int64 `json:"max"`
}

// StatsEvery is how often an instance's connection figures are refreshed (in the background: the heartbeat never
// waits for a docker exec).
var StatsEvery = time.Minute

type statsEntry struct {
	at   time.Time
	c    *Connections
	busy bool
}

// statsCache holds each running instance's last connection figures.
type statsCache struct {
	mu sync.Mutex
	m  map[string]*statsEntry
}

// Report lists the database containers on the server, for the heartbeat (nil when there are none or Docker is not
// reachable).
func (db *DB) Report(ctx context.Context) []InstanceReport {
	if db.d.Docker == nil {
		return nil
	}
	list, err := db.d.Docker.ContainerList(ctx, true, []string{LabelInstance})
	if err != nil {
		return nil
	}
	var out []InstanceReport
	cfgs := db.pitrConfigs()
	running := map[string]bool{}
	for _, c := range list {
		id := c.Labels[LabelInstance]
		if !idRe.MatchString(id) {
			continue
		}
		r := InstanceReport{ID: id, State: reportState(c.State), Health: healthFromStatus(c.Status), SecretsMissing: db.secretsMissing(id), PITR: db.pitrReport(id, cfgs)}
		if r.State == "running" && r.Health == "healthy" {
			running[id] = true
			r.Connections = db.connections(id)
		}
		out = append(out, r)
	}
	db.forgetStats(running)
	sort.Slice(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	if len(out) > 200 {
		out = out[:200]
	}
	return out
}

func reportState(s string) string {
	switch s {
	case "running", "exited", "created", "restarting", "paused", "dead":
		return s
	}
	return "missing"
}

// healthFromStatus reads the health from a container list entry's Status ("Up 3 minutes (healthy)").
func healthFromStatus(status string) string {
	switch {
	case strings.Contains(status, "(healthy)"):
		return "healthy"
	case strings.Contains(status, "(unhealthy)"):
		return "unhealthy"
	case strings.Contains(status, "(health: starting)"):
		return "starting"
	}
	return "none"
}

// connections returns the instance's cached connection figures, refreshing them in the background when stale.
func (db *DB) connections(id string) *Connections {
	// The observer's last pass has them (an image with `falak-db observe`): no `stats` exec needed.
	if c := db.observer.Load().Connections(id); c != nil {
		return c
	}
	db.stats.mu.Lock()
	defer db.stats.mu.Unlock()
	if db.stats.m == nil {
		db.stats.m = map[string]*statsEntry{}
	}
	e := db.stats.m[id]
	if e == nil {
		e = &statsEntry{}
		db.stats.m[id] = e
	}
	if !e.busy && time.Since(e.at) >= StatsEvery {
		e.busy = true
		go db.refreshStats(id, e)
	}
	if e.c == nil {
		return nil
	}
	c := *e.c
	return &c
}

func (db *DB) refreshStats(id string, e *statsEntry) {
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	var c *Connections
	if out, _, err := db.exec(ctx, id, nil, nil, nil, "stats"); err == nil {
		var r struct {
			Connections    int64 `json:"connections"`
			MaxConnections int64 `json:"max_connections"`
		}
		if json.Unmarshal([]byte(strings.TrimSpace(out)), &r) == nil && r.MaxConnections > 0 {
			c = &Connections{Used: r.Connections, Max: r.MaxConnections}
		}
	}
	db.stats.mu.Lock()
	e.at, e.c, e.busy = time.Now(), c, false
	db.stats.mu.Unlock()
}

// forgetStats drops the figures of instances no longer running.
func (db *DB) forgetStats(running map[string]bool) {
	db.stats.mu.Lock()
	defer db.stats.mu.Unlock()
	for id := range db.stats.m {
		if !running[id] {
			delete(db.stats.m, id)
		}
	}
}
