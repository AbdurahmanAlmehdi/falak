// Package relay is falak-relay: a WebSocket relay that pipes a terminal session's bytes between the agent running it
// and the browsers or CLIs watching it (docs/plans/CONSOLE.md). The control plane mints the tokens; the relay only
// checks them, with a key both derive from APP_KEY.
package relay

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"
)

// keyLabel is the HMAC message that derives the relay key from APP_KEY.
const keyLabel = "falak-terminal-relay/v1"

// Token audiences: the agent's token (reusable for the session's life), a browser's or CLI's ticket (single use),
// and the control plane's own calls (aud "control": POST /internal/v1/sessions/{sid}/kick).
const (
	AudAgent   = "agent"
	AudClient  = "client"
	AudControl = "control"
)

// DeriveKey turns Laravel's APP_KEY ("base64:…", or raw bytes without the prefix) into the token key:
// HMAC-SHA256(key = app key bytes, message = "falak-terminal-relay/v1").
func DeriveKey(appKey string) ([]byte, error) {
	appKey = strings.TrimSpace(appKey)
	if appKey == "" {
		return nil, errors.New("APP_KEY is empty")
	}
	raw := []byte(appKey)
	if rest, ok := strings.CutPrefix(appKey, "base64:"); ok {
		b, err := base64.StdEncoding.DecodeString(rest)
		if err != nil {
			return nil, fmt.Errorf("APP_KEY: %w", err)
		}
		raw = b
	}
	m := hmac.New(sha256.New, raw)
	m.Write([]byte(keyLabel))
	return m.Sum(nil), nil
}

// Claims is a token's payload.
type Claims struct {
	V   int    `json:"v"`
	SID string `json:"sid"`
	Aud string `json:"aud"`
	Sub string `json:"sub"`
	W   bool   `json:"w"`
	Exp int64  `json:"exp"`
	N   string `json:"n"`
	// Fp (agent tokens, optional): the agent certificate's fingerprint (lowercase hex SHA-256 of the DER); the
	// relay then requires the edge's X-Falak-Client-Cert-Fingerprint to match.
	Fp string `json:"fp,omitempty"`
	// Keep (control tokens, kick): the user whose clients stay attached ("" = none).
	Keep string `json:"keep,omitempty"`
}

var b64 = base64.RawURLEncoding

// Sign returns b64url(payload) + "." + b64url(HMAC-SHA256(key, b64url(payload))) (tests; the control plane signs
// real tokens).
func Sign(key []byte, c Claims) string {
	b, _ := json.Marshal(c)
	p := b64.EncodeToString(b)
	return p + "." + b64.EncodeToString(mac(key, p))
}

func mac(key []byte, payload string) []byte {
	m := hmac.New(sha256.New, key)
	m.Write([]byte(payload))
	return m.Sum(nil)
}

// ErrToken is every token refusal (the reason is wrapped for the log, never shown to the caller).
var ErrToken = errors.New("invalid token")

// Verifier checks tokens and remembers the client tickets' nonces until they expire (single use).
type Verifier struct {
	key []byte
	now func() time.Time

	mu     sync.Mutex
	nonces map[string]int64 // nonce → exp
}

// NewVerifier returns a Verifier for key; now nil = time.Now.
func NewVerifier(key []byte, now func() time.Time) *Verifier {
	if now == nil {
		now = time.Now
	}
	return &Verifier{key: key, now: now, nonces: map[string]int64{}}
}

// Verify checks token's signature (constant time), version, audience, expiry and session. A client ticket is
// consumed: a second Verify with the same nonce fails until it has expired.
func (v *Verifier) Verify(token, aud, sid string) (Claims, error) {
	var c Claims
	payload, sig, ok := strings.Cut(token, ".")
	if !ok || payload == "" || sig == "" {
		return c, fmt.Errorf("%w: malformed", ErrToken)
	}
	got, err := b64.DecodeString(sig)
	if err != nil || !hmac.Equal(got, mac(v.key, payload)) {
		return c, fmt.Errorf("%w: bad signature", ErrToken)
	}
	raw, err := b64.DecodeString(payload)
	if err != nil {
		return c, fmt.Errorf("%w: payload: %v", ErrToken, err)
	}
	if err := json.Unmarshal(raw, &c); err != nil {
		return c, fmt.Errorf("%w: payload: %v", ErrToken, err)
	}
	now := v.now().Unix()
	switch {
	case c.V != 1:
		return c, fmt.Errorf("%w: version %d", ErrToken, c.V)
	case c.Aud != aud:
		return c, fmt.Errorf("%w: audience %q", ErrToken, c.Aud)
	case c.Exp <= now:
		return c, fmt.Errorf("%w: expired", ErrToken)
	case c.SID != sid:
		return c, fmt.Errorf("%w: other session", ErrToken)
	}
	if aud != AudClient {
		return c, nil
	}
	if c.N == "" {
		return c, fmt.Errorf("%w: no nonce", ErrToken)
	}
	v.mu.Lock()
	defer v.mu.Unlock()
	if _, used := v.nonces[c.N]; used {
		return c, fmt.Errorf("%w: ticket already used", ErrToken)
	}
	v.nonces[c.N] = c.Exp
	return c, nil
}

// Sweep forgets the nonces of expired tickets (they fail on exp anyway).
func (v *Verifier) Sweep() {
	now := v.now().Unix()
	v.mu.Lock()
	defer v.mu.Unlock()
	for n, exp := range v.nonces {
		if exp <= now {
			delete(v.nonces, n)
		}
	}
}
