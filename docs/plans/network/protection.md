# Network phase 4, stream C: Protection (v0.17.x)

Acting on what streams A (connections) and B (HTTP analytics) find: block an IP or CIDR from the panel, SSH brute-force
detection with an optional auto-ban, and hit counters on every Falak firewall rule. Index and shared rules:
[OBSERVABILITY.md, phase 4](../OBSERVABILITY.md#phase-4-network-v017x).

| Piece | Where | Changes traffic? |
|---|---|---|
| Block an IP / CIDR (per server or all servers, with expiry, reason, audit) | Network → Blocked IPs, the `network.ip-actions` slot on A's and B's rows | Only when a user blocks |
| SSH brute force → `security` Issues | agent (auth log) → `net.protection.observed` → Insights | No |
| Auto-ban (N failures in M minutes → block for T) | per server, Network → Blocked IPs | Only when a user turns it on (off by default) |
| Firewall rule and block-set counters | agent (`nft -j`) → `falak.net.firewall.*` + the Firewall tab | No |
| Rate-limit a path at the edge | **not in this stream** (see the last section) | — |

Agent feature: `net.protection`. Permission: `network.protect` (admins only); the list needs `network.view`.

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
  wipe set elements and counters. The protection table is created once (only when missing; a full `nft -f` of an
  existing table would append the chain's rules twice) and then only changed element by element.
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
- `release`: auto-bans a user lifted: the agent forgets them from its local state too (it would re-add them otherwise).
- `never_block`: the guard list (below), so the agent can refuse auto-bans the control plane would refuse.
- Result: `{changed, added, removed, failed: [{cidr, error}], set_counters}`.

The agent diffs against the live sets (`nft -j list set`): elements no longer wanted are deleted, missing ones added
with their remaining timeout, and an element whose expiry moved by more than a minute is re-added. Unchanged elements
keep their counters. Old agents never get the command: the control plane only dispatches it to agents with
`net.protection` and shows "update the agent" otherwise.

### Guards (control plane, and again on the agent)

Refused outright:

- `0.0.0.0/0`, `::/0`, and prefixes shorter than /8 (IPv4) or /16 (IPv6);
- loopback, unspecified, link-local, multicast;
- a prefix containing **the requester's own IP** (from the request, behind the trusted proxies);
- a prefix containing **a control-plane address**: `network.protection.control_plane_addresses`
  (`FALAK_CONTROL_PLANE_IPS`) plus the A / AAAA records of the panel and agent API hosts;
- a prefix containing **any server of the organization** (public IPv4 / IPv6, private IPv4) or a **WireGuard member
  address** / private network CIDR.

Need `confirm: true` (the dialog shows a "block anyway" checkbox with the reason):

- private ranges (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7): Docker networks, load balancers and peers live
  there;
- broad prefixes: shorter than /16 (IPv4) or /48 (IPv6).

Agent side, defense in depth: never adds an element that contains a loopback / link-local / unspecified address,
the host's own interface addresses, the control plane's resolved addresses or a `never_block` entry, and never adds
a /0. Auto-bans additionally skip private ranges and addresses that logged in successfully over SSH in the last 24 h.

Limits: 30 block changes a minute per user (`network.protection.changes_per_minute`), 5,000 active blocks per
organization (`network.protection.max_blocks`). Every block, unblock, auto-ban and settings change is audited
(`network.ip_blocked`, `network.ip_unblocked`, `network.ip_auto_banned`, `network.protection_updated`).

### Data (Network module)

- `network_ip_blocks`: organization, `server_id` (null = all servers), `cidr` (canonical), `family`, `reason`,
  `source` (`manual` | `auto`), `expires_at` (null = permanent), `created_by`, `meta` (auto-ban failures, the site it
  was blocked from). Expired rows are pruned after 7 days (daily `network:prune-blocks`).
- `network_ip_block_hits`: block × server: packets, bytes, `last_hit_at` (cumulative counters as the agent reports
  them; a re-added element restarts at 0, a smaller value replaces the stored one).
- `network_protection_states`: per server: auto-ban settings (`auto_ban`, `failures`, `window_s`, `ban_s`) and the
  apply state.
- `network_firewall_states.counters` / `counters_at`: the latest rule counters (`{rule id: {packets, bytes}}`).

### UI

- **Network → Blocked IPs** (`/network/blocked`): active blocks (address, reach, reason, source, expires, hits, last
  hit, by whom) with Unblock; "Block an address"; per server: agent support, apply status and the auto-ban settings.
- **`network.ip-actions` slot** (`registerIpActions` / `ipActionsFor` in `@/lib/registry`, shared with stream B; props
  `{ip, serverId?, siteId?, source}`): streams A and B render the registered actions on rows with a remote IP; this
  stream registers "Block" (`network.block`, permission `network.protect`). With `siteId`, "the site's servers" blocks
  on each of the site's servers (one block per server).
- **Firewall tab**: packets / bytes next to each rule, plus the block-set drops.

## SSH brute force

The agent reads sshd's messages every minute: `journalctl -t sshd -t sshd-session -o json --after-cursor` (Ubuntu ≥
24.04 and Debian 12 log sshd to the journal only; OpenSSH ≥ 9.8 logs authentication from `sshd-session`), falling back
to `/var/log/auth.log` (offset + inode, rotation-safe) when journalctl is missing. At most 5,000 lines a pass; the first
pass only sets the cursor.

Counted per source address (IPv4, IPv6, IPv4-mapped IPv6 unmapped, zones dropped):

| Line | Counted |
|---|---|
| `Failed password for [invalid user] X from IP port N ssh2` (also `keyboard-interactive/pam`) | yes, unless "invalid user" (the `Invalid user` line counted it) |
| `Invalid user X from IP port N` | yes |
| `error: maximum authentication attempts exceeded for … from IP` | yes |
| `Connection closed by / Disconnected from authenticating user X IP port N [preauth]` | yes |
| `message repeated N times: [ … ]` (rsyslog) | N times the inner line |
| `Accepted publickey|password for X from IP` | no: the address is trusted for 24 h (never auto-banned) |
| PAM `authentication failure`, `Received disconnect`, `Did not receive identification`, `Unable to negotiate` | no (duplicates or scanners without an attempt) |

`net.protection.observed` (agent → control plane, once a minute when there is something to say):
`{at, window_s, auth: {source, sources: [{ip, failures, invalid_users, users, last_at}]}, bans: [{cidr, until,
failures}], rules: [{id, packets, bytes}], blocks: [{cidr, packets, bytes}], blocked: {packets, bytes}}`; the top 100
sources by failures, 5 sample user names each. Old control planes answer 404: the agent logs it once.

Control plane:

- **Issue** (Insights kind `security`, shared with streams A and B; contract `HostSecurityIssues`): one per server,
  fingerprint `sha256(host|<server>|ssh.bruteforce)`, an occurrence per report where the server saw ≥ `ssh_issue_min_failures` (20)
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

## What needs a reload

- **Firewall**: once per server after the agent upgrade (counters in the ruleset): an atomic `nft -f` of `table inet
  falak`, triggered by the control plane on `AgentVersionChanged`. Same rules, so no traffic change.
- **Protection table**: created on the first `net.protection.apply` (the first block or enabling auto-ban); never
  reloaded afterwards, only element updates.
- **Edge**: nothing.

## Not in this stream: path rate limits at the edge

Sites' edge is Caddy from the official apt repository (`provision.caddy`) or FrankenPHP; neither build has a rate-limit
module (`deploy/edge/Dockerfile` is the panel's own edge, not the servers'). Shipping one means a Falak-built Caddy:

1. build `caddy` with `github.com/mholt/caddy-ratelimit` (xcaddy) for amd64 / arm64 in the release workflow, signed and
   checksummed like the agent;
2. `provision.apply` installs it with `dpkg-divert` over `/usr/bin/caddy` (an apt upgrade must not replace it), feature
   `edge.rate_limit`, and the agent checks `caddy list-modules` for `http.handlers.rate_limit`;
3. `edge.caddy.apply sites[].rate_limits: [{path, events, window_s, key: "{remote_host}"}]` (stripped for older
   agents) renders a `rate_limit` handler in front of the site's routes only for sites that have rules: the rendered
   config of every other site stays byte-identical (`config_sha256` unchanged, no reload);
4. FrankenPHP edges: a FrankenPHP build with the same module, or refuse rules on them.

An nftables meter (per source, per port) is not a substitute: it limits every path of every site on the port. This is
its own follow-up (`network/rate-limits.md`) once the custom build exists.
