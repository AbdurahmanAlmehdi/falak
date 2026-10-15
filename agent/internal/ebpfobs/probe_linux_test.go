//go:build linux && (amd64 || arm64)

package ebpfobs

import (
	"net"
	"os"
	"os/exec"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// TestLoadAttach loads and attaches every program against the running kernel, makes a TCP connection, a refused
// connect and a DNS query, and reads them back. Needs root and kernel BTF; skipped otherwise (CI runs unprivileged).
func TestLoadAttach(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root")
	}
	if _, err := os.Stat("/sys/kernel/btf/vmlinux"); err != nil {
		t.Skip("no kernel BTF")
	}
	if ok, reason := detect(hostfs.FS{}, bpfWorks); !ok {
		t.Skip("not supported: " + reason)
	}
	src, problems, err := openProbe(hostfs.FS{})
	if err != nil {
		t.Fatalf("open: %v (%v)", err, problems)
	}
	defer src.Close()
	if len(problems) > 0 {
		t.Errorf("groups not attached: %v", problems)
	}
	if g := src.Groups(); len(g) != 3 {
		t.Errorf("groups = %v", g)
	}

	// TCP: an accepted connection carrying data, then a connect refused by a closed port (failed connect, reset).
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	go func() {
		c, err := ln.Accept()
		if err == nil {
			buf := make([]byte, 4)
			_, _ = c.Read(buf)
			_, _ = c.Write(buf)
			_ = c.Close()
		}
	}()
	c, err := net.Dial("tcp", ln.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	_, _ = c.Write([]byte("ping"))
	_, _ = c.Read(make([]byte, 4))
	_ = c.Close()
	_ = ln.Close()
	port := ln.Addr().(*net.TCPAddr).Port
	if c, err := net.Dial("tcp", ln.Addr().String()); err == nil {
		_ = c.Close()
		t.Log("closed port accepted a connection")
	}

	// DNS: a query to a local server on port 53 answered NXDOMAIN, and one never answered.
	srv, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 53})
	if err != nil {
		t.Fatalf("listen :53: %v", err)
	}
	defer srv.Close()
	go func() {
		buf := make([]byte, 512)
		for {
			n, from, err := srv.ReadFromUDP(buf)
			if err != nil {
				return
			}
			q := buf[:n]
			if q[1] == 2 { // the second query (id 0x0002) gets no answer
				continue
			}
			r := append([]byte(nil), q...)
			r[2] |= 0x80 // QR
			r[3] = 3     // NXDOMAIN
			_, _ = srv.WriteToUDP(r, from)
		}
	}()
	query := func(id byte) {
		cl, err := net.DialUDP("udp", nil, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 53})
		if err != nil {
			t.Fatal(err)
		}
		defer cl.Close()
		msg := []byte{0, id, 1, 0, 0, 1, 0, 0, 0, 0, 0, 0, 7, 'e', 'x', 'a', 'm', 'p', 'l', 'e', 4, 't', 'e', 's', 't', 0, 0, 1, 0, 1}
		_, _ = cl.Write(msg)
		_ = cl.SetReadDeadline(time.Now().Add(300 * time.Millisecond))
		_, _ = cl.Read(make([]byte, 512))
	}
	query(1)
	query(2)
	time.Sleep(200 * time.Millisecond)

	var connects, fails, rtts uint64
	if err := src.Peers(func(k peerKey, v peerVal) {
		if k.Port == uint16(port) && k.Dir == dirOut {
			connects += v.Connects
			fails += v.ConnectFail
			rtts += v.RTTCount
		}
	}); err != nil {
		t.Fatal(err)
	}
	if connects < 1 || fails < 1 || rtts < 1 {
		t.Errorf("tcp: connects %d, failed %d, rtt samples %d; want ≥ 1 each", connects, fails, rtts)
	}
	kinds := map[uint32]uint64{}
	if err := src.Hists(func(k histKey, v histVal) { kinds[k.Kind] += v.Count }); err != nil {
		t.Fatal(err)
	}
	if kinds[kindConnect] == 0 || kinds[kindRTT] == 0 || kinds[kindDNS] == 0 || kinds[kindRunq] == 0 {
		t.Errorf("histogram counts = %v", kinds)
	}
	var queries, nx uint64
	if err := src.DNSNames(func(k dnsKey, v dnsVal) {
		if name, _ := decodeQName(k.QName[:]); name == "example.test" {
			queries += v.Queries
			nx += v.NXDomain
		}
	}); err != nil {
		t.Fatal(err)
	}
	if queries != 2 || nx != 1 {
		t.Errorf("dns example.test: %d queries, %d NXDOMAIN; want 2, 1", queries, nx)
	}
	if late, err := src.ExpirePending(100 * time.Millisecond); err != nil || len(late) != 1 {
		t.Errorf("unanswered queries = %d (%v), want 1", len(late), err)
	}
	// An exited process leaves no run-queue stamp behind (sched_process_exit), and the sweep runs.
	cmd := exec.Command("/bin/true")
	if err := cmd.Run(); err == nil {
		var stamp uint64
		if err := src.(*probe).m("runq_start").Lookup(uint32(cmd.Process.Pid), &stamp); err == nil {
			t.Errorf("pid %d exited but kept its run-queue stamp", cmd.Process.Pid)
		}
	}
	if _, err := src.SweepRunq(10 * time.Second); err != nil {
		t.Errorf("sweep: %v", err)
	}
	for g, d := range src.Runtime() {
		t.Logf("in-kernel run time so far: %s %v", g, d)
	}
	if p, ok := src.(*probe); ok {
		for g, progs := range p.progs {
			for _, prog := range progs {
				if s, err := prog.Stats(); err == nil && s.RunCount > 0 {
					t.Logf("%s %s: %d runs, %d ns/run", g, prog.String(), s.RunCount, s.Runtime.Nanoseconds()/int64(s.RunCount))
				}
			}
		}
	}
}

