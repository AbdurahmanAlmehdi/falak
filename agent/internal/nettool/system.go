package nettool

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/netip"
	"os/exec"
	"strconv"
)

// ExitNotInstalled is the exit code when no binary for the tool exists (as a shell's "command not found").
const ExitNotInstalled = 127

// lookPath finds a binary (tests replace it).
var lookPath = exec.LookPath

// pingArgv: ping -n -c <count> -W 2 -w <deadline> <ip>. The deadline bounds the whole run below the tool timeout.
func pingArgv(bin string, s HelperSpec, ip netip.Addr) []string {
	deadline := min(s.TimeoutS, s.Count*2+3)
	return []string{bin, "-n", "-c", strconv.Itoa(s.Count), "-W", "2", "-w", strconv.Itoa(deadline), ip.String()}
}

// tracerouteArgv picks the first installed of traceroute, mtr and tracepath; nil when none is.
func tracerouteArgv(s HelperSpec, ip netip.Addr) []string {
	hops := strconv.Itoa(s.MaxHops)
	if bin, err := lookPath("traceroute"); err == nil {
		return []string{bin, "-n", "-q", "1", "-w", "2", "-m", hops, ip.String()}
	}
	if bin, err := lookPath("mtr"); err == nil {
		return []string{bin, "-n", "-r", "-w", "-c", "3", "-m", hops, ip.String()}
	}
	if bin, err := lookPath("tracepath"); err == nil {
		return []string{bin, "-n", "-m", hops, ip.String()}
	}
	return nil
}

// runSystem resolves the target (so the system tool never resolves with the host's configuration inside a
// container), then runs the tool with stdout and stderr on w (the helper's PTY).
func runSystem(ctx context.Context, s HelperSpec, n *names, w io.Writer) int {
	addrs, err := n.lookupIP(ctx, s.Target)
	if err != nil {
		fmt.Fprintf(w, "resolve %s: %s\n", s.Target, dnsError(err))
		return 2
	}
	ip := addrs[0]
	if _, ipErr := netip.ParseAddr(s.Target); ipErr != nil {
		fmt.Fprintf(w, "target %s → %s (%s)\n", s.Target, ip, n.serverLabel())
	}
	var argv []string
	switch s.Tool {
	case ToolPing:
		bin, lerr := lookPath("ping")
		if lerr != nil {
			fmt.Fprintln(w, "ping is not installed on this server (apt install iputils-ping). The TCP test works without it.")
			return ExitNotInstalled
		}
		argv = pingArgv(bin, s, ip)
	case ToolTraceroute:
		if argv = tracerouteArgv(s, ip); argv == nil {
			fmt.Fprintln(w, "No traceroute is installed on this server (apt install traceroute, or mtr-tiny). The TCP test works without it.")
			return ExitNotInstalled
		}
	}
	cmd := exec.CommandContext(ctx, argv[0], argv[1:]...)
	cmd.Stdout, cmd.Stderr = w, w
	cmd.Env = []string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C.UTF-8"}
	err = cmd.Run()
	var ee *exec.ExitError
	switch {
	case err == nil:
		return 0
	case errors.As(err, &ee):
		if code := ee.ExitCode(); code >= 0 {
			return code
		}
		return 1
	default:
		fmt.Fprintf(w, "run %s: %s\n", argv[0], err)
		return 1
	}
}
