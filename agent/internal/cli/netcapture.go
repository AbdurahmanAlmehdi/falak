package cli

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

var captureIDRe = regexp.MustCompile(`^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$`)

const captureWarning = "Packet captures may contain sensitive data (cookies, tokens, personal data). The file is kept encrypted for 24 hours and every download is audited."

// netCaptureAPI is what `falak net capture` needs of the client.
type netCaptureAPI interface {
	StartNetCapture(ctx context.Context, path string, r api.NetCaptureRequest) (api.NetCapture, error)
	NetCapture(ctx context.Context, id string) (api.NetCapture, error)
	CancelNetCapture(ctx context.Context, id string) (api.NetCapture, error)
	DownloadNetCapture(ctx context.Context, id string, w io.Writer) (int64, error)
}

// cmdNetCapture starts a capture, follows it, and downloads the pcap (Ctrl-C cancels the capture).
func cmdNetCapture(ctx context.Context, a *App, args []string) error {
	fs := a.flagSet("net capture")
	server := fs.String("server", "", "capture on this server (name or id)")
	site := fs.String("site", "", "capture where this site runs: its container, or the host for native sites")
	on := fs.String("on", "", "--site: the server (default: the site's leader)")
	service := fs.String("service", "", "--site: the compose service")
	db := fs.String("db", "", "capture in this database container (name or id)")
	out := fs.String("o", "", "write the pcap here (default falak-capture-<id>.pcap; never overwritten)")
	yes := fs.Bool("yes", false, "confirm: "+captureWarning)
	var req api.NetCaptureRequest
	var f api.NetCaptureFilter
	fs.StringVar(&req.Interface, "interface", "", "servers and native sites: default, wireguard or docker")
	fs.IntVar(&req.DurationS, "duration", 0, "seconds (1-60, default 30)")
	fs.IntVar(&req.MaxMB, "max-size", 0, "MiB (1-100, default 20)")
	fs.BoolVar(&req.FullPackets, "full", false, "capture full packets (default: packet headers, the first 96 bytes, which may include the first bytes of a payload)")
	fs.StringVar(&f.Host, "host", "", "filter: an IP or a CIDR")
	fs.IntVar(&f.Port, "port", 0, "filter: a port")
	fs.StringVar(&f.Protocol, "protocol", "", "filter: tcp, udp or icmp")
	fs.StringVar(&f.Direction, "direction", "", "filter: in, out or both")
	if _, err := a.parse(fs, args, 0, 0, "--server NAME|ID | --site SITE [--on SERVER] [--service NAME] | --db INSTANCE --yes [flags]"); err != nil {
		return err
	}
	if !*yes {
		return usagef("net capture: %s Pass --yes to go ahead.", captureWarning)
	}
	if f != (api.NetCaptureFilter{}) {
		req.Filter = &f
	}
	req.Acknowledged = true
	var tools api.NetToolRequest
	if _, err := netPath(*server, *site, *db, *on, *service, &tools); err != nil {
		return err
	}
	req.ServerID, req.Service = tools.ServerID, tools.Service
	path := netCapturePath(*server, *site, *db)
	c, err := a.api()
	if err != nil {
		return err
	}
	cl, ok := c.(netCaptureAPI)
	if !ok {
		return errors.New("this client cannot capture packets")
	}
	capt, err := cl.StartNetCapture(ctx, path, req)
	if err != nil {
		return err
	}
	// The id goes into URLs and the default file name: only ever a ULID, whatever the server answered.
	if !captureIDRe.MatchString(capt.ID) {
		return fmt.Errorf("net capture: the server answered an invalid capture id %q", capt.ID)
	}
	where := "on " + capt.ServerName
	if capt.Container != nil {
		where = "in " + *capt.Container + " " + where
	}
	fmt.Fprintf(a.Stderr, "Capturing %s for %ds (capture %s).\n", where, capt.DurationS, capt.ID)
	for last := ""; !capt.Done(); {
		select {
		case <-ctx.Done():
			// Ctrl-C: cancel the capture (with a fresh context: ctx is gone).
			cctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
			_, cerr := cl.CancelNetCapture(cctx, capt.ID)
			cancel()
			if cerr != nil {
				fmt.Fprintf(a.Stderr, "falak: cancel the capture: %v\n", cerr)
			} else {
				fmt.Fprintln(a.Stderr, "Capture cancelled.")
			}
			return ctx.Err()
		case <-time.After(a.Poll):
		}
		next, err := cl.NetCapture(ctx, capt.ID)
		if err != nil {
			if ctx.Err() != nil {
				continue
			}
			return err
		}
		capt = next
		if line := fmt.Sprintf("%s: %d packets, %d bytes", capt.Status, capt.PacketsCaptured, capt.BytesCaptured); line != last && !a.jsonOut {
			fmt.Fprintln(a.Stderr, line)
			last = line
		}
	}
	if capt.Status != "ready" {
		msg := "the capture " + capt.Status
		if capt.Error != nil {
			msg += ": " + *capt.Error
		}
		return exitError{code: 1, err: errors.New(msg)}
	}
	name := *out
	if name == "" {
		name = "falak-capture-" + capt.ID + ".pcap"
	}
	file, err := os.OpenFile(name, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return err
	}
	n, err := cl.DownloadNetCapture(ctx, capt.ID, file)
	if cerr := file.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		_ = os.Remove(name)
		return err
	}
	if a.jsonOut {
		return a.printJSON(map[string]any{"capture": capt, "file": name, "bytes": n})
	}
	fmt.Fprintf(a.Stdout, "%s (%d bytes, %d packets; available until %s)\n", name, n, capt.PacketsCaptured, capt.ExpiresAt)
	return nil
}

func netCapturePath(server, site, db string) string {
	switch {
	case server != "":
		return api.Path(api.PathServerNetCaptures, "server", server)
	case db != "":
		return api.Path(api.PathDatabaseNetCaptures, "instance", db)
	}
	return api.Path(api.PathSiteNetCaptures, "site", site)
}
