//go:build !linux

package netobs

import "errors"

// dumpConntrack needs Linux netlink.
func dumpConntrack(int) ([]Flow, error) {
	return nil, errors.New("conntrack: not supported on this platform")
}
