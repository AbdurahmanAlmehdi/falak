package relay

// ReplaySize is how much of a session's latest output a new client gets first.
const ReplaySize = 256 << 10

// ring keeps the last max bytes written to it. Its buffer grows with the output (a quiet session costs little) and
// turns circular once it reaches max.
type ring struct {
	buf      []byte
	max      int
	start, n int
}

func newRing(max int) *ring { return &ring{max: max} }

func (r *ring) write(p []byte) {
	c := r.max
	if len(r.buf) < c { // still growing: buf holds everything, in order
		if len(r.buf)+len(p) <= c {
			if cap(r.buf) < len(r.buf)+len(p) {
				nb := make([]byte, len(r.buf), min(c, max(2*cap(r.buf), len(r.buf)+len(p), 4096)))
				copy(nb, r.buf)
				r.buf = nb
			}
			r.buf = append(r.buf, p...)
			r.n = len(r.buf)
			return
		}
		// Full from here on: keep the last c bytes of buf+p in a buffer of exactly c.
		nb := make([]byte, c)
		k := 0
		if keep := c - len(p); keep > 0 {
			k = copy(nb, r.buf[len(r.buf)-keep:])
		}
		copy(nb[k:], p[max(0, len(p)-c):])
		r.buf, r.start, r.n = nb, 0, c
		return
	}
	if len(p) >= c {
		copy(r.buf, p[len(p)-c:])
		r.start, r.n = 0, c
		return
	}
	end := (r.start + r.n) % c
	k := copy(r.buf[end:], p)
	copy(r.buf, p[k:])
	r.n += len(p)
	if r.n > c {
		r.start = (r.start + r.n - c) % c
		r.n = c
	}
}

// bytes returns a copy of the buffered output, oldest first.
func (r *ring) bytes() []byte {
	out := make([]byte, r.n)
	k := copy(out, r.buf[r.start:min(r.start+r.n, len(r.buf))])
	copy(out[k:], r.buf[:r.n-k])
	return out
}
