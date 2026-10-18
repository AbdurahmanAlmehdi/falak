package hostfs

import "syscall"

// DiskSpace reports the free (available to unprivileged users) and total bytes of the filesystem holding path.
func DiskSpace(path string) (free, total uint64, err error) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, 0, err
	}
	bs := uint64(st.Bsize)
	return uint64(st.Bavail) * bs, uint64(st.Blocks) * bs, nil
}
