package api

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
)

// Browser login (`falak login`, RFC 8628 device authorization) and `falak logout`'s revoke.
const (
	PathCliLogin      = "/api/v1/cli/login"       // POST {client_name} → 201 {data:CliLoginStart} (no token)
	PathCliLoginToken = "/api/v1/cli/login/token" // POST {device_code} → {data:CliLoginResult} | 400 {error}
	PathMeToken       = "/api/v1/me/token"        // DELETE → 204: revoke the token making the request
)

type CliLoginStart struct {
	DeviceCode              string `json:"device_code"`
	UserCode                string `json:"user_code"`
	VerificationURI         string `json:"verification_uri"`
	VerificationURIComplete string `json:"verification_uri_complete"`
	ExpiresIn               int    `json:"expires_in"`
	Interval                int    `json:"interval"`
}

// CliLoginResult is the collected token (the user and organization come from /me afterwards).
type CliLoginResult struct {
	Token string `json:"token"`
}

func (c *Client) CliLoginStart(ctx context.Context, clientName string) (CliLoginStart, error) {
	var env Envelope[CliLoginStart]
	err := c.Do(ctx, http.MethodPost, PathCliLogin, nil, map[string]string{"client_name": clientName}, &env)
	return env.Data, err
}

// CliLoginToken polls once. pending is the RFC 8628 error code while there is no token yet
// (authorization_pending, slow_down, access_denied, expired_token); err is anything else.
func (c *Client) CliLoginToken(ctx context.Context, deviceCode string) (res CliLoginResult, pending string, err error) {
	var env Envelope[CliLoginResult]
	err = c.Do(ctx, http.MethodPost, PathCliLoginToken, nil, map[string]string{"device_code": deviceCode}, &env)
	var ae *Error
	if errors.As(err, &ae) && ae.Status == http.StatusBadRequest {
		var b struct {
			Error string `json:"error"`
		}
		if json.Unmarshal(ae.Body, &b) == nil && b.Error != "" {
			return res, b.Error, nil
		}
	}
	return env.Data, "", err
}

func (c *Client) RevokeCurrentToken(ctx context.Context) error {
	return c.Do(ctx, http.MethodDelete, PathMeToken, nil, nil, nil)
}
