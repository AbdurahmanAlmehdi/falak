package api

import (
	"context"
	"net/http"
	"net/url"
)

// Secret store endpoints (`falak secret …`). {secret} is the secret id. Values are written, never read back.
const (
	PathSecrets     = "/api/v1/secrets"                // GET ?scope=&scope_id= → {data:[Secret]}; POST {name, scope, scope_id, value} → 201 {data:Secret}
	PathSecret      = "/api/v1/secrets/{secret}"       // DELETE → 204
	PathSecretValue = "/api/v1/secrets/{secret}/value" // PUT {value} → {data:Secret} (a new version)
)

// Secret is a secret's metadata (the API never returns a value here).
type Secret struct {
	ID             string `json:"id"`
	Name           string `json:"name"`
	Scope          string `json:"scope"`
	ScopeID        string `json:"scope_id"`
	ScopeLabel     string `json:"scope_label"`
	Kind           string `json:"kind"`
	CurrentVersion int    `json:"current_version"`
	UpdatedAt      string `json:"updated_at"`
}

func (c *Client) Secrets(ctx context.Context, scope, scopeID string) ([]Secret, error) {
	env, err := get[[]Secret](ctx, c, PathSecrets, url.Values{"scope": {scope}, "scope_id": {scopeID}})
	return env.Data, err
}

func (c *Client) CreateSecret(ctx context.Context, scope, scopeID, name, value string) (Secret, error) {
	var env Envelope[Secret]
	body := map[string]string{"name": name, "scope": scope, "scope_id": scopeID, "value": value}
	err := c.Do(ctx, http.MethodPost, PathSecrets, nil, body, &env)
	return env.Data, err
}

func (c *Client) SetSecretValue(ctx context.Context, id, value string) (Secret, error) {
	var env Envelope[Secret]
	err := c.Do(ctx, http.MethodPut, Path(PathSecretValue, "secret", id), nil, map[string]string{"value": value}, &env)
	return env.Data, err
}

func (c *Client) DeleteSecret(ctx context.Context, id string) error {
	return c.Do(ctx, http.MethodDelete, Path(PathSecret, "secret", id), nil, nil, nil)
}
