//go:build !(linux && (amd64 || arm64))

package ebpfobs

import (
	"errors"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

const platformSupported = false

func bpfWorks() error { return errors.New("not linux/amd64 or linux/arm64") }

func openProbe(hostfs.FS) (source, []string, error) {
	return nil, nil, errors.New("not linux/amd64 or linux/arm64")
}
