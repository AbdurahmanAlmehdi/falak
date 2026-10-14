//go:build !unix

package cli

// windowChanges: no SIGWINCH here; the size sent when the console attaches stays.
func windowChanges() (<-chan struct{}, func()) { return nil, func() {} }
