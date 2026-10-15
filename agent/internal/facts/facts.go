// Package facts collects host facts (contracts/agent-protocol/facts.schema.json).
package facts

import (
	"bufio"
	"bytes"
	"context"
	"net"
	"os"
	goruntime "runtime"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/version"
)

// OS identifies the distribution.
type OS struct {
	ID      string `json:"id"`
	Version string `json:"version"`
}

// Facts mirrors facts.schema.json.
type Facts struct {
	Hostname     string              `json:"hostname"`
	OS           OS                  `json:"os"`
	Arch         string              `json:"arch"`
	Kernel       string              `json:"kernel,omitempty"`
	CPUs         int                 `json:"cpus"`
	MemoryBytes  int64               `json:"memory_bytes"`
	DiskBytes    int64               `json:"disk_bytes"`
	PublicIPv4   *string             `json:"public_ipv4"`
	PrivateIPv4  *string             `json:"private_ipv4"`
	Docker       *string             `json:"docker"`
	Runtimes     map[string][]string `json:"runtimes"`
	AgentVersion string              `json:"agent_version"`
	Features     []string            `json:"features"`
	AgentSHA256  string              `json:"agent_sha256,omitempty"`
	// RebootRequired: the distribution asked for a reboot (kernel or libc updates).
	RebootRequired bool `json:"reboot_required"`
	// TLSCertificates are the edge's ACME certificates and their expiry.
	TLSCertificates []TLSCertificate `json:"tls_certificates,omitempty"`
	// EBPF is the eBPF probe's status (ebpfobs.Status, docs/plans/network/ebpf.md), set by the agent's heartbeat.
	EBPF any `json:"ebpf,omitempty"`
}

// Interface is one network interface that is up, with its addresses.
type Interface struct {
	Name  string
	Addrs []net.IP
}

// Interfaces lists the interfaces that are up; overridable in tests.
var Interfaces = func() ([]Interface, error) {
	ifs, err := net.Interfaces()
	if err != nil {
		return nil, err
	}
	var out []Interface
	for _, i := range ifs {
		if i.Flags&net.FlagUp == 0 {
			continue
		}
		addrs, err := i.Addrs()
		if err != nil {
			continue
		}
		it := Interface{Name: i.Name}
		for _, a := range addrs {
			if ipn, ok := a.(*net.IPNet); ok {
				it.Addrs = append(it.Addrs, ipn.IP)
			}
		}
		out = append(out, it)
	}
	return out, nil
}

// Collect gathers facts. Missing sources degrade to zero values rather than failing.
func Collect(ctx context.Context, r runner.Runner, fs hostfs.FS, agentVersion string) (Facts, error) {
	f := Facts{Arch: goruntime.GOARCH, CPUs: goruntime.NumCPU(), AgentVersion: agentVersion, Runtimes: map[string][]string{},
		Features: append([]string(nil), version.Features...), AgentSHA256: version.BinarySHA256()}
	if b, err := fs.ReadFile("/etc/hostname"); err == nil && strings.TrimSpace(string(b)) != "" {
		f.Hostname = strings.TrimSpace(string(b))
	} else {
		f.Hostname, _ = os.Hostname()
	}
	if b, err := fs.ReadFile("/etc/os-release"); err == nil {
		kv := ParseOSRelease(b)
		f.OS = OS{ID: kv["ID"], Version: kv["VERSION_ID"]}
	} else {
		f.OS = OS{ID: goruntime.GOOS, Version: ""}
	}
	if b, err := fs.ReadFile("/proc/sys/kernel/osrelease"); err == nil {
		f.Kernel = strings.TrimSpace(string(b))
	}
	if b, err := fs.ReadFile("/proc/meminfo"); err == nil {
		f.MemoryBytes = MeminfoKB(b, "MemTotal") * 1024
	}
	var st syscall.Statfs_t
	if err := syscall.Statfs(fs.P("/"), &st); err == nil {
		f.DiskBytes = int64(st.Blocks) * int64(st.Bsize)
	}
	f.PublicIPv4, f.PrivateIPv4 = ipv4s(DefaultRouteInterface(fs))
	if f.PublicIPv4 == nil && fs.IsReal() {
		f.PublicIPv4 = CloudPublicIPv4(ctx) // 1:1 NAT (EC2, GCP, Azure): not on any interface
	}
	if r != nil {
		cctx, cancel := context.WithTimeout(ctx, 5*time.Second)
		res, err := r.Run(cctx, runner.Cmd{Name: "docker", Args: []string{"version", "--format", "{{.Server.Version}}"}})
		cancel()
		if err == nil && res.ExitCode == 0 {
			if v := strings.TrimSpace(string(res.Stdout)); v != "" {
				f.Docker = &v
			}
		}
		if fs.Exists("/usr/local/bin/frankenphp") {
			cctx, cancel := context.WithTimeout(ctx, 5*time.Second)
			res, err := r.Run(cctx, runner.Cmd{Name: fs.P("/usr/local/bin/frankenphp"), Args: []string{"version"}})
			cancel()
			if err == nil && res.ExitCode == 0 {
				if v := frankenVersion(string(res.Stdout)); v != "" {
					f.Runtimes["frankenphp"] = []string{v}
				}
			}
		}
	}
	f.RebootRequired = RebootRequired(fs)
	f.TLSCertificates = TLSCertificates(fs)
	if v := dirVersions(fs, "/etc/php", func(n string) bool { _, err := strconv.ParseFloat(n, 64); return err == nil }); len(v) > 0 {
		f.Runtimes["php"] = v
	}
	if v := dirVersions(fs, "/opt/falak/node", func(n string) bool { return strings.Count(n, ".") == 2 }); len(v) > 0 {
		f.Runtimes["node"] = v
	}
	return f, nil
}

