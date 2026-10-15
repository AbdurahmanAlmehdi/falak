package cli

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

// Exit codes of `falak net` beyond the tool's own: a run stopped by its timeout (as timeout(1)).
const netExitTimeout = 124

const netWhere = "--server NAME|ID | --site SITE [--on SERVER] [--service NAME] | --db INSTANCE [--timeout S]"

func netCommands() *command {
	return &command{name: "net", summary: "network tools from a server, a site's container or a database container (audited)", subs: []*command{
		{name: "ping", args: "<host> [--count N] " + netWhere, summary: "ping a host", run: cmdNet("ping")},
		{name: "traceroute", args: "<host> [--max-hops N] " + netWhere, summary: "trace the route to a host", run: cmdNet("traceroute")},
		{name: "dns", args: "<name> [--type A|AAAA|CNAME|MX|TXT|NS|SRV|PTR] [--nameserver IP] " + netWhere, summary: "look a name up with the resolver there", run: cmdNet("dns")},
		{name: "http", args: "<url> [--method GET|HEAD] [--connect-to IP] [--insecure] " + netWhere, summary: "request a URL with a timing breakdown (curl -v)", run: cmdNet("http")},
		{name: "tcp", args: "<host> <port> [--count N] " + netWhere, summary: "test TCP connections to host:port", run: cmdNet("tcp")},
		{name: "capture", args: "--yes [--interface default|wireguard|docker] [--duration S] [--max-size MiB] [--full] [--host IP|CIDR] [--port N] [--protocol tcp|udp|icmp] [--direction in|out|both] [-o FILE] " + strings.TrimSuffix(netWhere, " [--timeout S]"), summary: "capture packets for up to 60 s and download the pcap (Admin, audited)", run: cmdNetCapture},
	}}
}

func cmdNet(tool string) func(ctx context.Context, a *App, args []string) error {
	return func(ctx context.Context, a *App, args []string) error {
		fs := a.flagSet("net " + tool)
		server := fs.String("server", "", "run on this server's host network (name or id)")
		site := fs.String("site", "", "run where this site runs: its container, or the host for native sites")
		on := fs.String("on", "", "--site: the server (default: the site's leader)")
		service := fs.String("service", "", "--site: the compose service")
		db := fs.String("db", "", "run in this database container (name or id)")
		timeout := fs.Int("timeout", 0, "seconds before the tool is stopped (1-60, default 30)")
		req := api.NetToolRequest{Tool: tool}
		synopsis, pos := "<host> "+netWhere, 1
		switch tool {
		case "ping":
			fs.IntVar(&req.Count, "count", 0, "echo requests (1-20, default 4)")
		case "traceroute":
			fs.IntVar(&req.MaxHops, "max-hops", 0, "hops (1-30, default 20)")
		case "dns":
			fs.StringVar(&req.RecordType, "type", "", "record type (default A)")
			fs.StringVar(&req.Nameserver, "nameserver", "", "ask this nameserver (an IP)")
			synopsis = "<name> " + netWhere
		case "http":
			fs.StringVar(&req.Method, "method", "", "GET or HEAD (default GET)")
			fs.StringVar(&req.ConnectTo, "connect-to", "", "connect to this IP instead of resolving the host")
			fs.BoolVar(&req.Insecure, "insecure", false, "skip certificate verification")
			synopsis = "<url> " + netWhere
		case "tcp":
			fs.IntVar(&req.Count, "count", 0, "connection attempts (1-10, default 3)")
			synopsis, pos = "<host> <port> "+netWhere, 2
		}
		p, err := a.parse(fs, args, pos, pos, synopsis)
		if err != nil {
			return err
		}
		switch tool {
		case "http":
			req.URL = p[0]
		case "tcp":
			req.Target = p[0]
			if req.Port, err = strconv.Atoi(p[1]); err != nil {
				return usagef("net tcp: the port must be a number")
			}
		default:
			req.Target = p[0]
		}
		req.TimeoutS = *timeout
		path, err := netPath(*server, *site, *db, *on, *service, &req)
		if err != nil {
			return err
		}
		c, err := a.api()
		if err != nil {
			return err
		}
		cl, ok := c.(netAPI)
		if !ok {
			return errors.New("this client cannot run network tools")
		}
		run, err := cl.StartNetTool(ctx, path, req)
		if err != nil {
			return err
		}
		if !a.jsonOut {
			where := "on " + run.ServerName
			if run.Container != nil {
				where = "in " + *run.Container + " " + where
			}
			fmt.Fprintf(a.Stderr, "Running %s %s.\n", tool, where)
		}
		return a.followNet(ctx, cl, run)
	}
}

// netAPI is what `falak net` needs of the client (not part of api.API: one implementation, nothing to fake).
type netAPI interface {
	StartNetTool(ctx context.Context, path string, r api.NetToolRequest) (api.NetToolRun, error)
	NetToolRun(ctx context.Context, id string, after int64) (api.NetToolRun, error)
}

// netPath is the endpoint for exactly one of --server, --site and --db.
func netPath(server, site, db, on, service string, req *api.NetToolRequest) (string, error) {
	n := 0
	for _, s := range []string{server, site, db} {
		if s != "" {
			n++
		}
	}
	switch {
	case n != 1:
		return "", usagef("net: run it somewhere: exactly one of --server, --site and --db")
	case (on != "" || service != "") && site == "":
		return "", usagef("net: --on and --service apply to --site")
	case server != "":
		return api.Path(api.PathServerNetTools, "server", server), nil
	case db != "":
		return api.Path(api.PathDatabaseNetTools, "instance", db), nil
	}
	req.ServerID, req.Service = on, service
	return api.Path(api.PathSiteNetTools, "site", site), nil
}

// followNet prints the run's output as it arrives and exits with the tool's code.
func (a *App) followNet(ctx context.Context, c netAPI, run api.NetToolRun) error {
	after := int64(-1)
	errs := 0
	var lines []api.OutputLine
	for {
		r, err := c.NetToolRun(ctx, run.ID, after)
		if err != nil {
			if ctx.Err() != nil {
				return ctx.Err()
			}
			if errs++; errs > 5 {
				return err
			}
			fmt.Fprintf(a.Stderr, "falak: %v (retrying)\n", err)
		} else {
			errs = 0
			if r.LastSeq > after {
				after = r.LastSeq
			}
			for _, l := range r.Lines {
				if a.jsonOut {
					lines = append(lines, l)
				} else {
					fmt.Fprint(a.Stdout, stripControl(l.Data))
				}
			}
			if r.Done() {
				if a.jsonOut {
					r.Lines = lines
					if err := a.printJSON(r); err != nil {
						return err
					}
				}
				return netExit(r)
			}
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(a.Poll):
		}
	}
}

// stripControl drops C0 and C1 control characters (and DEL) except newline and tab from tool output before it reaches
// the user's terminal: what a probed server answers must never move the cursor or retitle the window.
func stripControl(s string) string {
	return strings.Map(func(r rune) rune {
		if (r < 0x20 && r != '\n' && r != '\t') || (r >= 0x7f && r <= 0x9f) {
			return -1
		}
		return r
	}, s)
}

func netExit(r api.NetToolRun) error {
	code := 0
	if r.ExitCode != nil {
		code = *r.ExitCode
	}
	switch {
	case r.Status == "succeeded":
		return nil
	case r.Status == "timed_out":
		return exitError{code: netExitTimeout}
	case code == 0:
		msg := "the tool failed"
		if r.Error != nil {
			msg = *r.Error
		}
		return exitError{code: 1, err: errors.New(msg)}
	}
	return exitError{code: code}
}
