package sandbox

import (
	"context"
	"os"
	"time"
)

// Reap reasons (the sandbox.reaped request).
const (
	ReasonExpired = "expired"
	ReasonOOM     = "oom"
	ReasonExited  = "exited"
)

// Run is the reaper: at start (after an agent restart or a reboot) and every minute it removes the sandboxes past
// expires_at and those whose sandbox stopped (OOM kill, init exited, or gone after a reboot: no restart in v0.20),
// and tells the control plane. It also re-applies the network (the chains may have been flushed by a firewall or
// Docker reload while the agent was down) when sandboxes exist.
func (s *Service) Run(ctx context.Context) {
	if goos == "linux" && len(s.states()) > 0 {
		if err := s.ensureNetwork(ctx); err != nil {
			s.d.Logger.Error("sandbox network", "err", err)
		}
	}
	t := time.NewTicker(time.Minute)
	defer t.Stop()
	for {
		s.ReapOnce(ctx)
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

// reapReason decides what happens to a sandbox: "" keeps it. status is runsc's ("" = unknown to runsc); known=false
// when runsc couldn't be asked, which never removes a sandbox that hasn't expired.
func reapReason(expiresAt, now int64, status string, known, oom bool) string {
	switch {
	case expiresAt > 0 && now >= expiresAt:
		return ReasonExpired
	case !known, status == "running", status == "paused", status == "created":
		return ""
	case oom:
		return ReasonOOM
	default:
		return ReasonExited
	}
}

// ReapOnce is one pass of the reaper. A sandbox busy with a command (create, kill) is looked at next time.
func (s *Service) ReapOnce(ctx context.Context) {
	for _, st := range s.states() {
		mu := s.lock(st.ID)
		if !mu.TryLock() {
			continue
		}
		s.reapOne(ctx, st)
		mu.Unlock()
	}
}

func (s *Service) reapOne(ctx context.Context, st *state) {
	// Re-read under the lock: a kill may have removed it since the listing.
	st, err := s.readState(st.ID)
	if err != nil || st == nil {
		return
	}
	if st.Reaped == "" {
		status, known := s.status(ctx, st.ID)
		reason := reapReason(st.ExpiresAt, s.d.Now().Unix(), status, known, s.oomKilled(st.ID))
		if reason == "" {
			return
		}
		s.d.Logger.Info("reaping sandbox", "sandbox", st.ID, "reason", reason, "runsc_status", status)
		st.Reaped = reason
		if err := s.writeState(st); err != nil {
			s.d.Logger.Warn("sandbox state", "sandbox", st.ID, "err", err)
		}
		s.teardown(ctx, st)
	}
	// The state directory stays (inventory: stopped) until the control plane has been told.
	if s.d.Requests != nil {
		var reply struct {
			Accepted bool `json:"accepted"`
		}
		if err := s.d.Requests.Request(ctx, "sandbox.reaped", map[string]string{"id": st.ID, "reason": st.Reaped}, &reply); err != nil {
			s.d.Logger.Warn("report reaped sandbox", "sandbox", st.ID, "err", err)
			return
		}
	}
	_ = os.RemoveAll(s.dir(st.ID))
}
