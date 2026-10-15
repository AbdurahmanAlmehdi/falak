// SPDX-License-Identifier: GPL-2.0-only OR BSD-2-Clause
//
// Falak's eBPF probe (docs/plans/network/ebpf.md): TCP health (connect latency, RTT, retransmits, resets) per
// socket owner and peer, DNS queries per cgroup and name, and run-queue delay per process, all counted in bounded LRU
// maps that the agent reads once per interval. Read-only: nothing here changes, drops or redirects traffic.
//
// Regenerate the Go bindings and objects after editing: `go generate` in agent/internal/ebpfobs.

#include "vmlinux.h"
#include "bpf_helpers.h"
#include "bpf_tracing.h"
#include "bpf_core_read.h"
#include "bpf_endian.h"

#define AF_INET 2
#define AF_INET6 10
#define IPPROTO_TCP 6
#define IPPROTO_UDP 17
#define PF_KTHREAD 0x00200000
#define TASK_RUNNING 0

#define TCP_ESTABLISHED 1
#define TCP_SYN_SENT 2
#define TCP_SYN_RECV 3
#define TCP_TIME_WAIT 6
#define TCP_CLOSE 7
#define TCP_NEW_SYN_RECV 12

#define DIR_OUT 1
#define DIR_IN 2

// Histogram buckets: slot i counts values in [2^i, 2^(i+1)) microseconds (slot 0: below 2 µs), the last one
// everything from 2^26 µs (~67 s) up.
#define SLOTS 27
#define QNAME_LEN 64

enum hist_kind {
	H_CONNECT = 1,
	H_RTT = 2,
	H_DNS = 3,
	H_RUNQ = 4,
};

// Who a socket or a task belongs to: its cgroup v2 id, the process (0 when the event ran outside its context) and uid.
struct owner {
	__u64 cgid;
	__u32 tgid;
	__u32 uid;
};

struct conn {
	struct owner o;
	__u64 start_ns;
	__u8 dir;
	__u8 _pad[7];
};

struct peer_key {
	struct owner o;
	__u8 addr[16]; // IPv4 in the first 4 bytes
	__u16 port;    // remote port, host order; 0 for inbound connections
	__u8 family;
	__u8 dir;
	__u32 _pad;
};

struct peer_val {
	__u64 retrans;
	__u64 rst_sent;
	__u64 rst_recv;
	__u64 connects;
	__u64 connect_fail;
	__u64 rtt_sum_us;
	__u64 rtt_count;
};

struct hist_key {
	struct owner o;
	__u32 kind;
	__u32 _pad;
};

struct hist_val {
	__u64 slots[SLOTS];
	__u64 sum_us;
	__u64 count;
};

struct dns_key {
	__u64 cgid;
	__u32 uid;
	__u32 _pad;
	__u8 qname[QNAME_LEN]; // wire format, zeroed after the root label
};

struct dns_val {
	__u64 queries;
	__u64 responses;
	__u64 nxdomain;
	__u64 servfail;
	__u64 refused;
	__u64 other_err;
	__u64 lat_sum_us;
};

struct dns_pending_key {
	__u64 cookie;
	__u16 id;
	__u16 _pad1;
	__u32 _pad2;
};

struct dns_pending {
	__u64 ts;
	struct dns_key k;
};

struct {
	__uint(type, BPF_MAP_TYPE_LRU_HASH);
	__uint(max_entries, 16384);
	__type(key, __u64);
	__type(value, struct conn);
} conns SEC(".maps");

struct {
	__uint(type, BPF_MAP_TYPE_LRU_HASH);
	__uint(max_entries, 8192);
	__type(key, struct peer_key);
	__type(value, struct peer_val);
} peers SEC(".maps");

struct {
	__uint(type, BPF_MAP_TYPE_LRU_HASH);
	__uint(max_entries, 4096);
	__type(key, struct hist_key);
	__type(value, struct hist_val);
} hists SEC(".maps");

