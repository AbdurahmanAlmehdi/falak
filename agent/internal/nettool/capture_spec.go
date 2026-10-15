package nettool

import (
	"errors"
	"fmt"
	"net/netip"
	"regexp"
	"strconv"
	"strings"
)

// Packet capture limits (docs/plans/network/capture.md).
const (
	MaxCaptureS          = 60
	DefaultCaptureS      = 30
	MinCaptureBytes      = 1 << 20
	MaxCaptureBytes      = 100 << 20
	DefaultCaptureBytes  = 20 << 20
	HeadersSnaplen       = 128
	FullSnaplen          = 262144
	minSnaplen           = 64
	captureIfaceDefault  = "default"
	captureIfaceWG       = "wireguard"
	captureIfaceDocker   = "docker"
	captureIfaceAny      = "any"
	captureDirectionIn   = "in"
	captureDirectionOut  = "out"
	captureDirectionBoth = "both"
)

var ulidRe = regexp.MustCompile(`^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$`)

// CaptureSpec is net.capture's payload.
type CaptureSpec struct {
	CaptureID string         `json:"capture_id"`
	Interface string         `json:"interface,omitempty"`
	Container *Container     `json:"container,omitempty"`
	Filter    *CaptureFilter `json:"filter,omitempty"`
	Snaplen   int            `json:"snaplen,omitempty"`
	DurationS int            `json:"duration_s,omitempty"`
	MaxBytes  int64          `json:"max_bytes,omitempty"`
}

// CaptureFilter is the structured filter the BPF expression is built from (never a free-form expression).
type CaptureFilter struct {
	Host      string `json:"host,omitempty"`
	Port      int    `json:"port,omitempty"`
	Protocol  string `json:"protocol,omitempty"`
	Direction string `json:"direction,omitempty"`
}

// Validate checks the spec and fills defaults.
func (s *CaptureSpec) Validate() error {
	if !ulidRe.MatchString(s.CaptureID) {
		return errors.New("capture_id must be a ULID")
	}
	if err := s.Container.validate(); err != nil {
		return err
	}
	switch {
	case s.Container != nil && s.Interface != "":
		return errors.New("interface does not apply to a container (it captures on all of its interfaces)")
	case s.Container == nil && s.Interface == "":
		s.Interface = captureIfaceDefault
	}
	if s.Container == nil && s.Interface != captureIfaceDefault && s.Interface != captureIfaceWG && s.Interface != captureIfaceDocker {
		return errors.New("interface must be default, wireguard or docker")
	}
	if s.DurationS == 0 {
		s.DurationS = DefaultCaptureS
	}
	if s.DurationS < 1 || s.DurationS > MaxCaptureS {
		return fmt.Errorf("duration_s must be 1–%d", MaxCaptureS)
	}
	if s.MaxBytes == 0 {
		s.MaxBytes = DefaultCaptureBytes
	}
	if s.MaxBytes < MinCaptureBytes || s.MaxBytes > MaxCaptureBytes {
		return fmt.Errorf("max_bytes must be %d–%d", MinCaptureBytes, MaxCaptureBytes)
	}
	switch {
	case s.Snaplen == 0:
		s.Snaplen = HeadersSnaplen
	case s.Snaplen < minSnaplen || s.Snaplen > FullSnaplen:
		return fmt.Errorf("snaplen must be %d–%d", minSnaplen, FullSnaplen)
	}
	return s.Filter.validate()
}

func (f *CaptureFilter) validate() error {
	if f == nil {
		return nil
	}
	if f.Host != "" {
		if _, err := parseHostFilter(f.Host); err != nil {
			return err
		}
	}
	if f.Port != 0 && (f.Port < 1 || f.Port > 65535) {
		return errors.New("filter.port must be 1–65535")
	}
	switch f.Protocol {
	case "", "tcp", "udp":
	case "icmp":
		if f.Port != 0 {
			return errors.New("filter.port does not apply to icmp")
		}
	default:
		return errors.New("filter.protocol must be tcp, udp or icmp")
	}
	switch f.Direction {
	case "", captureDirectionBoth, captureDirectionIn, captureDirectionOut:
	default:
		return errors.New("filter.direction must be in, out or both")
	}
	return nil
}

// parseHostFilter accepts an IP or a CIDR (never a name: tcpdump would resolve it with the host's resolver).
func parseHostFilter(s string) (netip.Prefix, error) {
	if a, err := netip.ParseAddr(s); err == nil && a.Zone() == "" {
		return netip.PrefixFrom(a, a.BitLen()), nil
	}
	if p, err := netip.ParsePrefix(s); err == nil {
		return p.Masked(), nil
	}
	return netip.Prefix{}, errors.New("filter.host must be an IP address or a CIDR")
}

// Expression is the BPF expression built from the validated filter ("" captures everything): fixed keywords joined
// with "and", values that passed netip and integer checks. It goes to tcpdump as one argv element after "--".
func (f *CaptureFilter) Expression() string {
	if f == nil {
		return ""
	}
	var parts []string
	if f.Host != "" {
		p, _ := parseHostFilter(f.Host)
		if p.IsSingleIP() {
			parts = append(parts, "host "+p.Addr().String())
		} else {
			parts = append(parts, "net "+p.String())
		}
	}
	switch f.Protocol {
	case "tcp", "udp":
		parts = append(parts, f.Protocol)
	case "icmp":
		parts = append(parts, "(icmp or icmp6)")
	}
	if f.Port != 0 {
		parts = append(parts, "port "+strconv.Itoa(f.Port))
	}
	return strings.Join(parts, " and ")
}

// tcpdumpArgv is the fixed template: -n no lookups, -p never promiscuous, -U flush per packet, the pcap on stdout,
// root dropped to user right after the device is open.
func tcpdumpArgv(bin, iface string, s CaptureSpec, user string) []string {
	argv := []string{bin, "-n", "-p", "-U", "-i", iface, "-s", strconv.Itoa(s.Snaplen), "-w", "-", "-Z", user}
	if s.Filter != nil && (s.Filter.Direction == captureDirectionIn || s.Filter.Direction == captureDirectionOut) {
		argv = append(argv, "-Q", s.Filter.Direction)
	}
	if expr := s.Filter.Expression(); expr != "" {
		argv = append(argv, "--", expr)
	}
	return argv
}
