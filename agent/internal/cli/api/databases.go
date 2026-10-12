package api

import (
	"context"
	"net/http"
)

// Database container endpoints (`falak db …`). {instance} is the instance id.
const (
	PathDatabaseInstances = "/api/v1/databases/instances"            // GET → {data:[DatabaseInstance]}
	PathDatabaseInstance  = "/api/v1/databases/instances/{instance}" // GET → {data:DatabaseInstance}; DELETE {confirm, delete_volume?} → 202 {data:DatabaseInstance}
)

// DatabaseInstance is a database container (never carries a password).
type DatabaseInstance struct {
	ID             string `json:"id"`
	Name           string `json:"name"`
	ServerID       string `json:"server_id"`
	ServerName     string `json:"server_name"`
	Engine         string `json:"engine"`
	EngineLabel    string `json:"engine_label"`
	Version        string `json:"version"`
	Hostname       string `json:"hostname"`
	Port           int    `json:"port"`
	MemoryMB       int    `json:"memory_mb"`
	VolumeID       string `json:"volume_id"`
	Status         string `json:"status"`
	StatusMessage  string `json:"status_message,omitempty"`
	Health         string `json:"health,omitempty"`
	DatabasesCount *int   `json:"databases_count"`
	CreatedAt      string `json:"created_at"`
}

func (c *Client) DatabaseInstances(ctx context.Context) ([]DatabaseInstance, error) {
	env, err := get[[]DatabaseInstance](ctx, c, PathDatabaseInstances, nil)
	return env.Data, err
}

func (c *Client) DatabaseInstance(ctx context.Context, id string) (DatabaseInstance, error) {
	env, err := get[DatabaseInstance](ctx, c, Path(PathDatabaseInstance, "instance", id), nil)
	return env.Data, err
}

// DeleteDatabaseInstance asks the agent to remove the container; confirm must be its name.
func (c *Client) DeleteDatabaseInstance(ctx context.Context, id, confirm string, deleteVolume bool) (DatabaseInstance, error) {
	var env Envelope[DatabaseInstance]
	body := map[string]any{"confirm": confirm, "delete_volume": deleteVolume}
	err := c.Do(ctx, http.MethodDelete, Path(PathDatabaseInstance, "instance", id), nil, body, &env)
	return env.Data, err
}