// TestOverheadUnderLoad measures the probe's in-kernel cost per event and per second under a synthetic load (context
// switches, TCP connections with data, DNS queries). Opt-in: FALAK_EBPF_BENCH=1, root and BTF.
func TestOverheadUnderLoad(t *testing.T) {
	if os.Getenv("FALAK_EBPF_BENCH") == "" || os.Geteuid() != 0 {
		t.Skip("set FALAK_EBPF_BENCH=1 and run as root")
	}
	src, _, err := openProbe(hostfs.FS{})
	if err != nil {
		t.Fatal(err)
	}
	defer src.Close()
	p := src.(*probe)
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go func() {
				buf := make([]byte, 64)
				n, _ := c.Read(buf)
				_, _ = c.Write(buf[:n])
				_ = c.Close()
			}()
		}
	}()
	dns, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 53})
	if err != nil {
		t.Fatal(err)
	}
	defer dns.Close()
	go func() {
		buf := make([]byte, 512)
		for {
			n, from, err := dns.ReadFromUDP(buf)
			if err != nil {
				return
			}
			buf[2] |= 0x80
			_, _ = dns.WriteToUDP(buf[:n], from)
		}
	}()
	before := src.Runtime()
	start := time.Now()
	deadline := start.Add(3 * time.Second)
	done := make(chan struct{})
	ping, pong := make(chan int), make(chan int)
	go func() {
		for v := range ping {
			pong <- v
		}
	}()
	go func() {
		defer close(done)
		cl, _ := net.DialUDP("udp", nil, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 53})
		msg := []byte{0, 1, 1, 0, 0, 1, 0, 0, 0, 0, 0, 0, 3, 'a', 'p', 'i', 7, 'e', 'x', 'a', 'm', 'p', 'l', 'e', 0, 0, 1, 0, 1}
		for i := 0; time.Now().Before(deadline); i++ {
			if c, err := net.Dial("tcp", ln.Addr().String()); err == nil {
				_, _ = c.Write([]byte("hello"))
				_, _ = c.Read(make([]byte, 8))
				_ = c.Close()
			}
			msg[1] = byte(i)
			_, _ = cl.Write(msg)
			_ = cl.SetReadDeadline(time.Now().Add(50 * time.Millisecond))
			_, _ = cl.Read(make([]byte, 512))
		}
		_ = cl.Close()
	}()
	for time.Now().Before(deadline) {
		ping <- 1
		<-pong
	}
	close(ping)
	<-done
	wall := time.Since(start)
	after := src.Runtime()
	for g, progs := range p.progs {
		var runs uint64
		var ns time.Duration
		for _, prog := range progs {
			if s, err := prog.Stats(); err == nil {
				runs += s.RunCount
				ns += s.Runtime
			}
		}
		if runs > 0 {
			t.Logf("%-4s %8d runs (%7.0f/s), %4d ns/run, %.3f ms of CPU per s", g, runs, float64(runs)/wall.Seconds(),
				ns.Nanoseconds()/int64(runs), (after[g]-before[g]).Seconds()*1000/wall.Seconds())
		}
	}
}
