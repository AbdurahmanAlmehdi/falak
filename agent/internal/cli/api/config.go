package api

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
)

// Config as code endpoints (docs/plans/CONFIG_AS_CODE.md, "Phase 0 contract (fixed)", API). {project} is the
// project id.
const (
	PathProjects       = "/api/v1/projects"                           // GET → {data:[Project]}
	PathConfigValidate = "/api/v1/projects/{project}/config/validate" // POST {file, format, environment?} → {data:ConfigValidation}
	PathConfigPlan     = "/api/v1/projects/{project}/config/plan"     // POST {file|commit, format, environment} → {data:ConfigPlanResult}; 422 invalid; 409 not enabled
	PathConfigRuns     = "/api/v1/projects/{project}/config/runs"     // POST {file|commit, format, environment, allow_destroy} → 201 {data:ConfigRun}
	PathConfigExport   = "/api/v1/projects/{project}/config/export"   // GET ?environment=&format= → {data:ConfigExport}
	PathConfigRun      = "/api/v1/config/runs/{run}"                  // GET → {data:ConfigRun}
)

// Project is one project of GET /projects.
type Project struct {
	ID           string               `json:"id"`
	Name         string               `json:"name"`
	IsDefault    bool                 `json:"is_default,omitempty"`
	Environments []ProjectEnvironment `json:"environments,omitempty"`
}

// ProjectEnvironment is one environment of a project.
type ProjectEnvironment struct {
	ID           string `json:"id"`
	Name         string `json:"name"`
	Slug         string `json:"slug"`
	IsProduction bool   `json:"is_production"`
}

// ConfigIssue is one error or warning about a falak.yaml (line and column are null when unknown).
type ConfigIssue struct {
	Code     string  `json:"code"`
	Pointer  string  `json:"pointer"`
	Source   *string `json:"source"`
	Line     *int    `json:"line"`
	Column   *int    `json:"column"`
	Message  string  `json:"message"`
	Severity string  `json:"severity,omitempty"`
}

// ConfigChange is one change of a plan (Change::toArray()).
type ConfigChange struct {
	Op      string `json:"op"` // create | update | delete | detach | noop
	Path    string `json:"path"`
	Before  any    `json:"before"`
	After   any    `json:"after"`
	Section string `json:"section,omitempty"`
	Unit    string `json:"unit,omitempty"`
	Risky   bool   `json:"risky"`
	Reason  string `json:"reason,omitempty"`
	Rule    string `json:"rule,omitempty"`
}

// ConfigPlan is a ChangeSet.
type ConfigPlan struct {
	Changes []ConfigChange `json:"changes"`
	Summary map[string]int `json:"summary"`
	Risky   bool           `json:"risky"`
	Deploy  []string       `json:"deploy"`
}

// ConfigRequest is the body of validate, plan and runs: exactly one of File and Commit.
type ConfigRequest struct {
	File         string `json:"file,omitempty"`
	Commit       string `json:"commit,omitempty"`
	Format       string `json:"format,omitempty"`
	Environment  string `json:"environment,omitempty"`
	AllowDestroy *bool  `json:"allow_destroy,omitempty"`
}

// ConfigValidation is POST …/config/validate.
type ConfigValidation struct {
	Valid        bool            `json:"valid"`
	Environments []string        `json:"environments"`
	Errors       []ConfigIssue   `json:"errors"`
	Warnings     []ConfigIssue   `json:"warnings"`
	Raw          json.RawMessage `json:"-"`
}

// ConfigPlanResult is POST …/config/plan.
type ConfigPlanResult struct {
	Environment string          `json:"environment"`
	Commit      *string         `json:"commit"`
	Resolved    json.RawMessage `json:"resolved"`
	Plan        ConfigPlan      `json:"plan"`
	Warnings    []ConfigIssue   `json:"warnings"`
	Raw         json.RawMessage `json:"-"`
}

// ConfigRunDeployment is a deployment a run triggered.
type ConfigRunDeployment struct {
	Unit         string `json:"unit"`
	DeploymentID string `json:"deployment_id"`
	Status       string `json:"status"`
}

// ConfigRun is a config run (POST …/config/runs, GET /config/runs/{run}).
type ConfigRun struct {
	ID           string                `json:"id"`
	ProjectID    string                `json:"project_id"`
	Environment  string                `json:"environment"`
	Commit       *string               `json:"commit"`
	Trigger      string                `json:"trigger"`
	Status       string                `json:"status"` // planning, awaiting_approval, applying, applied, failed, cancelled, refused
	Plan         *ConfigPlan           `json:"plan"`
	Errors       []ConfigIssue         `json:"errors"`
	Error        *string               `json:"error"`
	AllowDestroy bool                  `json:"allow_destroy"`
	Deployments  []ConfigRunDeployment `json:"deployments"`
	CreatedAt    string                `json:"created_at,omitempty"`
	FinishedAt   *string               `json:"finished_at"`
	URL          string                `json:"url,omitempty"`
	Raw          json.RawMessage       `json:"-"`
}

