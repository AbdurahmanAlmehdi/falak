# Network phase 4, stream C: Protection (v0.17.x)

Acting on what streams A (connections) and B (HTTP analytics) find: block an IP or CIDR from the panel, SSH brute-force
detection with an optional auto-ban, hit counters on every Falak firewall rule, and path rate limits at the edge. Index and shared rules:
[OBSERVABILITY.md, phase 4](../OBSERVABILITY.md#phase-4-network-v017x).

| Piece | Where | Changes traffic? |
|---|---|---|
| Block an IP / CIDR (per server or all servers, with expiry, reason, audit) | Network → Blocked IPs, the `network.ip-actions` slot on A's and B's rows | Only when a user blocks |
| SSH brute force → `security` Issues | agent (auth log) → `net.protection.observed` → Insights | No |
| Auto-ban (N failures in M minutes → block for T) | per server, Network → Blocked IPs | Only when a user turns it on (off by default) |
| Firewall rule and block-set counters | agent (`nft -j`) → `falak.net.firewall.*` + the Firewall tab | No |
| Rate-limit a path at the edge (per site, requests per client IP per window) | Network → Rate limits, the edge (Falak's Caddy build) | Only for sites with rules |

Agent features: `net.protection`, `edge.rate_limit`. Permissions: `network.protect` (admins only; the list needs
`network.view`), rate limits `edge.manage` (the list `edge.view`).

## Blocking

### Where packets are dropped

Blocks live in their own nftables table, never in `table inet falak`:

```
table inet falak_protect {
    set falak_block4 { type ipv4_addr; flags interval, timeout; counter; }
    set falak_block6 { type ipv6_addr; flags interval, timeout; counter; }
    chain prerouting {
        type filter hook prerouting priority raw; policy accept;
        ip saddr @falak_block4 counter drop comment "falak:block4"
        ip6 saddr @falak_block6 counter drop comment "falak:block6"
    }
}
```

- **Prerouting at `raw` priority (-300)**, not the `input` hook: Docker publishes container ports with DNAT
  (`nat` prerouting, priority -100), so those packets are *forwarded*, never seen by `input`. `DOCKER-USER` (which
  `db/firewall.go` uses for database ports) only sees forwarded traffic and runs after DNAT. A drop at raw prerouting
  happens before conntrack, DNAT and routing, so one rule covers the host, native sites, the edge **and** published
  container ports, whatever Docker uses (iptables-nft or legacy: they hang on the same netfilter hooks, and a drop in
  any base chain is final). Established connections from a blocked address are cut too (it is a block, not a
  "no new connections").
- **Its own table**: `net.firewall.apply` replaces `table inet falak` atomically (`delete table` + redefine), which would
  wipe set elements and counters. The protection table is created only when `nft list` says it is missing; one that
  exists but lacks its chain is completed in place (the definition only adds), and one the agent cannot read, or that
  has its chain but not its sets, is left alone with an error. The agent never deletes it.
- **Timeouts on elements** (`timeout 3600s`): the kernel expires a block; no cron, no command at expiry. Permanent
  blocks have no timeout. Per-element `counter` gives hits per block (the set's `counter` needs nftables ≥ 0.9.5;
  if the kernel or nft refuses it, the agent creates the sets without it and per-block hits stay empty).
- **Hot updates**: `nft add element` / `nft delete element` with argument arrays (`runner.Cmd{Args}`, never a shell
  string); every element is a `netip.Prefix` re-printed by Go, so nothing from the payload reaches nft verbatim.
- **Reboots**: the agent keeps the desired state in `/var/lib/falak/protection.json` (absolute expiry times) and
  restores the table and the remaining timeouts at start. Between boot and the agent's start (seconds) blocks are not
  active; the firewall itself (`falak-firewall.service`) is.
- **Overlaps**: an interval set refuses overlapping elements. The control plane refuses a block that overlaps an
  active one in the same reach ("already covered by 203.0.113.0/24"); an exact duplicate updates the existing block
  (expiry, reason). The agent applies elements in one batch and, if nft refuses it, one by one, reporting the
  elements it could not add (an auto-ban inside a CIDR the user just blocked, a race) instead of failing them all.

### `net.protection.apply` (control plane → agent)

The full desired state of the server, deduplicated by hash like the firewall (`network_protection_states`:
revision, desired / applied hash, status, command id, error). Idempotency key `net.protection:<server>:<revision>`.

```json
{
  "blocks": [{"cidr": "203.0.113.7/32", "expires_at": "2026-10-16T10:00:00Z"}, {"cidr": "2001:db8:1::/48", "expires_at": null}],
  "release": ["198.51.100.9/32"],
  "never_block": ["192.0.2.10/32", "10.90.0.0/24"],
  "auto_ban": {"enabled": true, "failures": 10, "window_s": 600, "ban_s": 3600}
}
```

- `blocks`: the server's own blocks plus the organization-wide ones and the server's auto-bans, active only (≤ 10,000).
- `release`: auto-bans a user lifted (or a manual block replaced, or a guard suspended): the agent forgets them from its
  local state too (it would re-add them otherwise); cleared once an apply succeeds.
- `never_block`: the guard list (below), so the agent can refuse auto-bans the control plane would refuse.
- Result: `{changed, added, removed, failed: [{cidr, error}], set_counters}`.

The agent diffs against the live sets (`nft -j list set`): elements no longer wanted are deleted, missing ones added
with their remaining timeout, and an element whose expiry moved by more than a minute is re-added. Unchanged elements
keep their counters. Old agents never get the command: the control plane only dispatches it to agents with
`net.protection` and shows "update the agent" otherwise.

**Convergence.** A block or unblock converges the servers it reaches. What the guards protect changes elsewhere, so
protection is also re-converged (cheaply: the guard list is hashed and compared) when a server is provisioned or its
addresses change (`ServerProvisioned`, `ServerUpdated`), when a private network changes (`PrivateNetworkChanged`), and
when an agent comes back online with an apply pending or failed (`AgentCameOnline`). Every ten minutes
`network:converge-protection` converges every server that has protection: a failed apply (an unblock included) is
retried until the agent confirms, and one in flight for more than ten minutes is sent again.

**Suspension.** Before compiling, every active block is checked against the guards again: one that now covers a
protected address (a server provisioned later, a member's new address, a private network) is suspended — no longer
sent, marked on its row (`suspended_reason`, shown as "lifted" in the list), audited (`network.ip_block_suspended`) and
alerted (`network.block_suspended`, warning). A suspended auto-ban is released on its server too.

### Guards (control plane, and again on the agent)

Refused outright:

- `0.0.0.0/0`, `::/0`, and prefixes shorter than /8 (IPv4) or /16 (IPv6);
- loopback, unspecified, link-local, multicast;
- a prefix containing **the requester's own IP** (from the request, behind the trusted proxies);
- a prefix containing **a control-plane address**: `network.protection.control_plane_addresses`
  (`FALAK_CONTROL_PLANE_IPS`) plus the A / AAAA records of the panel and agent API hosts;
- a prefix containing **any server of the organization** (public IPv4 / IPv6, private IPv4) or a **WireGuard member
  address** / private network CIDR;
- a prefix containing an address **a member of the organization used the panel from in the last 30 days** (their
  sessions and audited actions, `network.protection.member_address_days`): a teammate is never locked out.

The requester's address is unmapped first (`::ffff:203.0.113.7` from a dual-stack socket is `203.0.113.7`). Configured
`FALAK_CONTROL_PLANE_IPS` entries that are not addresses or CIDRs are logged and ignored.

Need `confirm: true` (the dialog shows a "block anyway" checkbox with the reason):

- private ranges (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7): Docker networks, load balancers and peers live
  there;
- broad prefixes: shorter than /16 (IPv4) or /48 (IPv6);
- **a peer the server connects to** (stream A's "talks to" rows, `outbound: true`): blocking it also cuts the server's
  own connections to it (an API, a database, a package mirror).

A manual block that covers an agent's auto-ban replaces it: the auto-ban row is deleted and released on its server.
Overlaps with other manual blocks are refused (the interval set cannot hold both).

Agent side, defense in depth: never adds an element that contains a loopback / link-local / unspecified address,
the host's own interface addresses, **its DNS servers** (`/etc/resolv.conf` and systemd-resolved's upstream list
`/run/systemd/resolve/resolv.conf`) **and default gateways** (`/proc/net/route`, `/proc/net/ipv6_route`), the control
plane's resolved addresses or a `never_block` entry, and never adds a /0. An element that becomes guarded later is
removed. Auto-bans additionally skip private ranges and addresses that logged in successfully over SSH in the last
24 h (kept in `protection.json`, so a restart or an upgrade does not forget them).

Limits: 30 block changes a minute per user, blocks and unblocks alike (a block reaching a site's servers is one change;
`network.protection.changes_per_minute`), 5,000 active blocks per organization (`network.protection.max_blocks`). Every
block, unblock, auto-ban, suspension and settings change is audited (`network.ip_blocked`, `network.ip_unblocked`,
`network.ip_auto_banned`, `network.ip_block_suspended`, `network.protection_updated`).

### Data (Network module)

- `network_ip_blocks`: organization, `server_id` (null = all servers), `cidr` (canonical), `family`, `reason`,
  `source` (`manual` | `auto`), `expires_at` (null = permanent), `created_by`, `meta` (auto-ban failures, the site it
  was blocked from), `suspended_reason` / `suspended_at`. Expired rows are pruned after 7 days (daily
  `network:prune-blocks`).
- `network_ip_block_hits`: block × server: packets, bytes, `last_hit_at` (cumulative counters as the agent reports
  them; a re-added element restarts at 0, a smaller value replaces the stored one).
- `network_protection_states`: per server: auto-ban settings (`auto_ban`, `failures`, `window_s`, `ban_s`) and the
  apply state.
- `network_firewall_states.counters` / `counters_at`: the latest rule counters (`{rule id: {packets, bytes}}`).

### UI

- **Network → Blocked IPs** (`/network/blocked`): active blocks (address, reach, reason, source, expires, hits, last
  hit, by whom) with Unblock; "Block an address"; per server: agent support, apply status and the auto-ban settings.
- **`network.ip-actions` slot** (`registerIpActions` / `ipActionsFor` in `@/lib/registry`, defined by streams A and
  B): the Network tab's connection rows and the HTTP tab's client rows render the registered actions with
  `{ip, serverId: string | null, siteId?, context: "talks_to" | "clients" | "http_clients" | "http_threats"}`; this
  stream registers "Block" (`network.block`, permission `network.protect`). Its component only adds optional props
  (`source`, `direction`), so it fits the slot as defined. A `talks_to` row (a peer the server connects to) shows a
  warning and is sent with `outbound: true` (the server asks for confirmation). With `serverId` the block can be for
  that server; with `siteId`, for the site's servers (one block per server); always for every server.
- The auto-ban dialog says that turning it off keeps existing bans until they expire (unblock them to lift them now).
- **Firewall tab**: packets / bytes next to each rule, plus the block-set drops.

## SSH brute force

The agent reads sshd's messages every minute: `journalctl -t sshd -t sshd-session -t sshd-auth -o json --after-cursor`
(Ubuntu ≥ 24.04 and Debian 12 log sshd to the journal only; OpenSSH ≥ 9.8 logs authentication from `sshd-session`),
falling back to `/var/log/auth.log` (offset + inode, rotation-safe) when journalctl is missing or the journal has no
storage ("No journal files were found"). In auth.log only the syslog program field decides that a line is sshd's (a
message of another program that mentions `sshd[1]:` is not). At most 5,000 lines a pass; the first pass only sets the
cursor.

**The user name is the attacker's text.** Every pattern is anchored at both ends and takes the user name greedily, so the
address is always the line's *last* `from <ip> port <n>`: `Invalid user x from 192.0.2.66 port 1 from 203.0.113.9
port 22` counts 203.0.113.9, never the framed 192.0.2.66 (which would otherwise get a victim banned).

**One failure per connection.** Lines are grouped by sshd's PID (journald `_PID`, the syslog `sshd[PID]`): a connection
that tries 4 passwords, or logs `Invalid user` then `Failed password for invalid user`, is one failure. IPv6 sources are
counted (and banned) per /64; IPv4 per address. Counts live in ten buckets per window per source (bounded memory:
10,000 sources, 40,000 remembered connections).

| Line | Counted |
|---|---|
| `Invalid user X from IP port N` | yes |
| `Failed password|keyboard-interactive/pam|none for X from IP port N ssh2` | yes (an existing user) |
| `Failed password for invalid user X …` | no (its `Invalid user` line counted the connection) |
| `error: maximum authentication attempts exceeded for invalid user X from IP port N ssh2 [preauth]` | yes |
| `error: maximum authentication attempts exceeded for X …` (an existing user) | no (its `Failed` lines did) |
| `Connection closed by / Disconnected from authenticating user X IP port N [preauth]` | no (nothing failed) |
| `message repeated N times: [ … ]` (rsyslog) | N times the inner line, once per connection when the PID is known |
| `Accepted publickey|password|… for X from IP port N ssh2` | no: the address is trusted for 24 h (never auto-banned) |
| PAM `authentication failure`, `Received disconnect`, `Did not receive identification`, `Unable to negotiate` | no |

`net.protection.observed` (agent → control plane, once a minute when there is something to say):
`{at, window_s, auth: {source, sources: [{ip, failures, invalid_users, users, last_at}]}, bans: [{cidr, until,
failures}], rules: [{id, packets, bytes}], blocks: [{cidr, packets, bytes}], blocked: {packets, bytes}}`; the top 100
sources by failures, 5 sample user names each. `bans` carries **every active auto-ban** each time (not only the
minute's), so a report that never arrived loses nothing: the control plane upserts them (audited when new) and never
recreates one it released. Rule ids are bounded to the schema (`[A-Za-z0-9_-]{1,100}`). Old control planes answer
404: the agent logs it once.

Control plane:

- **Issue** (Insights kind `security`, written through stream A's `SecurityIssues` contract, source `protection.ssh_bruteforce`, key `sshd`): one per server,
  fingerprint `sha256(<server>|protection.ssh_bruteforce|sshd)`, an occurrence per report where the server saw ≥ `ssh_issue_min_failures` (20)
  failures, title "SSH brute force on <server>", sample = the top sources. A resolved Issue regresses when it starts
  again.
- **Auto-ban** (per server, off by default; defaults 10 failures in 10 minutes → 1 hour): the agent decides (it sees
  the attempts as they happen and needs no round trip), adds the address to the same set with the same guards, and
  reports it in `bans`; the control plane records a `source: auto` block (audited as the system) so it is listed and
  can be lifted. At most 60 bans a minute per server; an address already covered by a block is not banned again.

## Firewall rule counters

`net.firewall.apply` gains `counters: true` (stripped for agents without `net.protection`): the agent renders
`counter` before each rule's verdict (and on the SSH rule). The ruleset file changes once, so the next apply reloads
the table (atomic, same rules). When an agent reports `net.protection` for the first time (`AgentVersionChanged`), the
control plane re-sends the firewall so counters appear without a rule change.

The agent reads `nft -j list table inet falak` and `inet falak_protect` each minute:

| Metric | Labels |
|---|---|
| `falak.net.firewall.packets`, `falak.net.firewall.bytes` (cumulative) | `falak.firewall.rule` (rule id, `ssh`) |
| `falak.net.firewall.blocked.packets`, `.bytes` (cumulative) | `network.type` (`ipv4`, `ipv6`) |
| `falak.net.protection.blocks` (gauge) | `network.type` |
| `falak.net.auth.failures` (cumulative) | — |

No addresses as labels (shared rule): per-block hits travel in `net.protection.observed`.

## Path rate limits at the edge

Network → Rate limits (`/network/rate-limits`, Edge module, `edge.manage`): per site, `{path, events, window_s}` —
at most `events` requests per client IP to `path` (exact, or a prefix with a trailing `*`) within `window_s` seconds;
more get **429** with `Retry-After`. Up to 20 rules a site; validated on both sides (no spaces, quotes or
`{placeholders}` in the path; events 1–100,000; window 1 s–1 day). Audited (`edge.rate_limit_saved`,
`edge.rate_limit_removed`). They complement the Cloudflare rate limits (proxied domains only) and work for every site.

**Rendering.** `edge.caddy.apply sites[].rate_limits` is set only on the routes of sites that have rules (not on
backends behind a load balancer: the balancer enforces them); the agent renders one `rate_limit` handler
(`github.com/mholt/caddy-ratelimit`) in the site's subroute, after its IP allow / deny lists (a denied client is not
counted) and before authentication and the handler. One zone per rule, keyed by `{http.vars.client_ip}` (behind
Cloudflare's trusted proxies, the visitor). Zones are global in Caddy by name (`falak_<site>_<n>`), so a site's
domain groups and compose services share the count. **Every other route renders byte for byte as before**, and a
server without rate-limited sites gets the same payload as before (no `caddy_build`).

**The binary.** The servers' Caddy is the apt package (or FrankenPHP); neither has the module. The control plane image
builds **Falak's Caddy** in its `edge-caddy` stage (`xcaddy build v2.11.4 --with github.com/mholt/caddy-ratelimit@v0.1.0
--with github.com/caddy-dns/cloudflare@v0.2.4`, amd64 + arm64, with `.sha256` sidecars; CI's image smoke test checks
`list-modules`) and serves it publicly at `/install/edge/caddy-linux-<arch>` like the agent installer. When a server
has rate-limited sites, `edge.caddy.apply` carries `caddy_build: {url, sha256}` for its architecture. Before loading
such a config the agent checks `list-modules` of the binary `falak-edge.service` runs: with the module, nothing
happens. Without it, it downloads the build over HTTPS (checksum verified) to
`/usr/local/lib/falak/caddy/caddy-<sha>`, refuses it unless it has `http.handlers.rate_limit` **and every module the
current Caddy has** (a newer apt Caddy with modules the build lacks keeps running, with an error), checks that it
accepts the config the edge loads at start (`validate` of the persisted bootstrap config), then points
`falak-edge.service` at it and restarts the edge once (a second of downtime for that server's sites). If the edge does
not come back (the restart fails or the admin API does not answer within 30 s), the unit is put back on the Caddy it
ran and restarted again, and the apply fails with the reason: rate limits are not applied, everything else keeps
serving. Later applies find the module and do nothing. `/usr/bin/caddy` itself is never touched (apt keeps updating
it; the build is a separate file). `deploy/edge/Dockerfile` (the panel's own edge) does not need it: it serves no sites.

**Trust.** The build is not signed: its integrity rests on the SHA-256 the control plane computes from the file it
ships and sends inside the `edge.caddy.apply` command (delivered over the agent's mTLS channel); the agent downloads
only over HTTPS and refuses a file whose digest differs. The download URL is public (like the agent installer).

**FrankenPHP edges are refused**: the panel refuses rules for sites on a server whose PHP runtime is FrankenPHP (its
binary is FrankenPHP's own build) and the agent refuses them too; use a Cloudflare rate limit there. Servers whose agent
lacks `edge.rate_limit` are refused with "update the agent"; PayloadCompatibility strips `sites.*.rate_limits` and
`caddy_build` for them all the same.

Not covered: an nftables meter (per source, per port) is no substitute — it would limit every path of every site on
the port.

## What needs a reload

- **Firewall**: once per server after the agent upgrade (counters in the ruleset): an atomic `nft -f` of `table inet
  falak`, triggered by the control plane on `AgentVersionChanged`. Same rules, so no traffic change.
- **Protection table**: created on the first `net.protection.apply` (the first block or enabling auto-ban); never
  reloaded afterwards, only element updates.
- **Edge**: nothing for sites without rate limits. The first rate limit on a server installs Falak's Caddy build and
  restarts `falak-edge.service` once; every rule change after that is an ordinary config load (`/load`).
