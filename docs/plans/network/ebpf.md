# Phase 5 · F. eBPF (TCP health, DNS, run-queue latency)

Part of [OBSERVABILITY.md phase 5](../OBSERVABILITY.md#phase-5-ebpf-and-packet-capture) (phase 4's shared rules
apply). What `/proc` cannot show: TCP retransmits, resets, connect latency and round-trip time per workload and per
peer, DNS queries, their latency, failures and the names asked for, and how long a workload's threads wait for a CPU.
An in-kernel probe aggregates into bounded maps; the agent reads them once per telemetry interval and ships
`falak.net.tcp.*`, `falak.net.dns.*` and `falak.workload.runqueue.*` metrics. Opt-in per server (default **off**),
read-only, and never a reason for the agent to fail.

```
kernel (CO-RE programs, bounded LRU maps)                         agent ebpfobs.Collector, every interval
  tp_btf/inet_sock_set_state  connect start/end, RTT on close ──┐   reads the maps (cumulative counters → deltas),
  tp_btf/tcp_retransmit_skb   retransmits per socket          ──┤   attributes keys to workloads (cgroup id, pid, uid),
  tp_btf/tcp_send_reset       resets sent                     ──┼─► folds peers to the top 20 per workload (+ other),
  tp_btf/tcp_receive_reset    resets received                 ──┤   names them like falak.net.peer, keeps top 10 DNS
  cgroup_skb egress/ingress   UDP port 53 on the root cgroup  ──┤   names per workload, measures its own cost
  tp_btf/sched_wakeup(_new)/sched_switch   run-queue delay   ──┘     ├─► OTLP metrics → VictoriaMetrics (30 d)
                                                                     └─► facts.ebpf (status) → panel
```

## Agent feature and protocol

Feature **`net.ebpf`** (`agent/internal/version.Features`) gates the whole command **`net.ebpf.configure`**
(`{"enabled": bool, "full_dns_names"?: bool, "reenable"?: bool}`): the control plane only queues it for agents that
list the feature, so `PayloadCompatibility` has nothing to strip. `full_dns_names` ships whole query names (default off:
names fold to their registrable domain, below); `reenable` clears an over-budget stop (see "Overhead") and is sent only
after the panel's explicit "Re-enable". The agent persists the setting and an over-budget stop in
`/etc/falak/ebpf.json` (0600), so a restarted agent keeps both.
It answers with its status (below) and also reports it in **facts** (`facts.ebpf`, every facts refresh, a few minutes):

| Field | Meaning |
|---|---|
| `supported` | the kernel can run the probe (capability detection, below) |
| `reason` | why not (`kernel 5.4 < 5.8`, `no BTF (/sys/kernel/btf/vmlinux)`, `bpf() not permitted (unprivileged container?)`, `cgroup v2 not mounted`, `not linux/amd64 or linux/arm64`) |
| `enabled` | the setting as the agent applied it |
| `state` | `off` · `running` · `degraded` (the run-queue programs were shed to stay in budget) · `over_budget` (detached, see "Overhead") · `failed` (a load or attach error) · `unsupported` |
| `detail` | the load error or the budget figure that tripped (cut to 2,000 characters; `reason` to 300) |
| `programs` | the program groups attached: `tcp`, `dns`, `runq` |
| `overhead_percent` | the probe's own CPU over the last 5 minutes, percent of the machine's capacity |

### Capability detection

Checked for the first facts report and again on every `enabled: true`: Linux on amd64 or arm64, kernel ≥ 5.8
(`/proc/sys/kernel/osrelease`: BTF raw tracepoints, `BPF_ENABLE_STATS`, `bpf_skb_cgroup_id` in `cgroup_skb`), `/sys/kernel/btf/vmlinux` readable (CO-RE), cgroup v2 at
`/sys/fs/cgroup` (`cgroup.controllers`), and a `bpf()` syscall that works (a tiny array map is created and closed: it
fails with `EPERM` in an unprivileged container or under a seccomp profile that denies it). `RLIMIT_MEMLOCK` is lifted
for kernels before 5.11 (`rlimit.RemoveMemlock`). A missing capability is reported, never retried in a loop.

### Never failing the agent