func frankenVersion(out string) string {
	// "FrankenPHP v1.4.0 PHP 8.4.3 Caddy v2.9.1 ..."
	for _, w := range strings.Fields(out) {
		if strings.HasPrefix(w, "v") && strings.Count(w, ".") >= 1 {
			return strings.TrimPrefix(w, "v")
		}
	}
	return ""
}

func dirVersions(fs hostfs.FS, dir string, ok func(string) bool) []string {
	ents, err := os.ReadDir(fs.P(dir))
	if err != nil {
		return nil
	}
	var out []string
	for _, e := range ents {
		if e.IsDir() && ok(e.Name()) {
			out = append(out, e.Name())
		}
	}
	sort.Strings(out)
	return out
}

// ParseOSRelease parses /etc/os-release KEY=VALUE lines.
func ParseOSRelease(b []byte) map[string]string {
	out := map[string]string{}
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		k, v, ok := strings.Cut(strings.TrimSpace(sc.Text()), "=")
		if !ok || strings.HasPrefix(k, "#") {
			continue
		}
		out[k] = strings.Trim(v, `"'`)
	}
	return out
}

// MeminfoKB returns the kB value of a /proc/meminfo key.
func MeminfoKB(b []byte, key string) int64 {
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		k, v, ok := strings.Cut(sc.Text(), ":")
		if ok && k == key {
			fs := strings.Fields(v)
			if len(fs) > 0 {
				n, _ := strconv.ParseInt(fs[0], 10, 64)
				return n
			}
		}
	}
	return 0
}

var privateNets = func() []*net.IPNet {
	var out []*net.IPNet
	for _, c := range []string{"10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "100.64.0.0/10"} {
		_, n, _ := net.ParseCIDR(c)
		out = append(out, n)
	}
	return out
}()

// IsPrivate reports RFC1918/CGNAT addresses.
func IsPrivate(ip net.IP) bool {
	for _, n := range privateNets {
		if n.Contains(ip) {
			return true
		}
	}
	return false
}

// virtualInterfaces are name prefixes of interfaces whose addresses are never the host's own: container bridges
// and veths (Docker's docker0 172.17.0.1, br-*, CNI plugins), VPNs and overlays (WireGuard incl. Falak's wg-falak,
// Tailscale, ZeroTier), VM bridges and tunnels.
var virtualInterfaces = []string{
	"lo", "docker", "br-", "veth", "cni", "flannel", "cali", "vxlan", "tailscale", "wg", "virbr", "lxcbr", "lxdbr",
	"podman", "cilium", "kube-", "weave", "tun", "tap", "zt", "vnet", "nebula", "genev", "gre", "sit", "ip6tnl", "dummy",
}

// IsVirtualInterface reports whether name is a container bridge, VPN, tunnel or loopback interface.
func IsVirtualInterface(name string) bool {
	for _, p := range virtualInterfaces {
		if strings.HasPrefix(name, p) {
			return true
		}
	}
	return false
}

// DefaultRouteInterface returns the interface of the IPv4 default route with the lowest metric ("" if none).
func DefaultRouteInterface(fs hostfs.FS) string {
	b, err := fs.ReadFile("/proc/net/route")
	if err != nil {
		return ""
	}
	best, bestMetric := "", -1
	for _, line := range strings.Split(string(b), "\n")[1:] {
		f := strings.Fields(line)
		// Iface Destination Gateway Flags RefCnt Use Metric Mask ...
		if len(f) < 8 || f[1] != "00000000" || f[7] != "00000000" {
			continue
		}
		m, _ := strconv.Atoi(f[6])
		if bestMetric < 0 || m < bestMetric {
			best, bestMetric = f[0], m
		}
	}
	return best
}

// ipv4s picks the public and private IPv4 from the host's real interfaces, the default route's interface first.
// A private address exists only on a real interface: a host whose only RFC1918 address is a bridge's reports none.
func ipv4s(defaultIface string) (pub, priv *string) {
	ifs, err := Interfaces()
	if err != nil {
		return nil, nil
	}
	sort.SliceStable(ifs, func(i, j int) bool { return ifs[i].Name == defaultIface && ifs[j].Name != defaultIface })
	for _, it := range ifs {
		if IsVirtualInterface(it.Name) {
			continue
		}
		for _, a := range it.Addrs {
			ip := a.To4()
			if ip == nil || ip.IsLoopback() || ip.IsLinkLocalUnicast() {
				continue
			}
			s := ip.String()
			if IsPrivate(ip) {
				if priv == nil {
					priv = &s
				}
			} else if pub == nil {
				pub = &s
			}
		}
	}
	return pub, priv
}

// OSRelease helper for other packages: reads VERSION_CODENAME etc.
func OSRelease(fs hostfs.FS) map[string]string {
	b, err := fs.ReadFile("/etc/os-release")
	if err != nil {
		return map[string]string{}
	}
	return ParseOSRelease(b)
}