struct {
	__uint(type, BPF_MAP_TYPE_LRU_HASH);
	__uint(max_entries, 4096);
	__type(key, struct dns_key);
	__type(value, struct dns_val);
} dns_names SEC(".maps");

struct {
	__uint(type, BPF_MAP_TYPE_LRU_HASH);
	__uint(max_entries, 4096);
	__type(key, struct dns_pending_key);
	__type(value, struct dns_pending);
} dns_pending SEC(".maps");

// A plain hash (an LRU update costs a lock per wakeup): when full, new wakeups are not measured until entries go.
struct {
	__uint(type, BPF_MAP_TYPE_HASH);
	__uint(max_entries, 16384);
	__type(key, __u32);
	__type(value, __u64);
} runq_start SEC(".maps");

static __always_inline void *lookup_or_init(void *map, const void *key, const void *zero)
{
	void *v = bpf_map_lookup_elem(map, key);
	if (v)
		return v;
	bpf_map_update_elem(map, key, zero, BPF_NOEXIST);
	return bpf_map_lookup_elem(map, key);
}

static __always_inline __u32 log2_slot(__u64 v)
{
	__u32 r = 0;
	if (v >= (1ULL << 32)) {
		v >>= 32;
		r += 32;
	}
	if (v >= (1 << 16)) {
		v >>= 16;
		r += 16;
	}
	if (v >= (1 << 8)) {
		v >>= 8;
		r += 8;
	}
	if (v >= (1 << 4)) {
		v >>= 4;
		r += 4;
	}
	if (v >= (1 << 2)) {
		v >>= 2;
		r += 2;
	}
	if (v >= (1 << 1))
		r += 1;
	return r < SLOTS ? r : SLOTS - 1;
}

static __always_inline void hist_add(struct owner o, __u32 kind, __u64 us)
{
	struct hist_key k = {};
	struct hist_val zero = {};
	k.o = o;
	k.kind = kind;
	struct hist_val *h = lookup_or_init(&hists, &k, &zero);
	if (!h)
		return;
	__u32 slot = log2_slot(us);
	if (slot >= SLOTS)
		return;
	__sync_fetch_and_add(&h->slots[slot], 1);
	__sync_fetch_and_add(&h->sum_us, us);
	__sync_fetch_and_add(&h->count, 1);
}

// The socket's own cgroup (kernels 5.15+: sk_cgrp_data is a cgroup pointer); 0 when unknown.
static __always_inline __u64 sock_cgid(struct sock *sk)
{
	if (!bpf_core_field_exists(sk->sk_cgrp_data.cgroup))
		return 0;
	struct cgroup *cg = BPF_CORE_READ(sk, sk_cgrp_data.cgroup);
	if (!cg)
		return 0;
	return BPF_CORE_READ(cg, kn, id);
}

// A full socket (not a time-wait or request socket, which end after sock_common).
static __always_inline bool full_sock(struct sock *sk)
{
	__u8 state = BPF_CORE_READ(sk, __sk_common.skc_state);
	return state != TCP_TIME_WAIT && state != TCP_NEW_SYN_RECV;
}

// Fills the peer key of a socket: its owner and direction from `conns` when the probe saw it open, else from the
// socket itself (the lower port is taken for the server's).
static __always_inline int peer_of(struct sock *sk, struct peer_key *k)
{
	__u64 skp = (__u64)sk;
	__u16 family = BPF_CORE_READ(sk, __sk_common.skc_family);
	if (family == AF_INET) {
		__be32 d = BPF_CORE_READ(sk, __sk_common.skc_daddr);
		__builtin_memcpy(k->addr, &d, 4);
	} else if (family == AF_INET6) {
		BPF_CORE_READ_INTO(&k->addr, sk, __sk_common.skc_v6_daddr.in6_u.u6_addr8);
	} else {
		return -1;
	}
	k->family = family;
	__u16 dport = bpf_ntohs(BPF_CORE_READ(sk, __sk_common.skc_dport));
	struct conn *c = bpf_map_lookup_elem(&conns, &skp);
	if (c) {
		k->o = c->o;
		k->dir = c->dir;
	} else {
		if (full_sock(sk)) {
			k->o.cgid = sock_cgid(sk);
			k->o.uid = BPF_CORE_READ(sk, sk_uid.val);
		}
		__u16 lport = BPF_CORE_READ(sk, __sk_common.skc_num);
		k->dir = dport < lport ? DIR_OUT : DIR_IN;
	}
	k->port = k->dir == DIR_OUT ? dport : 0;
	return 0;
}

