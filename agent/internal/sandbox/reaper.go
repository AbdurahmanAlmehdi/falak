package sandbox

import (
	"context"
	"os"
	"path/filepath"
	"sync"
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
// and tells the control plane. Each pass also re-applies the network when sandboxes exist: the chains may have been
// flushed by a firewall reload, and Docker puts its jumps above the agent's when it starts or creates a network.
func (s *Service) Run(ctx context.Context) {
	t := time.NewTicker(time.Minute)
	defer t.Stop()
	for {
		if goos == "linux" && len(s.states()) > 0 {
			if err := s.ensureNetwork(ctx); err != nil {
				s.d.Logger.Error("sandbox network", "err", err)
			}
		}
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
	s.gcSnapshotTemps()
	s.gcImages(ctx)
}

// imageTTL is how long an unused base rootfs is kept (its directory's mtime is its last use).
const imageTTL = 6 * time.Hour

// gcImages removes the base rootfs of images no sandbox or snapshot uses and none used for imageTTL, and the Docker
// image too (without force: Docker keeps it while a container uses it or another tag points at it).
func (s *Service) gcImages(ctx context.Context) {
	used := map[string]bool{}
	for _, st := range s.states() {
		used[filepath.Base(filepath.Dir(st.Rootfs))] = true
	}
	for _, m := range s.snapshots() {
		used[filepath.Base(filepath.Dir(m.Rootfs))] = true
	}
	root := filepath.Join(s.d.Root, "images")
	ents, _ := os.ReadDir(root)
	for _, e := range ents {
		hex := e.Name()
		if !e.IsDir() || !hexRe.MatchString(hex) || used[hex] {
			continue
		}
		m, _ := s.imgLocks.LoadOrStore(hex, &sync.Mutex{})
		mu := m.(*sync.Mutex)
		mu.Lock() // a create touches the directory under this lock, before its state lists the image
		if fi, err := os.Stat(filepath.Join(root, hex)); err == nil && s.d.Now().Sub(fi.ModTime()) >= imageTTL {
			if err := os.RemoveAll(filepath.Join(root, hex)); err != nil {
				s.d.Logger.Warn("remove sandbox base image", "image", hex, "err", err)
			} else if err := s.d.Docker.ImageRemove(ctx, "sha256:"+hex); err != nil {
				s.d.Logger.Debug("docker image kept", "image", hex, "err", err)
			}
		}
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
		// Bounded: the sandbox's lock is held meanwhile (a dead control plane is retried on the next pass).
		rctx, cancel := context.WithTimeout(ctx, 15*time.Second)
		err := s.d.Requests.Request(rctx, "sandbox.reaped", map[string]string{"id": st.ID, "reason": st.Reaped}, &reply)
		cancel()
		if err != nil {
			s.d.Logger.Warn("report reaped sandbox", "sandbox", st.ID, "err", err)
			return
		}
	}
	_ = os.RemoveAll(s.dir(st.ID))
}
