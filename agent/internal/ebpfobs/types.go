package ebpfobs

import "time"

// Go mirrors of the probe's map keys and values (bpf/probe.bpf.c): same fields, same sizes (types_test.go checks them
// against the compiled object's map specs).

const (
	slots    = 27 // log2 µs buckets of a kernel histogram
	qnameLen = 64

	dirOut = 1
	dirIn  = 2

	kindConnect = 1
	kindRTT     = 2
	kindDNS     = 3
	kindRunq    = 4

	afInet  = 2
	afInet6 = 10
)

// owner is who an event belongs to: a cgroup v2 id, a process (0 outside its context) and a uid.
type owner struct {
	CgID uint64
	TGID uint32
	UID  uint32
}

type peerKey struct {
	O      owner
	Addr   [16]byte
	Port   uint16
	Family uint8
	Dir    uint8
	_      [4]byte
}

type peerVal struct {
	Retrans, RstSent, RstRecv, Connects, ConnectFail, RTTSumUS, RTTCount uint64
}

type histKey struct {
	O    owner
	Kind uint32
	_    uint32
}

type histVal struct {
	Slots        [slots]uint64
	SumUS, Count uint64
}

type dnsKey struct {
	CgID  uint64
	UID   uint32
	_     uint32
	QName [qnameLen]byte
}

type dnsVal struct {
	Queries, Responses, NXDomain, ServFail, Refused, OtherErr, LatSumUS uint64
}

type dnsPendingKey struct {
	Cookie uint64
	ID     uint16
	_      uint16
	_      uint32
}

type dnsPending struct {
	TS uint64
	K  dnsKey
}

// Program groups: attached (and shed) together.
const (
	groupTCP  = "tcp"
	groupDNS  = "dns"
	groupRunq = "runq"
)

// source is the loaded probe as the collector reads it (the real one in probe_linux.go, fakes in tests).
type source interface {
	Peers(func(peerKey, peerVal)) error
	Hists(func(histKey, histVal)) error
	DNSNames(func(dnsKey, dnsVal)) error
	// ExpirePending removes the DNS queries waiting longer than age for an answer and returns them (timeouts).
	ExpirePending(age time.Duration) ([]dnsPending, error)
	// Runtime is each attached group's cumulative in-kernel run time (zero without BPF_ENABLE_STATS).
	Runtime() map[string]time.Duration
	// Groups are the program groups attached, sorted.
	Groups() []string
	// Shed detaches one group's programs.
	Shed(group string)
	Close() error
}