static __always_inline struct peer_val *peer_val(const struct peer_key *k)
{
	struct peer_val zero = {};
	return lookup_or_init(&peers, k, &zero);
}

SEC("tp_btf/inet_sock_set_state")
int BPF_PROG(on_set_state, struct sock *sk, int oldstate, int newstate)
{
	if (BPF_CORE_READ(sk, sk_protocol) != IPPROTO_TCP)
		return 0;
	__u64 skp = (__u64)sk;
	__u64 now = bpf_ktime_get_ns();

	if (newstate == TCP_SYN_SENT) {
		// connect(): the caller's context.
		struct conn c = {};
		c.o.cgid = bpf_get_current_cgroup_id();
		c.o.tgid = bpf_get_current_pid_tgid() >> 32;
		c.o.uid = (__u32)bpf_get_current_uid_gid();
		c.start_ns = now;
		c.dir = DIR_OUT;
		bpf_map_update_elem(&conns, &skp, &c, BPF_ANY);
		return 0;
	}
	if (oldstate == TCP_SYN_RECV && newstate == TCP_ESTABLISHED) {
		// A passive open completing (softirq: the socket's own cgroup and uid).
		struct conn c = {};
		c.o.cgid = sock_cgid(sk);
		c.o.uid = BPF_CORE_READ(sk, sk_uid.val);
		c.dir = DIR_IN;
		bpf_map_update_elem(&conns, &skp, &c, BPF_ANY);
		return 0;
	}

	struct conn *c = bpf_map_lookup_elem(&conns, &skp);
	if (oldstate == TCP_SYN_SENT) {
		if (!c)
			return 0;
		struct peer_key k = {};
		if (peer_of(sk, &k))
			return 0;
		struct peer_val *v = peer_val(&k);
		if (newstate == TCP_ESTABLISHED) {
			__u64 us = (now - c->start_ns) / 1000;
			if (v)
				__sync_fetch_and_add(&v->connects, 1);
			hist_add(c->o, H_CONNECT, us);
		} else if (newstate == TCP_CLOSE) {
			if (v)
				__sync_fetch_and_add(&v->connect_fail, 1);
			bpf_map_delete_elem(&conns, &skp);
		}
		return 0;
	}
	if (newstate != TCP_CLOSE)
		return 0;

	// Closing: one RTT sample per connection that measured one.
	__u32 srtt = BPF_CORE_READ((struct tcp_sock *)sk, srtt_us) >> 3;
	if (srtt) {
		struct peer_key k = {};
		if (!peer_of(sk, &k)) {
			struct peer_val *v = peer_val(&k);
			if (v) {
				__sync_fetch_and_add(&v->rtt_sum_us, srtt);
				__sync_fetch_and_add(&v->rtt_count, 1);
			}
			hist_add(k.o, H_RTT, srtt);
		}
	}
	if (c)
		bpf_map_delete_elem(&conns, &skp);
	return 0;
}

SEC("tp_btf/tcp_retransmit_skb")
int BPF_PROG(on_retransmit, struct sock *sk)
{
	struct peer_key k = {};
	if (!sk || peer_of(sk, &k))
		return 0;
	struct peer_val *v = peer_val(&k);
	if (v)
		__sync_fetch_and_add(&v->retrans, 1);
	return 0;
}

