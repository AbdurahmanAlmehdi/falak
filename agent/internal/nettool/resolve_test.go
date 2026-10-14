package nettool

import (
	"context"
	"net/netip"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
)

func TestParseResolvConf(t *testing.T) {
	got := parseResolvConf(strings.NewReader("# Docker\nsearch example.com\nnameserver 127.0.0.11\nnameserver  2001:db8::53 \nnameserver bogus\noptions ndots:0\nnameserver 1.1.1.1\nnameserver 8.8.8.8\n"))
	want := []string{"127.0.0.11:53", "[2001:db8::53]:53", "1.1.1.1:53"}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("got %v want %v", got, want)
	}
}

func TestParseHosts(t *testing.T) {
	h := parseHosts(strings.NewReader("127.0.0.1 localhost\n172.18.0.5\tweb web.shop # comment\n::1 localhost ip6-localhost\nbad line\n# 10.0.0.1 hidden\n"))
	if got := h["web.shop"]; len(got) != 1 || got[0] != netip.MustParseAddr("172.18.0.5") {
		t.Fatalf("web.shop: %v", got)
	}
	if got := h["localhost"]; len(got) != 2 {
		t.Fatalf("localhost: %v", got)
	}
	if _, ok := h["hidden"]; ok {
		t.Fatal("comment parsed")
	}
}

func TestNamesFromContainerFiles(t *testing.T) {
	dir := t.TempDir()
	rc := filepath.Join(dir, "resolv.conf")
	hosts := filepath.Join(dir, "hosts")
	_ = os.WriteFile(rc, []byte("nameserver 127.0.0.11\n"), 0o644)
	_ = os.WriteFile(hosts, []byte("10.1.2.3 cache.internal\n"), 0o644)
	n, err := newNames("", rc, hosts)
	if err != nil {
		t.Fatal(err)
	}
	if n.servers[0] != "127.0.0.11:53" || !strings.Contains(n.serverLabel(), "container resolv.conf") {
		t.Fatalf("servers %v label %s", n.servers, n.serverLabel())
	}
	addrs, err := n.lookupIP(context.Background(), "CACHE.internal.")
	if err != nil || len(addrs) != 1 || addrs[0].String() != "10.1.2.3" {
		t.Fatalf("hosts lookup: %v %v", addrs, err)
	}
	if addrs, _ := n.lookupIP(context.Background(), "192.0.2.1"); addrs[0].String() != "192.0.2.1" {
		t.Fatal("an IP is returned as is")
	}

	// A requested nameserver wins over the container's files (and its hosts file).
	n, _ = newNames("9.9.9.9", rc, hosts)
	if n.servers[0] != "9.9.9.9:53" || len(n.hosts) != 0 {
		t.Fatalf("nameserver override: %v %v", n.servers, n.hosts)
	}

	_ = os.WriteFile(rc, []byte("search x\n"), 0o644)
	if _, err := newNames("", rc, ""); err == nil {
		t.Fatal("a resolv.conf without nameservers is an error")
	}
	if _, err := newNames("", filepath.Join(dir, "missing"), ""); err == nil {
		t.Fatal("a missing resolv.conf is an error")
	}
}

func TestLinkLocal(t *testing.T) {
	for _, s := range []string{"169.254.169.254", "169.254.0.1", "fe80::1", "::ffff:169.254.169.254", "fd00:ec2::254"} {
		if !linkLocal(netip.MustParseAddr(s)) {
			t.Errorf("%s should be link-local", s)
		}
	}
	for _, s := range []string{"127.0.0.1", "10.0.0.1", "1.1.1.1", "::1", "fd00:ec2::1"} {
		if linkLocal(netip.MustParseAddr(s)) {
			t.Errorf("%s should not be link-local", s)
		}
	}
}
