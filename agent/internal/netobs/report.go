package netobs

import "time"

// Report is the net.observed request (contracts/agent-protocol/requests/net.observed.schema.json).
type Report struct {
	At        time.Time         `json:"at"`
	WindowS   int               `json:"window_s"`
	Conntrack ReportConntrack   `json:"conntrack"`
	Workloads []ReportWorkload  `json:"workloads"`
	WireGuard []ReportWireGuard `json:"wireguard"`
	Probes    []ReportProbe     `json:"probes"`
	Truncated bool              `json:"truncated"`
}

// ReportConntrack is the conntrack table's fill (entries / limit absent without conntrack).
type ReportConntrack struct {
	Entries    *int64 `json:"entries,omitempty"`
	Limit      *int64 `json:"limit,omitempty"`
	Accounting string `json:"accounting"`
}

// ReportWorkload is one workload's connections, listeners and peers.
type ReportWorkload struct {
	Kind           string              `json:"kind"` // container | processes | host
	Name           string              `json:"name"`
	Site           string              `json:"site,omitempty"`
	ComposeService string              `json:"compose_service,omitempty"`
	DBInstance     string              `json:"db_instance,omitempty"`
	ContainerID    string              `json:"container_id,omitempty"`
	Connections    ReportConnections   `json:"connections"`
	Listeners      []ReportListener    `json:"listeners"`
	Peers          []ReportPeer        `json:"peers"`
	Other          map[string]PeerSums `json:"other,omitempty"` // by direction: the peers past the top 20
}

// ReportConnections are the workload's TCP connections.
type ReportConnections struct {
	Inbound   int            `json:"inbound"`
	Outbound  int            `json:"outbound"`
	LongLived int            `json:"long_lived"`
	States    map[string]int `json:"states"`
}

// ReportListener is one listening socket.
type ReportListener struct {
	Proto   string `json:"proto"`
	Address string `json:"address"`
	Port    int    `json:"port"`
	Public  bool   `json:"public"`
	Process string `json:"process,omitempty"`
	// Container is the container a docker-proxy listener publishes (its name).
	Container string `json:"container,omitempty"`
	// Owner is the listener's owner as a peer name (site:<slug>, db:<id>, host:<comm>).
	Owner string `json:"owner,omitempty"`
}

// ReportPeer is one remote endpoint: outbound by address and port, inbound by address (port 0).
type ReportPeer struct {
	Direction   string  `json:"direction"`
	Address     string  `json:"address"`
	Port        int     `json:"port"`
	Name        string  `json:"name,omitempty"`
	Connections int     `json:"connections"`
	RxBytes     *uint64 `json:"rx_bytes,omitempty"`
	TxBytes     *uint64 `json:"tx_bytes,omitempty"`
}

// PeerSums are the folded peers' totals.
type PeerSums struct {
	Peers       int     `json:"peers"`
	Connections int     `json:"connections"`
	RxBytes     *uint64 `json:"rx_bytes,omitempty"`
	TxBytes     *uint64 `json:"tx_bytes,omitempty"`
}

// ReportWireGuard is one WireGuard peer.
type ReportWireGuard struct {
	Interface     string   `json:"interface"`
	PublicKey     string   `json:"public_key"`
	Name          string   `json:"name,omitempty"`
	AllowedIPs    []string `json:"allowed_ips"`
	HandshakeAgeS *int64   `json:"handshake_age_s"`
	RxBytes       uint64   `json:"rx_bytes"`
	TxBytes       uint64   `json:"tx_bytes"`
}

// ReportProbe is one latency probe's result.
type ReportProbe struct {
	Name      string   `json:"name"`
	Kind      string   `json:"kind"`
	Host      string   `json:"host"`
	Port      int      `json:"port"`
	OK        bool     `json:"ok"`
	Refused   bool     `json:"refused,omitempty"`
	ConnectMS *float64 `json:"connect_ms,omitempty"`
	Error     string   `json:"error,omitempty"`
}
