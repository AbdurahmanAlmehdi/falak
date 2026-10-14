//go:build unix

package cli

import (
	"os"
	"os/signal"
	"syscall"
)

// windowChanges signals SIGWINCH (coalesced) until stop.
func windowChanges() (<-chan struct{}, func()) {
	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGWINCH)
	out := make(chan struct{}, 1)
	done := make(chan struct{})
	go func() {
		for {
			select {
			case <-sig:
				select {
				case out <- struct{}{}:
				default:
				}
			case <-done:
				return
			}
		}
	}()
	return out, func() { signal.Stop(sig); close(done) }
}
