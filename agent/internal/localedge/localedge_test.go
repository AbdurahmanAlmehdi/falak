package localedge

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

func TestLoad(t *testing.T) {
	root := t.TempDir()
	f := hostfs.FS{Root: root}
	if c, err := Load(f, Path); err != nil || c.Enabled {
		t.Fatalf("absent file: %+v %v", c, err)
	}
	os.MkdirAll(filepath.Join(root, "etc/falak"), 0o755)
	os.WriteFile(f.P(Path), []byte("# falak-ctl\nFALAK_LOCAL_EDGE=1\nFALAK_LOCAL_EDGE_ADDR=172.17.0.1\nFALAK_LOCAL_EDGE_HTTP_PORT=8080\n"+
		"FALAK_LOCAL_EDGE_HTTPS_PORT=8443\nFALAK_LOCAL_EDGE_TRUST=172.30.0.0/16\nFALAK_WORKLOAD_SLICE=falak.slice\n"), 0o644)
	c, err := Load(f, Path)
	want := Config{Enabled: true, Addr: "172.17.0.1", HTTPPort: 8080, HTTPSPort: 8443, Trust: "172.30.0.0/16", WorkloadSlice: "falak.slice"}
	if err != nil || c != want {
		t.Fatalf("got %+v %v", c, err)
	}
	if c.HTTPListen() != "172.17.0.1:8080" || c.HTTPSListen() != "172.17.0.1:8443" {
		t.Fatal(c.HTTPListen(), c.HTTPSListen())
	}
}

func TestParse(t *testing.T) {
	if c, err := Parse([]byte("FALAK_LOCAL_EDGE=0\nFALAK_LOCAL_EDGE_ADDR=nope\n")); err != nil || c.Enabled {
		t.Fatalf("disabled: %+v %v", c, err)
	}
	// Ports default to the contract's.
	c, err := Parse([]byte("FALAK_LOCAL_EDGE=1\nFALAK_LOCAL_EDGE_ADDR=10.0.0.1\nFALAK_LOCAL_EDGE_TRUST=10.1.0.0/24\n"))
	if err != nil || c.HTTPPort != 8080 || c.HTTPSPort != 8443 || c.WorkloadSlice != "" {
		t.Fatalf("defaults: %+v %v", c, err)
	}
	base := "FALAK_LOCAL_EDGE=1\nFALAK_LOCAL_EDGE_ADDR=10.0.0.1\nFALAK_LOCAL_EDGE_TRUST=10.1.0.0/24\n"
	for _, bad := range []string{
		"FALAK_LOCAL_EDGE=1\nFALAK_LOCAL_EDGE_TRUST=10.1.0.0/24\n",
		"FALAK_LOCAL_EDGE=1\nFALAK_LOCAL_EDGE_ADDR=10.0.0.1\nFALAK_LOCAL_EDGE_TRUST=10.1.0.1\n",
		base + "FALAK_LOCAL_EDGE_HTTPS_PORT=443\n",
		base + "FALAK_LOCAL_EDGE_HTTP_PORT=8443\n",
		base + "FALAK_LOCAL_EDGE_HTTP_PORT=x\n",
		base + "FALAK_WORKLOAD_SLICE=../etc\n",
		base + "garbage\n",
	} {
		if _, err := Parse([]byte(bad)); err == nil {
			t.Errorf("accepted %q", bad)
		}
	}
}
