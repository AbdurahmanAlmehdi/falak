//go:build !linux

package netobs

import "errors"

// dumpConntrack needs Linux netlink.
func dumpConntrack(int) ([]Flow, bool, error) {
	return nil, false, errors.New("conntrack: not supported on this platform")
}