Loading and every read run under a recover; any error (verifier rejection, a missing tracepoint on an
exotic kernel, attach failure) leaves the state `failed` with the error in `detail`, closes what was attached and
loaded, and the agent goes on. Each program group attaches alone: a kernel without one tracepoint loses that group, not
the others (`degraded`, `detail` says which). `enabled: false`, agent shutdown and an over-budget trip close every link
and map (links are never pinned: a crashed agent's programs are detached by the kernel when its file descriptors go).

## Kernel side

One C file (`agent/internal/ebpfobs/bpf/probe.bpf.c`), compiled with **bpf2go** for amd64 and arm64; the generated Go
files and `.o` objects are committed, so builds and CI need no clang. CO-RE against the kernel's BTF: the struct
fields the programs read come from a hand-written `bpf/vmlinux.h` with `preserve_access_index`, relocated at load time
by cilium/ebpf against `/sys/kernel/btf/vmlinux`. Regenerate after editing the C file:

```
cd agent && PATH="/opt/homebrew/opt/llvm/bin:$PATH" go generate ./internal/ebpfobs   # Homebrew clang 23.1.3
```

The build is reproducible: the same clang gives the same bytes (both objects are identical, the programs use no
architecture-specific code), so a review regenerates them and checks `git diff --exit-code agent/internal/ebpfobs`. CI
does not regenerate them: it would need this exact clang build, and a different clang emits different (equally valid)
bytes, so a diff check there would fail on the toolchain, not the source; the pinned version above is the contract.

Tracepoints only, no kprobes: BTF-typed raw tracepoints (`tp_btf`, kernel 5.5+, no trampoline: also on arm64 kernels
before 6.0) for TCP and the scheduler, and `cgroup_skb` programs attached to the cgroup v2 root (through a BPF link,
which runs alongside systemd's own per-unit programs) for DNS. No ring buffer and no per-event user-space work:
everything is counted in maps.

### Maps (hard caps)

| Map | Type | Entries | Key → value |
|---|---|---|---|
| `conns` | LRU hash | 16,384 | socket → owner (cgroup id, tgid, uid), direction, connect start |
| `peers` | LRU hash | 8,192 | owner + direction + remote address + port (0 inbound) → retransmits, resets sent / received, connects, failed connects, RTT sum / count |
| `hists` | LRU hash | 4,096 | owner + kind (connect, RTT, DNS, run queue) → 27 log2 µs buckets, sum, count |
| `dns_names` | LRU hash | 4,096 | cgroup id + uid + query name (wire format, 64 bytes) → queries, responses, NXDOMAIN, SERVFAIL, REFUSED, other errors, latency sum |
| `dns_pending` | LRU hash | 4,096 | socket cookie + DNS id → query time and its `dns_names` key |
| `runq_start` | hash | 16,384 | pid → wakeup time (a plain hash: an LRU update takes a lock on every wakeup). A stamp goes when its task is switched in or exits (`sched_process_exit`); the agent sweeps stamps older than 10 s each pass, so the map cannot fill with gone tasks and a reused pid never inherits a stale one |

About 4–6 MB of kernel memory in all at the caps. An evicted entry loses what it counted since the agent's last read
(under-counting, never double counting); the user-space reader treats a counter that went down as a new key.

### TCP

- **Owner of a socket.** At `connect()` (`inet_sock_set_state` CLOSE → SYN_SENT, in the caller's context): the current
  cgroup id, tgid and uid, kept in `conns`. A passive socket becoming ESTABLISHED (SYN_RECV → ESTABLISHED, in softirq):
  the socket's own cgroup (`sk_cgrp_data`, kernels 5.15+; older kernels attribute such events to the server) and
  `sk_uid`. Events on a socket not in `conns` (opened before the probe started, or evicted) use the socket's cgroup and
  uid, and the direction is guessed: the lower port is the server's.
- **Connect latency**: SYN_SENT → ESTABLISHED; SYN_SENT → CLOSE is a failed connect.
- **RTT**: `tcp_sock.srtt_us >> 3` when a socket that carried data closes (one sample per connection).
- **Retransmits** (`tcp_retransmit_skb`) and **resets** sent / received (`tcp_send_reset`, `tcp_receive_reset`; a reset
  for no socket, or for a time-wait / request socket, counts for the server).

### DNS

`cgroup_skb` egress and ingress, on every packet: two byte loads decide (not IPv4 / IPv6 UDP → return); then the UDP
ports. Egress to port 53 with QR = 0 is a query, ingress from port 53 with QR = 1 its answer, matched on the socket
cookie and the DNS id. The query name is copied in wire format (length-prefixed labels, at most 64 bytes, the bytes
after its root label zeroed in the kernel so a name is one key whatever the query type). RCODE 3 is NXDOMAIN, 2 SERVFAIL,
5 REFUSED, other non-zero `other`; a query unanswered after 5 s is a `timeout` (counted by the agent when it reads
`dns_pending`, then deleted). A timeout may also be an answer the probe could not match (a reply to another socket):
it is charted, never part of the failure alert. UDP only: DNS over TCP, DoT and DoH are not seen. Through systemd-resolved a lookup is
seen twice: the app's query to `127.0.0.53` (the app's workload) and resolved's upstream query (the server's).

### Run-queue latency

`sched_wakeup` / `sched_wakeup_new` stamp the woken task; `sched_switch` measures the delay of the task switched in
(and stamps a preempted task that is still runnable); `sched_process_exit` drops the stamp of a task that exits
before it runs again. Kernel threads are folded into one key. This is the expensive
part (two events per context switch): it is the first thing shed when the probe goes over budget.

## Attribution

Every key carries a cgroup id (the inode of a cgroup v2 directory), a tgid when the event ran in the process's
context, and a uid. The collector reuses the workload collector's last pass (`WorkloadCollector.NetSnapshot`, phase 1
and 4) — it makes no Engine API call and reads no `/proc` of its own beyond one `stat` per new cgroup directory:

