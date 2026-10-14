package nettool

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"time"
)

// ExitTimeout is the exit code of a run stopped by its timeout (as timeout(1)).
const ExitTimeout = 124

// HelperCommand is the agent subcommand that runs one tool: `falak-agent nettool '<HelperSpec JSON>'`.
const HelperCommand = "nettool"

// Main is the helper: it decodes and validates the spec again (the helper is a separate entry point), then runs the
// tool with its output on w. It returns the process exit code.
func Main(ctx context.Context, args []string, w io.Writer) int {
	if len(args) != 1 {
		fmt.Fprintln(w, "usage: falak-agent nettool '<spec json>'")
		return 2
	}
	var s HelperSpec
	dec := json.NewDecoder(bytes.NewReader([]byte(args[0])))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&s); err != nil {
		fmt.Fprintf(w, "nettool: invalid spec: %s\n", err)
		return 2
	}
	if err := s.Validate(); err != nil {
		fmt.Fprintf(w, "nettool: %s\n", err)
		return 2
	}
	ctx, cancel := context.WithTimeout(ctx, time.Duration(s.TimeoutS)*time.Second)
	defer cancel()
	n, err := newNames(s.Nameserver, s.ResolvConf, s.HostsFile)
	if err != nil {
		fmt.Fprintf(w, "nettool: %s\n", err)
		return 2
	}
	code := run(ctx, s, n, w)
	if ctx.Err() != nil {
		fmt.Fprintf(w, "stopped after %ds (timeout)\n", s.TimeoutS)
		return ExitTimeout
	}
	return code
}

func run(ctx context.Context, s HelperSpec, n *names, w io.Writer) int {
	switch s.Tool {
	case ToolDNS:
		return runDNS(ctx, s, n, w)
	case ToolHTTP:
		return runHTTP(ctx, s, n, w, nil)
	case ToolTCP:
		return runTCP(ctx, s, n, w)
	default: // ping, traceroute
		return runSystem(ctx, s, n, w)
	}
}
