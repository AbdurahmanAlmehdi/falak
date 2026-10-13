package cli

import (
	"context"
	"errors"
	"fmt"
	"os"
	"strings"
	"time"
)

// cliTokenPrefix names the tokens a browser login mints ("CLI — <host>"); logout revokes only those.
const cliTokenPrefix = "CLI — "

// browserLogin runs the RFC 8628 device flow: start, show the code, open the approval page, poll for the token.
func (a *App) browserLogin(ctx context.Context, url string, openBrowser bool) (string, error) {
	host, _ := os.Hostname()
	c := a.newAPI(url, "")
	st, err := c.CliLoginStart(ctx, host)
	if err != nil {
		return "", fmt.Errorf("start the browser login: %w", err)
	}
	fmt.Fprintf(a.Stderr, "Your code: %s\n", st.UserCode)
	opened := false
	if openBrowser && a.Open != nil {
		opened = a.Open(st.VerificationURIComplete) == nil
	}
	if opened {
		fmt.Fprintf(a.Stderr, "Opened %s\nApprove the request there if it shows the same code.\n", st.VerificationURIComplete)
	} else {
		fmt.Fprintf(a.Stderr, "Open %s on any device and approve the request if it shows the same code.\n", st.VerificationURIComplete)
	}
	fmt.Fprintln(a.Stderr, "Waiting for the approval…")

	wait := time.Duration(st.Interval) * time.Second
	if wait <= 0 {
		wait = a.Poll
	}
	deadline := time.Now().Add(time.Duration(st.ExpiresIn) * time.Second)
	if st.ExpiresIn <= 0 {
		deadline = time.Now().Add(10 * time.Minute)
	}
	for time.Now().Before(deadline) {
		select {
		case <-ctx.Done():
			return "", ctx.Err()
		case <-time.After(wait):
		}
		res, pending, err := c.CliLoginToken(ctx, st.DeviceCode)
		switch {
		case err != nil:
			return "", err
		case pending == "":
			return res.Token, nil
		case pending == "authorization_pending":
		case pending == "slow_down":
			wait += 5 * time.Second
		case pending == "access_denied":
			return "", errors.New("the login was denied in the browser")
		default:
			return "", errors.New("the login request expired: run `falak login` again")
		}
	}
	return "", errors.New("the login request expired: run `falak login` again")
}

// revokeStoredToken revokes a browser-login token on the server before logout forgets it. A token pasted in by hand
// may be shared (CI, another machine), so it is kept; the result says what happened.
func (a *App) revokeStoredToken(ctx context.Context, creds Credentials) string {
	if creds.URL == "" || creds.Token == "" {
		return ""
	}
	c := a.newAPI(creds.URL, creds.Token)
	me, err := c.Me(ctx)
	if err != nil {
		return " (the token could not be checked; revoke it in Settings → API tokens if it still exists)"
	}
	if me.Token == nil || !strings.HasPrefix(me.Token.Name, cliTokenPrefix) {
		return " (the API token you logged in with was kept: revoke it in Settings → API tokens if it isn't used elsewhere)"
	}
	if err := c.RevokeCurrentToken(ctx); err != nil {
		return " (revoking the token failed: " + err.Error() + ")"
	}
	return "; the token was revoked"
}
