package dbhelper

import (
	"context"
	"strconv"
)

// Reload is `falak-db reload`: renders the config again from the current settings (the agent rewrote the settings
// file) and applies what the running server can take without a restart, the slow query threshold: PostgreSQL
// re-reads its config (pg_reload_conf: log_min_duration_statement is reloadable), MySQL / MariaDB get SET GLOBAL
// slow_query_log / long_query_time. Every other setting still needs a restart (the agent restarts when anything else
// changed). Redis / Valkey can't: CONFIG is denied to the default user (exit 3).
func (h *Helper) Reload(ctx context.Context) error {
	if h.Engine.kv() {
		return unsupported(h.Engine, "reload")
	}
	s, err := h.settings()
	if err != nil {
		return err
	}
	mem, _, err := h.detectMemory()
	if err != nil {
		return err
	}
	if _, err := h.renderAndWrite(mem, s); err != nil {
		return err
	}
	ms := s.slowMS(h.Engine)
	if h.Engine == Postgres {
		if _, err := h.pgExec(ctx, "postgres", "SELECT pg_reload_conf();"); err != nil {
			return err
		}
		return h.result(map[string]any{"reloaded": true, "applied": []string{"log_min_duration_statement"}, "slow_query_ms": ms})
	}
	sql := "SET GLOBAL slow_query_log = OFF;\n"
	if ms > 0 {
		sql = "SET GLOBAL slow_query_log_file = " + myLit(mySlowLog) + ";\n" +
			"SET GLOBAL long_query_time = " + strconv.FormatFloat(float64(ms)/1000, 'f', 3, 64) + ";\n" +
			"SET GLOBAL slow_query_log = ON;\n"
	}
	if _, err := h.myExec(ctx, sql); err != nil {
		return err
	}
	return h.result(map[string]any{"reloaded": true, "applied": []string{"slow_query_log", "long_query_time"}, "slow_query_ms": ms})
}