1. the cgroup id of a site or database **container** (its cgroup directory, from the snapshot) → that container
   (`falak.workload.kind` `container`, `container.id`, `falak.compose.service`);
2. the cgroup id of a native site's **slice** (`falak-site_<key>.slice`) → the site (`processes`);
3. the **tgid** of a process the snapshot attributes to a native site (slice, isolated user, shared user's pool or
   working directory) → the site (`processes`);
4. the **uid** of an isolated site's own user → the site (`processes`; DNS has no tgid: the packet hooks see the socket,
   not the process);
5. anything else → the server (`falak-host`, kind `host`).

Resources as phase 4: `service.name` = the site slug (enriched to `falak.site.id`), `falak-db` with
`falak.db.instance.id`, or `falak-host`. Scope `falak-agent/ebpf`. Peers are named like `falak.net.peer` (phase 4's
`netobs` naming: control-plane names `server:<id>` / `db:<id>`, containers on this server `site:<slug>` / `db:<id>`,
else the buckets `external:<port>`, `private:<port>`, `local:<port>` outbound and `external`, `private`, `local`
inbound). No raw IP is a label.

Known gaps: a shared-user native site's DNS counts for the server (no pid at the socket hooks); inbound TCP of native
sites is the edge's (Caddy proxies to PHP-FPM over a unix socket), so it counts for the server — their outbound
connections (database, APIs) are theirs.

## Metrics (agent → OTLP → VictoriaMetrics)

Every interval (15 s). Counters are cumulative from the agent's start (deltas of the kernel maps added up); a new
series is shipped at 0 in the pass that creates it and with its count from the next one (a counter's first sample is
only a baseline to `rate()` / `increase()`, so a series appearing with its first events counted would lose them). Histograms
are OTLP explicit-bucket histograms (bounds 4 µs … 16.8 s, powers of two), which VictoriaMetrics stores as
`_bucket{le}`, `_sum`, `_count`.

