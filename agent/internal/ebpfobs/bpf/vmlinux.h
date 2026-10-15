/* SPDX-License-Identifier: GPL-2.0-only OR BSD-2-Clause */
/*
 * The few kernel types probe.bpf.c reads, written by hand instead of a 3 MB bpftool dump. Every struct is
 * preserve_access_index: its fields are relocated by name at load time against the running kernel's BTF
 * (/sys/kernel/btf/vmlinux), so their order and the members left out do not matter. Fields the kernel keeps in
 * anonymous unions or structs are listed flat: CO-RE finds them through the anonymous members.
 */
#pragma once

typedef unsigned char __u8;
typedef short int __s16;
typedef short unsigned int __u16;
typedef int __s32;
typedef unsigned int __u32;
typedef long long int __s64;
typedef long long unsigned int __u64;
typedef __u8 u8;
typedef __s16 s16;
typedef __u16 u16;
typedef __s32 s32;
typedef __u32 u32;
typedef __s64 s64;
typedef __u64 u64;
typedef __u16 __be16;
typedef __u32 __be32;
typedef __u32 __wsum;
typedef _Bool bool;
#define true 1
#define false 0

enum bpf_map_type {
	BPF_MAP_TYPE_HASH = 1,
	BPF_MAP_TYPE_ARRAY = 2,
	BPF_MAP_TYPE_LRU_HASH = 9,
};

enum {
	BPF_ANY = 0,
	BPF_NOEXIST = 1,
};

/* UAPI: the context of cgroup_skb programs. Fixed layout (the verifier rewrites the accesses); only the leading
 * fields up to the ones read are needed. */
struct __sk_buff {
	__u32 len;
	__u32 pkt_type;
	__u32 mark;
	__u32 queue_mapping;
	__u32 protocol;
};

#pragma clang attribute push(__attribute__((preserve_access_index)), apply_to = record)

struct in6_addr {
	union {
		__u8 u6_addr8[16];
	} in6_u;
};

struct sock_common {
	__be32 skc_daddr;
	__be32 skc_rcv_saddr;
	__be16 skc_dport;
	__u16 skc_num;
	unsigned short skc_family;
	volatile unsigned char skc_state;
	struct in6_addr skc_v6_daddr;
};

struct kernfs_node {
	__u64 id;
};

struct cgroup {
	struct kernfs_node *kn;
};

/* Kernels 5.15+: the socket's cgroup v2 pointer. */
struct sock_cgroup_data {
	struct cgroup *cgroup;
};

typedef struct {
	__u32 val;
} kuid_t;

struct sock {
	struct sock_common __sk_common;
	__u16 sk_protocol;
	kuid_t sk_uid;
	struct sock_cgroup_data sk_cgrp_data;
};

struct tcp_sock {
	__u32 srtt_us;
};

struct css_set {
	struct cgroup *dfl_cgrp;
};

struct task_struct {
	unsigned int flags;
	unsigned int __state;
	int pid;
	int tgid;
	struct css_set *cgroups;
};

/* Kernels before 5.14 call the task state `state` (a long). */
struct task_struct___pre514 {
	volatile long state;
};

struct sk_buff;

#pragma clang attribute pop
