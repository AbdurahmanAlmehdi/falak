package runner

import (
	"context"
	"os"
	"strings"
	"testing"
)

func TestClearEnvDropsTheParentsEnvironment(t *testing.T) {
	t.Setenv("FALAK_BUILDER_TOKEN", "builder-secret")
	res, err := Check(context.Background(), Exec{}, Cmd{Name: "/usr/bin/env", ClearEnv: true, Env: []string{"PATH=/opt/x:/usr/bin", "A=1"}})
	if err != nil {
		t.Fatal(err)
	}
	got := string(res.Stdout)
	if strings.Contains(got, "builder-secret") || !strings.Contains(got, "A=1\n") || strings.Count(got, "PATH=") != 1 || !strings.Contains(got, "PATH=/opt/x:/usr/bin\n") {
		t.Fatal(got)
	}
}

func TestNumericUID(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	res, err := Check(context.Background(), Exec{}, Cmd{Name: "/usr/bin/id", Args: []string{"-u"}, ClearEnv: true, UID: 200123, GID: 200123})
	if err != nil || strings.TrimSpace(string(res.Stdout)) != "200123" {
		t.Fatalf("%s %v", res.Stdout, err)
	}
}