| Metric (OTLP) | Type | Unit | Attributes | Prometheus name |
|---|---|---|---|---|
| `falak.net.tcp.retransmits` | counter | {segment} | `falak.net.peer`, `falak.net.direction` | `falak_net_tcp_retransmits_total` |
| `falak.net.tcp.resets` | counter | {reset} | `falak.net.peer`, `falak.net.direction`, `falak.net.reset.direction` (`sent` \| `received`) | `falak_net_tcp_resets_total` |
| `falak.net.tcp.connects` | counter | {connection} | `falak.net.peer`, `falak.net.outcome` (`ok` \| `failed`) | `falak_net_tcp_connects_total` |
| `falak.net.tcp.peer.rtt` | gauge | s | `falak.net.peer`, `falak.net.direction` (mean of the interval's closed connections) | `falak_net_tcp_peer_rtt_seconds` |
| `falak.net.tcp.connect.duration` | histogram | s | | `falak_net_tcp_connect_duration_seconds_{bucket,sum,count}` |
| `falak.net.tcp.rtt` | histogram | s | | `falak_net_tcp_rtt_seconds_{bucket,sum,count}` |
| `falak.net.dns.queries` | counter | {query} | | `falak_net_dns_queries_total` |
| `falak.net.dns.failures` | counter | {query} | `falak.net.dns.error` (`nxdomain` \| `servfail` \| `refused` \| `other` \| `timeout`) | `falak_net_dns_failures_total` |
| `falak.net.dns.duration` | histogram | s | | `falak_net_dns_duration_seconds_{bucket,sum,count}` |
| `falak.net.dns.name.queries` | counter | {query} | `falak.net.dns.name` (top 10 per workload, ranked over about an hour, the rest `other`) | `falak_net_dns_name_queries_total` |
| `falak.workload.runqueue.delay` | histogram | s | | `falak_workload_runqueue_delay_seconds_{bucket,sum,count}` |
| `falak.ebpf.cpu.time` | counter | s | `falak.ebpf.component` (`tcp` \| `dns` \| `runq` \| `agent`) | `falak_ebpf_cpu_time_seconds_total` |
| `falak.ebpf.active` | gauge | (none) | `falak.ebpf.component` | `falak_ebpf_active` |

Every data point also carries the workload's `falak.workload.kind` / `falak.workload.name` (+ `container.id`,
`falak.compose.service`). `falak.ebpf.*` are on the host resource. `falak.ebpf.active` has no unit on purpose (`1`
would become `_ratio`). Cardinality per workload: ≤ 2 × 21 peers × (retransmits + 2 resets + 2 connects + RTT), 4
histograms × 24 buckets, ≤ 11 names; a peer or name label idle for 10 minutes is dropped.

### DNS names

