package nettool

import (
	"context"
	"net/netip"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"syscall"
	"testing"
	"time"
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

func TestNamesFromContainerContents(t *testing.T) {
	n, err := newNames("", true, "nameserver 127.0.0.11\n", "10.1.2.3 cache.internal\n")
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
	n, _ = newNames("9.9.9.9", true, "nameserver 127.0.0.11\n", "10.1.2.3 cache.internal\n")
	if n.servers[0] != "9.9.9.9:53" || len(n.hosts) != 0 {
		t.Fatalf("nameserver override: %v %v", n.servers, n.hosts)
	}
	if _, err := newNames("", true, "search x\n", ""); err == nil {
		t.Fatal("a resolv.conf without nameservers is an error")
	}
	// On the host: the system resolver, no hosts map of ours.
	if n, err := newNames("", false, "", ""); err != nil || len(n.servers) != 0 || n.source != "system resolver" {
		t.Fatalf("host: %v %+v", err, n)
	}
}

func TestReadRegularFile(t *testing.T) {
	dir := t.TempDir()
	good := filepath.Join(dir, "resolv.conf")
	_ = os.WriteFile(good, []byte("nameserver 1.1.1.1\n"), 0o644)
	if s, err := readRegularFile(good); err != nil || s != "nameserver 1.1.1.1\n" {
		t.Fatalf("regular: %q %v", s, err)
	}

	// A symlink (even to a regular file) is refused: O_NOFOLLOW.
	link := filepath.Join(dir, "link")
	_ = os.Symlink(good, link)
	if _, err := readRegularFile(link); err == nil {
		t.Fatal("symlink accepted")
	}

	// A FIFO is refused without blocking (nobody ever writes to it).
	fifo := filepath.Join(dir, "fifo")
	if err := syscall.Mkfifo(fifo, 0o644); err != nil {
		t.Fatal(err)
	}
	done := make(chan error, 1)
	go func() { _, err := readRegularFile(fifo); done <- err }()
	select {
	case err := <-done:
		if err == nil || !strings.Contains(err.Error(), "not a regular file") {
			t.Fatalf("fifo: %v", err)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("reading a FIFO blocked")
	}

	// A directory and a character device are refused too; an oversized file as well.
	if _, err := readRegularFile(dir); err == nil {
		t.Fatal("directory accepted")
	}
	if _, err := readRegularFile("/dev/null"); err == nil {
		t.Fatal("character device accepted")
	}
	big := filepath.Join(dir, "big")
	_ = os.WriteFile(big, make([]byte, maxHostFile+1), 0o644)
	if _, err := readRegularFile(big); err == nil {
		t.Fatal("oversized file accepted")
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