SEC("tp_btf/tcp_send_reset")
int BPF_PROG(on_send_reset, struct sock *sk)
{
	struct peer_key k = {};
	if (!sk || peer_of(sk, &k))
		return 0;
	struct peer_val *v = peer_val(&k);
	if (v)
		__sync_fetch_and_add(&v->rst_sent, 1);
	return 0;
}

SEC("tp_btf/tcp_receive_reset")
int BPF_PROG(on_receive_reset, struct sock *sk)
{
	struct peer_key k = {};
	if (!sk || peer_of(sk, &k))
		return 0;
	struct peer_val *v = peer_val(&k);
	if (v)
		__sync_fetch_and_add(&v->rst_recv, 1);
	return 0;
}

// Copies the query name (wire format) and zeroes what follows its root label, so a name is one key whatever the
// query type behind it. A name longer than QNAME_LEN keeps its first QNAME_LEN bytes.
static __always_inline void read_qname(struct __sk_buff *skb, __u32 off, __u8 *buf)
{
	__u32 n = skb->len > off ? skb->len - off : 0;
	if (n > QNAME_LEN)
		n = QNAME_LEN;
	if (n < 2 || n > QNAME_LEN)
		return;
	if (bpf_skb_load_bytes(skb, off, buf, n))
		return;
	// Constant offsets only (the verifier refuses variable-offset stack reads): walk the labels by position.
	__u32 next = 0;
	bool done = false;
#pragma unroll
	for (int j = 0; j < QNAME_LEN; j++) {
		if (done) {
			buf[j] = 0;
			continue;
		}
		if (j == next) {
			__u8 l = buf[j];
			if (l == 0 || l > 63) {
				buf[j] = 0;
				done = true;
			} else {
				next = j + l + 1;
			}
		}
	}
}

struct udphdr_ {
	__be16 source;
	__be16 dest;
	__be16 len;
	__be16 check;
};

struct dnshdr_ {
	__be16 id;
	__be16 flags;
	__be16 qdcount;
	__be16 ancount;
	__be16 nscount;
	__be16 arcount;
};

static __always_inline int dns(struct __sk_buff *skb, bool egress)
{
	__u8 b;
	__u8 proto;
	__u32 off;
	if (bpf_skb_load_bytes(skb, 0, &b, 1))
		return 1;
	if ((b >> 4) == 4) {
		off = (b & 0xf) * 4;
		if (bpf_skb_load_bytes(skb, 9, &proto, 1))
			return 1;
	} else if ((b >> 4) == 6) {
		off = 40;
		if (bpf_skb_load_bytes(skb, 6, &proto, 1))
			return 1;
	} else {
		return 1;
	}
	if (proto != IPPROTO_UDP)
		return 1;
	struct udphdr_ udp;
	if (bpf_skb_load_bytes(skb, off, &udp, sizeof(udp)))
		return 1;
	if (bpf_ntohs(egress ? udp.dest : udp.source) != 53)
		return 1;
	struct dnshdr_ h;
	off += sizeof(udp);
	if (bpf_skb_load_bytes(skb, off, &h, sizeof(h)))
		return 1;
	__u16 flags = bpf_ntohs(h.flags);
	bool response = flags >> 15;
	if (response == egress || h.qdcount == 0)
		return 1;
	struct dns_pending_key pk = {};
	pk.cookie = bpf_get_socket_cookie(skb);
	pk.id = h.id;
	__u64 now = bpf_ktime_get_ns();
	struct dns_val zero = {};

	if (egress) {
		struct dns_pending p = {};
		p.ts = now;
		p.k.cgid = bpf_skb_cgroup_id(skb);
		p.k.uid = bpf_get_socket_uid(skb);
		read_qname(skb, off + sizeof(h), p.k.qname);
		struct dns_val *v = lookup_or_init(&dns_names, &p.k, &zero);
		if (v)
			__sync_fetch_and_add(&v->queries, 1);
		bpf_map_update_elem(&dns_pending, &pk, &p, BPF_ANY);
		return 1;
	}

	struct dns_pending *q = bpf_map_lookup_elem(&dns_pending, &pk);
	if (!q)
		return 1;
	__u64 us = (now - q->ts) / 1000;
	struct dns_val *v = lookup_or_init(&dns_names, &q->k, &zero);
	if (v) {
		__sync_fetch_and_add(&v->responses, 1);
		__sync_fetch_and_add(&v->lat_sum_us, us);
		switch (flags & 0xf) {
		case 0:
			break;
		case 2:
			__sync_fetch_and_add(&v->servfail, 1);
			break;
		case 3:
			__sync_fetch_and_add(&v->nxdomain, 1);
			break;
		case 5:
			__sync_fetch_and_add(&v->refused, 1);
			break;
		default:
			__sync_fetch_and_add(&v->other_err, 1);
		}
	}
	struct owner o = {.cgid = q->k.cgid, .uid = q->k.uid};
	hist_add(o, H_DNS, us);
	bpf_map_delete_elem(&dns_pending, &pk);
	return 1;
}

