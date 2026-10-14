//go:build !linux

package logs

import "os"

// checkFilesystem: kernel pseudo filesystems are a Linux concern (the agent runs on Linux; other systems run tests).
func checkFilesystem(*os.File) error { return nil }
