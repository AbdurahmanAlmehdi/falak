package logs

import (
	"os"
	"syscall"
)

// pseudoFilesystems are the statfs magics of kernel filesystems whose files are kernel state, not logs (linux/magic.h).
var pseudoFilesystems = map[int64]bool{
	0x9fa0:     true, // PROC_SUPER_MAGIC
	0x62656572: true, // SYSFS_MAGIC
	0x64626720: true, // DEBUGFS_MAGIC
	0x74726163: true, // TRACEFS_MAGIC
	0x27e0eb:   true, // CGROUP_SUPER_MAGIC
	0x63677270: true, // CGROUP2_SUPER_MAGIC
	0x73636673: true, // SECURITYFS_MAGIC
	0x1cd1:     true, // DEVPTS_SUPER_MAGIC
	0xcafe4a11: true, // BPF_FS_MAGIC
	0x6165676c: true, // PSTOREFS_MAGIC
	0xde5e81e4: true, // EFIVARFS_MAGIC
	0x62656570: true, // CONFIGFS_MAGIC
	0x65735543: true, // FUSECTL_SUPER_MAGIC
	0x19800202: true, // MQUEUE_MAGIC
	0x6e736673: true, // NSFS_MAGIC
	0xf97cff8c: true, // SELINUX_MAGIC
	0x42494e4d: true, // BINFMTFS_MAGIC
	0x534f434b: true, // SOCKFS_MAGIC
	0x50495045: true, // PIPEFS_MAGIC
	0x09041934: true, // ANON_INODE_FS_MAGIC
}

// checkFilesystem refuses a file on a kernel pseudo filesystem (fstatfs of the open descriptor: no path involved).
func checkFilesystem(f *os.File) error {
	rc, err := f.SyscallConn()
	if err != nil {
		return err
	}
	var st syscall.Statfs_t
	var serr error
	if err := rc.Control(func(fd uintptr) { serr = syscall.Fstatfs(int(fd), &st) }); err != nil {
		return err
	}
	if serr != nil {
		return serr
	}
	if pseudoFilesystems[int64(st.Type)] {
		return errPseudoFS
	}
	return nil
}
