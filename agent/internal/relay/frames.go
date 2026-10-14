package relay

import "encoding/json"

// Subprotocol is accepted when a peer offers it (never required).
const Subprotocol = "falak.terminal.v1"

// Text frame types (binary frames are raw bytes: output from the agent, keystrokes from clients).
const (
	TResize  = "resize"  // client → agent {cols, rows}
	TExit    = "exit"    // agent → clients {reason, code}
	TWaiting = "waiting" // relay → client: no agent attached
	TReady   = "ready"   // relay → client: the agent is attached
	TError   = "error"   // relay → client {message}, then the relay closes it
)

// Frame is a decoded text frame (the fields of every type).
type Frame struct {
	T       string `json:"t"`
	Cols    int    `json:"cols,omitempty"`
	Rows    int    `json:"rows,omitempty"`
	Reason  string `json:"reason,omitempty"`
	Code    *int   `json:"code,omitempty"`
	Message string `json:"message,omitempty"`
}

// ParseFrame decodes a text frame; ok=false when it isn't a JSON object with a `t`.
func ParseFrame(b []byte) (Frame, bool) {
	var f Frame
	if json.Unmarshal(b, &f) != nil || f.T == "" {
		return f, false
	}
	return f, true
}

// ResizeFrame is {"t":"resize","cols":…,"rows":…}.
func ResizeFrame(cols, rows int) []byte {
	b, _ := json.Marshal(Frame{T: TResize, Cols: cols, Rows: rows})
	return b
}

// ExitFrame is {"t":"exit","reason":…,"code":…} (code null when unknown).
func ExitFrame(reason string, code *int) []byte {
	b, _ := json.Marshal(struct {
		T      string `json:"t"`
		Reason string `json:"reason"`
		Code   *int   `json:"code"`
	}{TExit, reason, code})
	return b
}

// ErrorFrame is {"t":"error","message":…}.
func ErrorFrame(msg string) []byte {
	b, _ := json.Marshal(Frame{T: TError, Message: msg})
	return b
}

// StatusFrame is {"t":"waiting"} or {"t":"ready"}.
func StatusFrame(t string) []byte {
	b, _ := json.Marshal(Frame{T: t})
	return b
}

// ValidSize reports whether a window size is in range (1–1000 each).
func ValidSize(cols, rows int) bool { return cols >= 1 && rows >= 1 && cols <= 1000 && rows <= 1000 }