SEC("cgroup_skb/egress")
int dns_egress(struct __sk_buff *skb)
{
	dns(skb, true);
	return 1;
}

SEC("cgroup_skb/ingress")
int dns_ingress(struct __sk_buff *skb)
{
	dns(skb, false);
	return 1;
}

static __always_inline long task_state(struct task_struct *t)
{
	if (bpf_core_field_exists(t->__state))
		return BPF_CORE_READ(t, __state);
	return BPF_CORE_READ((struct task_struct___pre514 *)t, state);
}

static __always_inline void enqueue(struct task_struct *p)
{
	__u32 pid = BPF_CORE_READ(p, pid);
	if (!pid)
		return;
	__u64 ts = bpf_ktime_get_ns();
	bpf_map_update_elem(&runq_start, &pid, &ts, BPF_ANY);
}

SEC("tp_btf/sched_wakeup")
int BPF_PROG(on_wakeup, struct task_struct *p)
{
	enqueue(p);
	return 0;
}

SEC("tp_btf/sched_wakeup_new")
int BPF_PROG(on_wakeup_new, struct task_struct *p)
{
	enqueue(p);
	return 0;
}

// A task that exits without being switched in again (woken while running, then gone) would keep its stamp: the plain
// hash would fill and a reused pid would inherit a stale wakeup. Its stamp goes with it.
SEC("tp_btf/sched_process_exit")
int BPF_PROG(on_exit, struct task_struct *p)
{
	__u32 pid = BPF_CORE_READ(p, pid);
	bpf_map_delete_elem(&runq_start, &pid);
	return 0;
}

SEC("tp_btf/sched_switch")
int BPF_PROG(on_switch, bool preempt, struct task_struct *prev, struct task_struct *next)
{
	// A preempted task is still runnable: it waits from now.
	if (task_state(prev) == TASK_RUNNING)
		enqueue(prev);
	__u32 pid = BPF_CORE_READ(next, pid);
	if (!pid)
		return 0;
	__u64 *ts = bpf_map_lookup_elem(&runq_start, &pid);
	if (!ts)
		return 0;
	__u64 us = (bpf_ktime_get_ns() - *ts) / 1000;
	bpf_map_delete_elem(&runq_start, &pid);
	struct owner o = {};
	o.cgid = BPF_CORE_READ(next, cgroups, dfl_cgrp, kn, id);
	if (!(BPF_CORE_READ(next, flags) & PF_KTHREAD))
		o.tgid = BPF_CORE_READ(next, tgid);
	hist_add(o, H_RUNQ, us);
	return 0;
}

char LICENSE[] SEC("license") = "Dual BSD/GPL";
