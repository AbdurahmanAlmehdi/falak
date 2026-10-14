//go:build !linux

package logs

import "os"

// checkFilesystem: kernel pseudo filesystems are a Linux concern (the agent runs on Linux; other systems run tests).
func checkFilesystem(*os.File) error { return nil }

// openLog opens the file non-blocking and checks the open descriptor (no O_PATH outside Linux).
func openLog(open func(flag int) (*os.File, error), want os.FileInfo) (*os.File, error) {
	f, err := open(openFlags)
	if err != nil {
		return nil, err
	}
	if err := checkOpened(f, want); err != nil {
		f.Close()
		return nil, err
	}
	return f, nil
}