// Config run statuses.
const (
	RunPlanning         = "planning"
	RunAwaitingApproval = "awaiting_approval"
	RunApplying         = "applying"
	RunApplied          = "applied"
	RunFailed           = "failed"
	RunCancelled        = "cancelled"
	RunRefused          = "refused"
)

// Done reports a status the run doesn't leave on its own (awaiting_approval waits for a person).
func (r ConfigRun) Done() bool {
	switch r.Status {
	case RunApplied, RunFailed, RunCancelled, RunRefused, RunAwaitingApproval:
		return true
	}
	return false
}

// ConfigExport is GET …/config/export.
type ConfigExport struct {
	Environment string          `json:"environment"`
	Format      string          `json:"format"`
	File        string          `json:"file"`
	Raw         json.RawMessage `json:"-"`
}

// ConfigInvalidError is a 422 answer carrying the file's issues.
type ConfigInvalidError struct {
	Message  string
	Errors   []ConfigIssue
	Warnings []ConfigIssue
}

func (e *ConfigInvalidError) Error() string {
	if e.Message != "" {
		return e.Message
	}
	return fmt.Sprintf("the file is invalid (%d errors)", len(e.Errors))
}

// configErr turns a 422 with config_errors into a ConfigInvalidError.
func configErr(err error) error {
	var e *Error
	if !errors.As(err, &e) || e.Status != http.StatusUnprocessableEntity {
		return err
	}
	var body struct {
		Message  string        `json:"message"`
		Errors   []ConfigIssue `json:"config_errors"`
		Warnings []ConfigIssue `json:"config_warnings"`
	}
	if json.Unmarshal(e.Body, &body) != nil || len(body.Errors) == 0 {
		return err
	}
	return &ConfigInvalidError{Message: body.Message, Errors: body.Errors, Warnings: body.Warnings}
}

// rawData decodes {data: …} into out and keeps data's raw JSON.
func (c *Client) rawData(ctx context.Context, method, path string, q url.Values, body, out any) (json.RawMessage, error) {
	var env struct {
		Data json.RawMessage `json:"data"`
	}
	if err := c.Do(ctx, method, path, q, body, &env); err != nil {
		return nil, configErr(err)
	}
	if len(env.Data) == 0 {
		return nil, fmt.Errorf("%s %s: response has no data", method, path)
	}
	if err := json.Unmarshal(env.Data, out); err != nil {
		return nil, fmt.Errorf("%s %s: decode response: %w", method, path, err)
	}
	return env.Data, nil
}

func (c *Client) Projects(ctx context.Context) ([]Project, error) {
	env, err := get[[]Project](ctx, c, PathProjects, nil)
	return env.Data, err
}

func (c *Client) ConfigValidate(ctx context.Context, project string, r ConfigRequest) (ConfigValidation, error) {
	var v ConfigValidation
	raw, err := c.rawData(ctx, http.MethodPost, Path(PathConfigValidate, "project", project), nil, r, &v)
	v.Raw = raw
	return v, err
}

func (c *Client) ConfigPlan(ctx context.Context, project string, r ConfigRequest) (ConfigPlanResult, error) {
	var p ConfigPlanResult
	raw, err := c.rawData(ctx, http.MethodPost, Path(PathConfigPlan, "project", project), nil, r, &p)
	p.Raw = raw
	return p, err
}

func (c *Client) ConfigApply(ctx context.Context, project string, r ConfigRequest) (ConfigRun, error) {
	var run ConfigRun
	raw, err := c.rawData(ctx, http.MethodPost, Path(PathConfigRuns, "project", project), nil, r, &run)
	run.Raw = raw
	return run, err
}

func (c *Client) ConfigRun(ctx context.Context, id string) (ConfigRun, error) {
	var run ConfigRun
	raw, err := c.rawData(ctx, http.MethodGet, Path(PathConfigRun, "run", id), nil, nil, &run)
	run.Raw = raw
	return run, err
}

func (c *Client) ConfigExport(ctx context.Context, project, environment, format string) (ConfigExport, error) {
	q := url.Values{}
	if environment != "" {
		q.Set("environment", environment)
	}
	if format != "" {
		q.Set("format", format)
	}
	var x ConfigExport
	raw, err := c.rawData(ctx, http.MethodGet, Path(PathConfigExport, "project", project), q, nil, &x)
	x.Raw = raw
	return x, err
}
