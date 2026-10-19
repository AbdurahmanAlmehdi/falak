# Tailscale: VPN-only sites and panel

Goal: put chosen sites, and the control-plane panel, behind the organization's Tailscale tailnet with a few clicks.

## How traffic gets in (the one idea)

Every Falak server that joins the tailnet **advertises its own public IPs as subnet routes** (`<ipv4>/32`,
`<ipv6>/128`). A tailnet device that accepts routes then sends traffic for that server's public IP through the
tunnel: it arrives on `tailscale0` with the device's tailnet source address (`100.64.0.0/10` /
`fd7a:115c:a1e0::/48`). Servers run with `--snat-subnet-routes=false`, so the source stays the device's tailnet
address both for local delivery and for Docker-published ports (which are forwarded after DNAT).

So nothing about DNS or certificates changes:

- DNS stays the public A / AAAA records; Let's Encrypt HTTP-01 still reaches the server (the edge answers ACME
  challenges before routes and the allowlist).
- Agents, webhooks and Let's Encrypt keep using the public path.
- A "Tailscale only" site is just an edge allowlist of the tailnet ranges.

Requirements on the tailnet (shown in the panel as setup steps):

1. A reusable, pre-approved auth key, ideally tagged `tag:falak`.
2. Routes auto-approved for that tag, or approved by hand in the admin console:
   `"autoApprovers": {"routes": {"0.0.0.0/0": ["tag:falak"], "::/0": ["tag:falak"]}}`
   (the 0/0 key only means "any route this tag advertises"; servers advertise only their own /32 and /128).
3. Devices accept routes (macOS / Windows / iOS / Android by default; Linux `tailscale set --accept-routes`).

Falak servers themselves never accept routes (`--accept-routes=false`): server-to-server, WireGuard and agent traffic
stays on the public path.

## Agent: `net.tailscale.apply` (feature `net.tailscale`)

Payload:

```json
{
  "state": "present",
  "version": "1.104.1",
  "url": "https://pkgs.tailscale.com/stable/tailscale_1.104.1_amd64.tgz",
  "sha256": "<of the tarball>",
  "auth_key": "tskey-auth-...",
  "hostname": "falak-main-1",
  "advertise_routes": ["62.171.130.153/32", "2a02:c207::1/128"]
}
```

`{"state": "absent"}` logs the node out of the tailnet and removes everything.

- Installs `tailscale` and `tailscaled` from the pinned, checksum-verified static tarball into `/usr/local/bin`
  (marker `/etc/falak/tailscale.version`, like cloudflared), unit `falak-tailscaled.service`
  (`tailscaled --state=/var/lib/tailscale/tailscaled.state --socket=/run/tailscale/tailscaled.sock --port=41641`).
- Runs `tailscale up --auth-key=file:<0600 temp file> --hostname=<h> --advertise-routes=<csv> --accept-routes=false
  --accept-dns=false --reset`; the temp key file is removed afterwards (the key is never stored on the server).
- Idempotent: re-sent to refresh (status, route approval) without re-downloading.
- Result: `{changed, active, version, backend_state, hostname, ipv4, ipv6, routes_approved}` from
  `tailscale status --json` (`routes_approved`: every advertised route is in `Self.PrimaryRoutes`).

## Control plane

Network module:

- `network_tailscale_settings` (one per organization): `auth_key` (encrypted), timestamps.
- `network_tailscale_nodes` (one per server): `status` (installing | active | error | removing), `hostname`, `ipv4`,
  `ipv6`, `routes_approved`, `version`, `error`, `command_id`.
- Settings → Network → Tailscale: save / replace / remove the auth key, setup steps, nodes list.
- Server → Network: join / leave / refresh the tailnet.
- The firewall accepts everything on `tailscale0` while the server has a node (like a private network's interface)
  plus UDP 41641 for direct connections.
- Contract `Tailnet::joined(serverId)` for other modules.

Edge module:

- Site setting `tailnet_only`. On, the edge allowlist becomes the tailnet ranges (plus the site's own "Allow only"
  entries, plus the control plane's public IPs so health / uptime checks still pass). Turning it on requires every
  server of the site to be on the tailnet.

## Panel (control plane machine)

`falak-ctl panel-access tailnet|public|status`: sets `FALAK_PANEL_ALLOW` in `/opt/falak/.env` and recreates the
edge. With `tailnet`, browser paths on `FALAK_DOMAIN` answer 403 outside the tailnet ranges; machine paths stay
public: `/agent/*`, `/install/*`, `/api/internal/*`, `/api/v1/*`, `/otlp/*`, `/terminal/v1/*`, `/webhooks/*`, `/up`
and public status pages. Refuses `tailnet` unless the host is on a tailnet (`tailscale ip -4`). The operator can
always switch back over SSH.

## Not in this release

Tailscale API (auto-approving routes, listing devices), MagicDNS names, `tailscale serve`, falak.yaml support.
