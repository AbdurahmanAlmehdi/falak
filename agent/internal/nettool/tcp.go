package nettool

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"net/netip"
	"os"
	"strconv"
	"syscall"
	"time"
)

// guardedDialer refuses link-local addresses at connect time (after any resolution, so a name pointing at a
// metadata endpoint is refused too).
func guardedDialer(timeout time.Duration) *net.Dialer {
	return &net.Dialer{Timeout: timeout, Control: func(_, address string, _ syscall.RawConn) error {
		ap, err := netip.ParseAddrPort(address)
		if err == nil && linkLocal(ap.Addr()) {
			return errLinkLocal
		}
		return nil
	}}
}

// runTCP connects to target:port count times, one line per attempt.
func runTCP(ctx context.Context, s HelperSpec, n *names, w io.Writer) int {
	addrs, err := n.lookupIP(ctx, s.Target)
	if err != nil {
		fmt.Fprintf(w, "resolve %s: %s\n", s.Target, dnsError(err))
		return 2
	}
	if _, ipErr := netip.ParseAddr(s.Target); ipErr != nil {
		fmt.Fprintf(w, "%s resolves to %s (%s)\n", s.Target, joinAddrs(addrs), n.serverLabel())
	}
	addr := netip.AddrPortFrom(addrs[0], uint16(s.Port)).String()
	failed := 0
	for i := 1; i <= s.Count; i++ {
		if ctx.Err() != nil {
			break
		}
		start := time.Now()
		d := guardedDialer(5 * time.Second)
		c, err := d.DialContext(ctx, "tcp", addr)
		took := time.Since(start)
		if err != nil {
			failed++
			fmt.Fprintf(w, "attempt %d: %s: %s after %s\n", i, addr, tcpError(err), ms(took))
		} else {
			fmt.Fprintf(w, "attempt %d: connected to %s in %s (from %s)\n", i, addr, ms(took), c.LocalAddr())
			c.Close()
		}
		if i < s.Count {
			select {
			case <-ctx.Done():
			case <-time.After(time.Second):
			}
		}
	}
	fmt.Fprintf(w, "%d of %d connections succeeded\n", s.Count-failed, s.Count)
	if failed > 0 {
		return 1
	}
	return 0
}

// tcpError names the usual failures.
func tcpError(err error) string {
	switch {
	case errors.Is(err, errLinkLocal):
		return errLinkLocal.Error()
	case errors.Is(err, syscall.ECONNREFUSED):
		return "connection refused (nothing listens there, or a firewall rejects it)"
	case errors.Is(err, syscall.EHOSTUNREACH), errors.Is(err, syscall.ENETUNREACH):
		return "no route to host"
	case errors.Is(err, os.ErrDeadlineExceeded), errors.Is(err, context.DeadlineExceeded):
		return "timed out (dropped by a firewall, or the host is down)"
	}
	var ne net.Error
	if errors.As(err, &ne) && ne.Timeout() {
		return "timed out (dropped by a firewall, or the host is down)"
	}
	return err.Error()
}

func joinAddrs(addrs []netip.Addr) string {
	s := ""
	for i, a := range addrs {
		if i > 0 {
			s += ", "
		}
		s += a.String()
	}
	return s
}

func portString(p int) string { return strconv.Itoa(p) }