Names are folded to their registrable domain by default (`api.eu.stripe.com` → `stripe.com`, `a.example.co.uk` →
`example.co.uk`: the last two labels, three under a two-letter country code with a common second level such as `co`,
`com`, `org`; a short rule, not the public suffix list, so `x.github.io` stays `github.io`; a name cut at 64 bytes is
`(long name)`). The per-server option **Full DNS names** (Admin, default off) ships them whole. Each workload's top 10
are ranked by queries decayed with a 30-minute half-life (about an hour), and sticky: a name joins only by beating the
quietest of the ten by half again, so labels change rarely and the series count stays bounded (≤ 11 per workload).
They are kept as long as every metric: 30 days in VictoriaMetrics. On the server's tab, the server workload's own names
(processes outside every site: the host's resolver traffic, admin sessions) are shown to `network.manage` only.

## Overhead

Budget: **< 1 % of the machine's CPU capacity** (all CPUs), measured, not assumed:

- kernel time: `BPF_ENABLE_STATS` (`BPF_STATS_RUN_TIME`, kernel 5.8) is held while the probe runs, and each program's
  `run_time_ns` delta per interval is the probe's in-kernel cost (`falak.ebpf.cpu.time`, per program group). Holding
  it is host-wide: it adds two clock reads to every BPF program run on the host (systemd's, Docker's, any other
  tool's), tens of nanoseconds each, while the probe is on; it is released with the probe (off, over budget, agent
  stopped);
- user time: the agent's own read of the maps (`agent`), timed per pass.

Over a 5-minute window (20 passes at 15 s), or at once when a single pass costs more than 5 × the budget: above
budget with the run-queue programs on, they are detached first (`degraded`, `detail` "run-queue programs shed: 1.4 % of
CPU"); still above budget, everything is detached (`over_budget`). The stop is persisted in `ebpf.json`: neither an
agent restart nor the control plane re-sending the setting (an upgrade, the agent back online, the periodic converge)
starts the probe again; only the panel's **Re-enable** (`reenable: true`) or turning the setting off does.

### Measurements

Linux 6.10.14 arm64 (Docker Desktop's VM, 8 vCPU, BTF on), privileged container, `go test -run
'TestLoadAttach|TestOverheadUnderLoad'` with `FALAK_EBPF_BENCH=1` (3 s of channel ping-pong, loopback TCP connections
with data and DNS queries as fast as one goroutine goes; the counts include everything else the VM ran):

| Group | Events/s under that load | Cost per event | CPU |
|---|---|---|---|
| `dns` (`cgroup_skb`, every packet) | 378,000 | 52 ns | 20 ms/s |
| `tcp` (`inet_sock_set_state`, retransmits, resets) | 157,000 | 169 ns | 27 ms/s |
| `runq` (wakeups and switches) | 112,000 | 249 ns | 28 ms/s |

That synthetic load is ~0.9 % of the 8 CPUs, i.e. inside the budget even there. A small web server (2 vCPU, ~2,000
packets/s, ~100 new connections/s, ~10,000 context switches/s) costs ≈ 0.1 ms/s (DNS hooks) + 0.1 ms/s (TCP) +
5 ms/s (run queue) ≈ 0.27 % of its capacity; the run queue is most of it, which is why it is shed first. The numbers
include `BPF_ENABLE_STATS` itself. Not yet measured on the live x86_64 servers (Ubuntu 24.04, kernels 6.8 and 6.17).

## Control plane

### Setting (Network module)

`network_ebpf_settings` (server id, organization, `enabled` and `full_dns_names` default false, `reenable`, `status`,
`command_id`, `pending_hash` / `applied_hash` of `{enabled, full_dns_names}`, `applied_at`, `error`). **Server →
Network** shows the switches (eBPF, and Full DNS names while it is on), **Admin only**: the new permission
`network.ebpf` (`network.manage` is not enough). Audited as `network.ebpf_updated` (`{enabled, full_dns_names,
reenable}`). A change is sent as `net.ebpf.configure` when the agent has `net.ebpf` (else "needs a newer agent"), and
sent again until the agent applied it: `AgentVersionChanged` (forced), `AgentCameOnline` (an agent that was offline when
it was turned off) and `network:converge-ebpf` every 10 minutes send it when it is not applied, failed, or lost in
flight for 30 minutes; never while a command is in flight. The card shows the agent's `facts.ebpf`: "Not supported:
<reason>", "Running" (programs, overhead), "Running in part" / "Turned off: over its CPU budget" with the detail and,
for admins, **Re-enable**, "The probe did not start: <error>".

`PUT /network/servers/{server}/ebpf {enabled, full_dns_names?, reenable?}` (`network.ebpf`).

### Queries and endpoints

| Endpoint | Permission | Scope |
|---|---|---|
| `GET /network/servers/{server}/ebpf?range=` | `network.view` | the server (every workload) |
| `GET /network/sites/{site}/ebpf?range=&server=&service=` | `telemetry.view` | the site's containers / processes (`falak_site_id`, `falak_compose_service`) |
| `GET /network/databases/{instance}/ebpf?range=` | `databases.view` | the instance's container (`falak_db_instance_id`) |

Each returns `status` (per server: `agent_outdated` · `off` · the agent's `facts.ebpf`), the charts (PromQL in
`EbpfQueries`) and the top names (an instant `topk(10, increase(falak_net_dns_name_queries_total[range]))`):

| Chart | PromQL (selector = scope + servers) |
|---|---|
| DNS queries/s | `sum(rate(falak_net_dns_queries_total[w]))` |
| DNS failures/s by error | `sum by (falak_net_dns_error) (rate(falak_net_dns_failures_total[w]))` |
| DNS latency p50 / p95 | `histogram_quantile(q, sum by (le) (rate(falak_net_dns_duration_seconds_bucket[w])))` |
| Retransmits/s by peer | `topk(5, sum by (falak_net_peer) (rate(falak_net_tcp_retransmits_total[w])))` |
| Resets/s sent / received | `sum by (falak_net_reset_direction) (rate(falak_net_tcp_resets_total[w]))` |
| RTT p50 / p95 | `histogram_quantile(q, sum by (le) (rate(falak_net_tcp_rtt_seconds_bucket[w])))` |
| Connect latency p95, failed connects/s | `histogram_quantile(0.95, …connect_duration…)`, `sum(rate(falak_net_tcp_connects_total{falak_net_outcome="failed"}[w]))` |
| Run-queue delay p50 / p95 / p99 | `histogram_quantile(q, sum by (le) (rate(falak_workload_runqueue_delay_seconds_bucket[w])))` |
| Probe overhead (server only) | `sum by (falak_ebpf_component) (rate(falak_ebpf_cpu_time_seconds_total[w]))` |

`w` = max(5 m, step), as the other Network charts.

### UI

A **TCP & DNS** section below the connections on the site, database and server Network tabs (`EbpfView`): the status
callout (off / unsupported / outdated agent / over budget, with what to do), DNS (queries/s, failures by error, latency
p50 / p95, top names table with failures), TCP health (retransmits by peer, resets, RTT and connect latency p95, failed
connects) and run-queue delay; the server's adds the switch and the probe's overhead chart.

### Alerts (Alerting, area Network: the default rule pack picks them up)

`network:ebpf-alerts` runs every minute, two instant queries over all servers with the setting on, per workload
(`falak_server_id`, `service_name`, `falak_workload_name`):

| Type | Condition (5-minute window) | Severity |
|---|---|---|
| `network.dns_failures` | answers with an error other than NXDOMAIN (SERVFAIL, REFUSED, …; unanswered queries left out) ≥ `dns_failures_min` (60) **and** ≥ `dns_failure_ratio` (20 %) of the queries, held `alert_seconds` (300) | Warning |
| `network.tcp_retransmits` | retransmits ≥ `retransmits_min` (3,000, ~10/s) for a site or database, `retransmits_min_host` (6,000, ~20/s: the edge carries every site's traffic) for the server's own processes, held 300 s | Warning |

Keys `network.dns_failures:<server>:<workload>` / `network.tcp_retransmits:<server>:<workload>`; conditions of workloads
no longer reporting are cleared (`clearExcept`). NXDOMAIN is left out: search domains and probes make it common and
harmless. Retransmits have no ratio: the probe counts no segments sent, and a long-lived connection makes no new
connects to divide by; the thresholds are high on purpose. Config `network.ebpf` (`dns_failures_min`,
`dns_failure_ratio`, `retransmits_min`, `retransmits_min_host`, `alert_seconds`).

## Security

- Read-only: no program changes, drops or redirects a packet (`cgroup_skb` programs always return 1), nothing is
  written to a socket.
- DNS names are metric labels only as the top 10 per workload (names, not addresses), folded to their domain unless an
  admin turned full names on, kept 30 days; the server workload's names need `network.manage`. No payload beyond the
  query name and RCODE is read; answers (addresses) are never parsed.
- The probe runs only where an organization admin turned it on (`network.ebpf`, default off); the action is audited.
- Endpoints authorize the organization and the permission (`network.view` / `network.manage`, `telemetry.view`,
  `databases.view`); other organizations' servers, sites and instances are 404.

## Not in this stream

Per-request latency (HTTP is stream B's), DNS over TCP / TLS / HTTPS, UDP retransmission, packet loss per interface,
on-CPU profiling, IPv6 extension headers in front of the UDP header (such packets are skipped), process-level DNS
attribution for shared-user native sites. Packet capture is stream G ([capture.md](capture.md)).

## Tests

- Go: user-space aggregation with fake map readers (deltas, counter resets, top-N folding to `other`, peer naming,
  DNS name decoding and top names, timeouts from `dns_pending`, histograms to OTLP bounds), attribution order (container
  cgroup, slice, tgid, uid, host), overhead accounting and shedding (`degraded` → `over_budget`), capability detection
  on fake files, the configure command and its persistence, map key / value sizes against the compiled object's spec;
  a Linux-only test that loads and attaches every program (skipped unless root with BTF), generates a TCP connection
  and a DNS query and reads them back.
- PHP: the setting (validation, audit, authorization, gating: no command for an agent without `net.ebpf`), the re-send
  on `AgentVersionChanged`, the endpoints (scoping, status, authorization, other organizations), the alert job
  (thresholds, ratio, clearing).
- Vitest: status messages and chart helpers.
