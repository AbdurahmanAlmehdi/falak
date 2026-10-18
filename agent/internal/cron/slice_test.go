package cron

import (
	"context"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// On the control-plane machine every run is a scope of falak.slice, dropped to the job's user by setpriv.
func TestRunsGoUnderTheSlice(t *testing.T) {
	fr := &runnertest.Fake{}
	s := New(Options{Runner: fr, Clock: &fakeClock{now: time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)}, Slice: "falak.slice",
		LookupUser: func(name string) (*syscall.Credential, string, error) {
			return &syscall.Credential{Uid: 1001, Gid: 1002}, "/home/" + name, nil
		}})
	s.run(context.Background(), Job{Name: "shop.backup", Command: "echo hi", User: "shop", Cwd: "/srv/shop"}, time.Time{})
	c := fr.Calls()[0]
	want := "systemd-run --scope --quiet --collect --slice=falak.slice --unit=falak-cron-shop.backup-1.scope -- " +
		"setpriv --reuid=1001 --regid=1002 --init-groups -- /bin/sh -c echo hi"
	if c.Line != want || c.User != "" || c.Dir != "/srv/shop" || !c.ClearEnv || !strings.Contains(strings.Join(c.Env, " "), "HOME=/home/shop USER=shop") {
		t.Fatalf("%+v", c.Cmd)
	}

	// Elsewhere: as before, the runner drops to the user.
	fr2 := &runnertest.Fake{}
	New(Options{Runner: fr2, Clock: &fakeClock{now: time.Now()}}).run(context.Background(), Job{Name: "b", Command: "true", User: "shop"}, time.Time{})
	if c := fr2.Calls()[0]; c.Line != "/bin/sh -c true" || c.User != "shop" {
		t.Fatalf("%+v", c.Cmd)
	}
}
