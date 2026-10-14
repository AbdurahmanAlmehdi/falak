package nettool

import (
	"context"
	"fmt"
	"io"
	"net"
	"sort"
	"strings"
	"time"
)

// runDNS looks a name up with the Go resolver and prints dig-like answer lines.
func runDNS(ctx context.Context, s HelperSpec, n *names, w io.Writer) int {
	fmt.Fprintf(w, ";; %s %s via %s\n", s.Target, s.RecordType, n.serverLabel())
	start := time.Now()
	lines, err := dnsAnswers(ctx, s, n)
	took := time.Since(start)
	if err != nil {
		fmt.Fprintf(w, ";; lookup failed after %s: %s\n", ms(took), dnsError(err))
		return 1
	}
	if len(lines) == 0 {
		fmt.Fprintf(w, ";; no %s records (%s)\n", s.RecordType, ms(took))
		return 1
	}
	for _, l := range lines {
		fmt.Fprintln(w, l)
	}
	fmt.Fprintf(w, ";; %d answer(s) in %s\n", len(lines), ms(took))
	return 0
}

func dnsAnswers(ctx context.Context, s HelperSpec, n *names) ([]string, error) {
	name := strings.TrimSuffix(s.Target, ".") + "."
	var out []string
	switch s.RecordType {
	case "A", "AAAA":
		network := "ip4"
		if s.RecordType == "AAAA" {
			network = "ip6"
		}
		if addrs, ok := n.hosts[strings.ToLower(strings.TrimSuffix(s.Target, "."))]; ok {
			for _, a := range addrs {
				if a.Is4() == (network == "ip4") {
					out = append(out, fmt.Sprintf("%s\t%s\t%s\t; hosts file", name, s.RecordType, a))
				}
			}
			if len(out) > 0 {
				return out, nil
			}
		}
		addrs, err := n.r.LookupNetIP(ctx, network, s.Target)
		if err != nil {
			return nil, err
		}
		for _, a := range addrs {
			out = append(out, fmt.Sprintf("%s\t%s\t%s", name, s.RecordType, a.Unmap()))
		}
	case "CNAME":
		c, err := n.r.LookupCNAME(ctx, s.Target)
		if err != nil {
			return nil, err
		}
		if c != "" && !strings.EqualFold(c, name) {
			out = append(out, fmt.Sprintf("%s\tCNAME\t%s", name, c))
		}
	case "MX":
		mx, err := n.r.LookupMX(ctx, s.Target)
		if err != nil {
			return nil, err
		}
		for _, m := range mx {
			out = append(out, fmt.Sprintf("%s\tMX\t%d %s", name, m.Pref, m.Host))
		}
	case "TXT":
		txt, err := n.r.LookupTXT(ctx, s.Target)
		if err != nil {
			return nil, err
		}
		for _, t := range txt {
			out = append(out, fmt.Sprintf("%s\tTXT\t%q", name, t))
		}
	case "NS":
		ns, err := n.r.LookupNS(ctx, s.Target)
		if err != nil {
			return nil, err
		}
		for _, x := range ns {
			out = append(out, fmt.Sprintf("%s\tNS\t%s", name, x.Host))
		}
	case "SRV":
		_, srv, err := n.r.LookupSRV(ctx, "", "", s.Target)
		if err != nil {
			return nil, err
		}
		for _, x := range srv {
			out = append(out, fmt.Sprintf("%s\tSRV\t%d %d %d %s", name, x.Priority, x.Weight, x.Port, x.Target))
		}
	case "PTR":
		hosts, err := n.r.LookupAddr(ctx, s.Target)
		if err != nil {
			return nil, err
		}
		sort.Strings(hosts)
		for _, h := range hosts {
			out = append(out, fmt.Sprintf("%s\tPTR\t%s", s.Target, h))
		}
	}
	return out, nil
}

// dnsError shortens resolver errors ("lookup x on 127.0.0.11:53: no such host" → "no such host (NXDOMAIN)").
func dnsError(err error) string {
	if de, ok := err.(*net.DNSError); ok {
		switch {
		case de.IsNotFound:
			return "no such host (NXDOMAIN or no records)"
		case de.IsTimeout:
			return "timed out (the nameserver did not answer)"
		}
		if de.Server != "" {
			return de.Err + " (server " + de.Server + ")"
		}
		return de.Err
	}
	return err.Error()
}

// ms formats a duration in milliseconds with one decimal below 10 ms.
func ms(d time.Duration) string {
	f := float64(d.Microseconds()) / 1000
	if f < 10 {
		return fmt.Sprintf("%.1f ms", f)
	}
	return fmt.Sprintf("%.0f ms", f)
}
