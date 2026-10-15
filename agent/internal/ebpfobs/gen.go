package ebpfobs

// The probe's objects (probe_x86_bpfel.o, probe_arm64_bpfel.o) and their Go loaders are generated from
// bpf/probe.bpf.c and committed, so builds and CI need no clang. Regenerate after editing the C file, with clang 17 or
// later built with the BPF target (the committed objects: Homebrew clang 23.1.3; Apple's clang has no BPF target):
//
//	PATH="/opt/homebrew/opt/llvm/bin:$PATH" go generate ./internal/ebpfobs

//go:generate go run github.com/cilium/ebpf/cmd/bpf2go -cc clang -cflags "-O2 -g -Wall -Werror" -target amd64,arm64 -no-global-types probe bpf/probe.bpf.c -- -I bpf/headers -I bpf
