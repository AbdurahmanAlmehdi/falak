package netobs

import (
	"context"
	"errors"
	"net"
	"strconv"
	"sync"
	"syscall"
	"time"
)

const (
	probeTimeout     = 3 * time.Second
	probeConcurrency = 8
)

// dialFunc opens a TCP connection (net.Dialer.DialContext; replaced in tests).
type dialFunc func(ctx context.Context, network, address string) (net.Conn, error)

// runProbes connects to every target once (probeTimeout each, probeConcurrency at a time) and measures the time to a
// SYN-ACK or a RST: a refused connection still measured the round trip. Nothing is sent; the connection is closed
// right away.
func runProbes(ctx context.Context, dial dialFunc, targets []ProbeTarget, now func() time.Time) []ReportProbe {
	out := make([]ReportProbe, len(targets))
	sem := make(chan struct{}, probeConcurrency)
	var wg sync.WaitGroup
	for i, t := range targets {
		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer wg.Done()
			defer func() { <-sem }()
			out[i] = probe(ctx, dial, t, now)
		}()
	}
	wg.Wait()
	return out
}

func probe(ctx context.Context, dial dialFunc, t ProbeTarget, now func() time.Time) ReportProbe {
	r := ReportProbe{Name: t.Name, Kind: t.Kind, Host: t.Host, Port: t.Port}
	pctx, cancel := context.WithTimeout(ctx, probeTimeout)
	defer cancel()
	start := now()
	conn, err := dial(pctx, "tcp", net.JoinHostPort(t.Host, strconv.Itoa(t.Port)))
	elapsed := now().Sub(start)
	ms := float64(elapsed.Microseconds()) / 1000
	switch {
	case err == nil:
		_ = conn.Close()
		r.OK, r.ConnectMS = true, &ms
	case errors.Is(err, syscall.ECONNREFUSED):
		r.OK, r.Refused, r.ConnectMS = true, true, &ms
	case errors.Is(err, context.DeadlineExceeded) || isTimeout(err):
		r.Error = "timeout"
	default:
		r.Error = probeError(err)
	}
	return r
}

func isTimeout(err error) bool {
	var ne net.Error
	return errors.As(err, &ne) && ne.Timeout()
}

// probeError keeps the cause short and without addresses (the target is in the report already).
func probeError(err error) string {
	switch {
	case errors.Is(err, syscall.EHOSTUNREACH):
		return "host unreachable"
	case errors.Is(err, syscall.ENETUNREACH):
		return "network unreachable"
	case errors.Is(err, syscall.ECONNRESET):
		return "connection reset"
	}
	var dnsErr *net.DNSError
	if errors.As(err, &dnsErr) {
		return "name not resolved"
	}
	return "connect failed"
}
