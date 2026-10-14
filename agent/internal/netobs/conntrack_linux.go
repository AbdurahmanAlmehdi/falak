//go:build linux

package netobs

import (
	"encoding/binary"
	"errors"
	"time"

	"golang.org/x/sys/unix"
)

// dumpConntrack reads the conntrack table over netlink (NETLINK_NETFILTER, IPCTNL_MSG_CT_GET with NLM_F_DUMP, every
// address family), at most limit flows, in the agent's network namespace (the host's). Read-only. At the cap it stops
// reading and closes the socket (truncated: the rest of the dump is dropped by the kernel).
func dumpConntrack(limit int) (flows []Flow, truncated bool, err error) {
	fd, err := unix.Socket(unix.AF_NETLINK, unix.SOCK_RAW|unix.SOCK_CLOEXEC, unix.NETLINK_NETFILTER)
	if err != nil {
		return nil, false, err
	}
	defer unix.Close(fd)
	tv := unix.NsecToTimeval(int64(5 * time.Second))
	if err := unix.SetsockoptTimeval(fd, unix.SOL_SOCKET, unix.SO_RCVTIMEO, &tv); err != nil {
		return nil, false, err
	}
	if err := unix.Bind(fd, &unix.SockaddrNetlink{Family: unix.AF_NETLINK}); err != nil {
		return nil, false, err
	}
	req := make([]byte, nlmsgHdrLen+nfgenmsgLen)
	binary.NativeEndian.PutUint32(req[0:4], uint32(len(req)))
	binary.NativeEndian.PutUint16(req[4:6], nfnlSubsysCT<<8|1) // IPCTNL_MSG_CT_GET
	binary.NativeEndian.PutUint16(req[6:8], unix.NLM_F_REQUEST|unix.NLM_F_DUMP)
	binary.NativeEndian.PutUint32(req[8:12], 1)
	req[16] = unix.AF_UNSPEC // nfgenmsg: family (all), version 0, res_id 0
	if err := unix.Sendto(fd, req, 0, &unix.SockaddrNetlink{Family: unix.AF_NETLINK}); err != nil {
		return nil, false, err
	}
	buf := make([]byte, 1<<16)
	for {
		n, _, err := unix.Recvfrom(fd, buf, 0)
		if err != nil {
			if errors.Is(err, unix.EINTR) {
				continue
			}
			return flows, false, err
		}
		var done bool
		flows, done, truncated, err = parseConntrackMessages(buf[:n], flows, limit)
		if err != nil || done {
			return flows, truncated, err
		}
	}
}
