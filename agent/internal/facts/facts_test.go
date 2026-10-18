package facts

import (
	"context"
	"encoding/json"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func write(t *testing.T, root, p, s string) {
	t.Helper()
	full := filepath.Join(root, p)
	os.MkdirAll(filepath.Dir(full), 0o755)
	if err := os.WriteFile(full, []byte(s), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestCollect(t *testing.T) {
	root := t.TempDir()
	write(t, root, "/etc/hostname", "web-1\n")
	write(t, root, "/etc/os-release", "NAME=\"Ubuntu\"\nID=ubuntu\nVERSION_ID=\"24.04\"\n")
	write(t, root, "/proc/sys/kernel/osrelease", "6.8.0-45-generic\n")
	write(t, root, "/proc/meminfo", "MemTotal:        4028440 kB\nMemFree: 1 kB\n")
	os.MkdirAll(filepath.Join(root, "/etc/php/8.4"), 0o755)
	os.MkdirAll(filepath.Join(root, "/etc/php/8.3"), 0o755)
	os.MkdirAll(filepath.Join(root, "/etc/php/mods-available"), 0o755)
	os.MkdirAll(filepath.Join(root, "/opt/falak/node/22.11.0"), 0o755)
	old := Interfaces
	Interfaces = func() ([]Interface, error) {
		return []Interface{
			{Name: "lo", Addrs: []net.IP{net.ParseIP("127.0.0.1")}},
			{Name: "docker0", Addrs: []net.IP{net.ParseIP("172.17.0.1")}},
			{Name: "eth1", Addrs: []net.IP{net.ParseIP("10.0.0.5")}},
			{Name: "eth0", Addrs: []net.IP{net.ParseIP("203.0.113.9"), net.ParseIP("2001:db8::1")}},
		}, nil
	}
	defer func() { Interfaces = old }()
	fr := (&runnertest.Fake{}).On("docker version", runner.Result{Stdout: []byte("27.3.1\n")})
	f, err := Collect(context.Background(), fr, hostfs.FS{Root: root}, "v1.0.0")
	if err != nil {
		t.Fatal(err)
	}
	if f.Hostname != "web-1" || f.OS.ID != "ubuntu" || f.OS.Version != "24.04" || f.Kernel != "6.8.0-45-generic" {
		t.Fatalf("%+v", f)
	}
	if f.MemoryBytes != 4028440*1024 || f.DiskBytes <= 0 || f.CPUs < 1 {
		t.Fatalf("%+v", f)
	}
	if *f.PublicIPv4 != "203.0.113.9" || *f.PrivateIPv4 != "10.0.0.5" || *f.Docker != "27.3.1" {
		t.Fatalf("%+v", f)
	}
	if len(f.Runtimes["php"]) != 2 || f.Runtimes["php"][1] != "8.4" || f.Runtimes["node"][0] != "22.11.0" {
		t.Fatalf("%+v", f.Runtimes)
	}
	b, _ := json.Marshal(f)
	var m map[string]any
	json.Unmarshal(b, &m)
	for _, k := range []string{"hostname", "os", "arch", "cpus", "memory_bytes", "disk_bytes", "agent_version"} {
		if _, ok := m[k]; !ok {
			t.Fatalf("missing %s", k)
		}
	}
	if _, ok := m["local_edge"]; ok || f.LocalEdge {
		t.Fatal("local_edge without local-edge.env")
	}
	write(t, root, "/etc/falak/local-edge.env", "FALAK_LOCAL_EDGE=1\nFALAK_LOCAL_EDGE_ADDR=172.17.0.1\nFALAK_LOCAL_EDGE_TRUST=172.30.0.0/16\n")
	if f, _ = Collect(context.Background(), fr, hostfs.FS{Root: root}, "v1.0.0"); !f.LocalEdge {
		t.Fatal("local_edge not reported")
	}
}

// routeTable renders /proc/net/route with default routes via the given interfaces (metric in order).
func routeTable(defaults ...string) string {
	s := "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT\n"
	s += "docker0\t000011AC\t00000000\t0001\t0\t0\t0\t0000FFFF\t0\t0\t0\n"
	for i, d := range defaults {
		s += d + "\t00000000\t0100000A\t0003\t0\t0\t" + strconv.Itoa(100*(i+1)) + "\t00000000\t0\t0\t0\n"
	}
	return s
}

func TestIPv4sIgnoreBridgesAndPreferTheDefaultRoute(t *testing.T) {
	ip := func(s ...string) []net.IP {
		var out []net.IP
		for _, a := range s {
			out = append(out, net.ParseIP(a))
		}
		return out
	}
	str := func(p *string) string {
		if p == nil {
			return "<nil>"
		}
		return *p
	}
	cases := []struct {
		name     string
		ifs      []Interface
		route    string
		pub, prv string
	}{
		{"docker host without a private network (the incident)", []Interface{
			{"lo", ip("127.0.0.1")}, {"eth0", ip("203.0.113.9")}, {"docker0", ip("172.17.0.1")}, {"br-1a2b3c", ip("172.18.0.1")}, {"veth12ab", ip("169.254.1.1")},
		}, routeTable("eth0"), "203.0.113.9", "<nil>"},
		{"overlays and VPNs are not the host's private address", []Interface{
			{"tailscale0", ip("100.101.102.103")}, {"wg-falak", ip("10.200.0.2")}, {"cni0", ip("10.42.0.1")}, {"flannel.1", ip("10.42.0.0")},
			{"cali1234", ip("10.1.1.1")}, {"vxlan.calico", ip("10.1.1.2")}, {"ens3", ip("203.0.113.9")},
		}, routeTable("ens3"), "203.0.113.9", "<nil>"},
		{"public default route, private network on a second NIC", []Interface{
			{"docker0", ip("172.17.0.1")}, {"ens10", ip("10.0.0.3")}, {"eth0", ip("203.0.113.9")},
		}, routeTable("eth0"), "203.0.113.9", "10.0.0.3"},
		{"the default route's private address wins over another NIC's", []Interface{
			{"ens4", ip("192.168.50.2")}, {"ens5", ip("172.31.5.10")},
		}, routeTable("ens5", "ens4"), "<nil>", "172.31.5.10"},
		{"no route table: real interfaces in order", []Interface{
			{"docker0", ip("172.17.0.1")}, {"enp1s0", ip("192.168.1.20")},
		}, "", "<nil>", "192.168.1.20"},
	}
	old := Interfaces
	defer func() { Interfaces = old }()
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			root := t.TempDir()
			if c.route != "" {
				write(t, root, "/proc/net/route", c.route)
			}
			Interfaces = func() ([]Interface, error) { return c.ifs, nil }
			pub, prv := ipv4s(DefaultRouteInterface(hostfs.FS{Root: root}))
			if str(pub) != c.pub || str(prv) != c.prv {
				t.Fatalf("public %s private %s, want %s %s", str(pub), str(prv), c.pub, c.prv)
			}
		})
	}
}

func TestDefaultRouteInterface(t *testing.T) {
	root := t.TempDir()
	if DefaultRouteInterface(hostfs.FS{Root: root}) != "" {
		t.Fatal("no table, no interface")
	}
	write(t, root, "/proc/net/route", routeTable("wlan0", "eth0"))
	if got := DefaultRouteInterface(hostfs.FS{Root: root}); got != "wlan0" {
		t.Fatal(got)
	}
}

func TestDockerAbsentIsNull(t *testing.T) {
	fr := (&runnertest.Fake{}).On("docker", runner.Result{ExitCode: 127})
	f, _ := Collect(context.Background(), fr, hostfs.FS{Root: t.TempDir()}, "dev")
	b, _ := json.Marshal(f)
	var m map[string]any
	json.Unmarshal(b, &m)
	if v, ok := m["docker"]; !ok || v != nil {
		t.Fatalf("docker should be null: %s", b)
	}
}
