package netobs

import (
	"net/netip"

	"github.com/OthmanHaba/falak/agent/internal/metrics"
)

// Namer returns a function naming remote endpoints the way falak.net.peer does, for the eBPF collector
// (docs/plans/network/ebpf.md): the control plane's names, then the containers on this server by IP, else the bucket
// (external:<port>, private:<port>, local:<port> outbound; external, private, local inbound). Never an address. The
// host's listener owners (host:<comm>) are not known outside a full pass and are not used.
func (c *Collector) Namer(snap metrics.NetSnapshot) func(inHost bool, dir string, a netip.Addr, port uint16) string {
	c.mu.Lock()
	cfg := c.cfg
	c.mu.Unlock()
	n := &names{cfg: map[string]string{}, ctByIP: map[netip.Addr]string{}, hostOwners: map[listenerKey]string{}}
	for _, nm := range cfg.Names {
		if a, err := netip.ParseAddr(nm.Address); err == nil {
			n.cfg[nameKey(a.Unmap(), uint16(nm.Port))] = nm.Name
		}
	}
	for _, ct := range snap.Containers {
		for _, ip := range ct.IPs {
			if a, err := netip.ParseAddr(ip); err == nil {
				n.ctByIP[a.Unmap()] = containerLabel(ct)
			}
		}
	}
	host, ctr := &workload{inHost: true}, &workload{}
	return func(inHost bool, dir string, a netip.Addr, port uint16) string {
		a = a.Unmap()
		w := ctr
		if inHost {
			w = host
		}
		if v := n.name(w, dir, a, port); v != "" {
			return v
		}
		if dir == dirIn {
			port = 0
		}
		return bucket(dir, a, port)
	}
}
