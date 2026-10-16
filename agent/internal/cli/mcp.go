package cli

import (
	"bufio"
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

// `falak mcp`: a Model Context Protocol server on stdio (newline-delimited JSON-RPC 2.0; stdout carries only
// protocol messages). Its tools drive sandboxes through the HTTP API with the CLI's login.

const mcpProtocol = "2025-06-18"

// mcpProtocols are the versions a client may ask for; the tools-only subset used here is the same in each.
var mcpProtocols = map[string]bool{"2024-11-05": true, "2025-03-26": true, mcpProtocol: true}

const mcpInstructions = "Falak sandboxes are disposable Linux machines (gVisor-isolated; you are root inside, nothing " +
	"reaches the host, its sites or its network). Use one for anything destructive, untrusted or experimental: " +
	"sandbox_create, then sandbox_exec / sandbox_write_file / sandbox_read_file, and sandbox_kill when done. " +
	"Each sandbox has a time limit (sandbox_extend adds time); its files are gone when it is killed or expires. " +
	"Before a risky step, take a sandbox_snapshot (disk, memory and processes); sandbox_fork a snapshot to try " +
	"several approaches in parallel, and sandbox_kill the forks you don't keep."

func cmdMCP(ctx context.Context, a *App, args []string) error {
	if _, err := a.parse(a.flagSet("mcp"), args, 0, 0, ""); err != nil {
		return err
	}
	return a.serveMCP(ctx)
}

type rpcError struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

type rpcResponse struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id"`
	Result  any             `json:"result,omitempty"`
	Error   *rpcError       `json:"error,omitempty"`
}

// serveMCP answers requests one at a time until stdin ends.
// ponytail: sequential; a long sandbox_exec (≤ 120 s) delays the next request. Concurrent calls if clients need it.
func (a *App) serveMCP(ctx context.Context) error {
	sc := bufio.NewScanner(a.Stdin)
	sc.Buffer(make([]byte, 64<<10), 32<<20) // a sandbox_write_file line carries up to 5 MB of content
	for sc.Scan() {
		line := bytes.TrimSpace(sc.Bytes())
		if len(line) == 0 {
			continue
		}
		var req struct {
			ID     json.RawMessage `json:"id"`
			Method string          `json:"method"`
			Params json.RawMessage `json:"params"`
		}
		if err := json.Unmarshal(line, &req); err != nil {
			if err := writeJSONLine(a.Stdout, rpcResponse{JSONRPC: "2.0", ID: json.RawMessage("null"), Error: &rpcError{-32700, "parse error"}}); err != nil {
				return err
			}
			continue
		}
		if len(req.ID) == 0 { // a notification (notifications/initialized, …): never answered
			continue
		}
		resp := rpcResponse{JSONRPC: "2.0", ID: req.ID}
		resp.Result, resp.Error = a.mcpHandle(ctx, req.Method, req.Params)
		if err := writeJSONLine(a.Stdout, resp); err != nil {
			return err
		}
	}
	return sc.Err()
}

func (a *App) mcpHandle(ctx context.Context, method string, params json.RawMessage) (any, *rpcError) {
	switch method {
	case "initialize":
		var p struct {
			ProtocolVersion string `json:"protocolVersion"`
		}
		_ = json.Unmarshal(params, &p)
		version := mcpProtocol
		if mcpProtocols[p.ProtocolVersion] {
			version = p.ProtocolVersion
		}
		return map[string]any{
			"protocolVersion": version,
			"capabilities":    map[string]any{"tools": map[string]any{}},
			"serverInfo":      map[string]any{"name": "falak", "version": a.Version},
			"instructions":    mcpInstructions,
		}, nil
	case "ping":
		return map[string]any{}, nil
	case "tools/list":
		return map[string]any{"tools": mcpTools}, nil
	case "tools/call":
		var p struct {
			Name      string          `json:"name"`
			Arguments json.RawMessage `json:"arguments"`
		}
		if err := json.Unmarshal(params, &p); err != nil {
			return nil, &rpcError{-32602, "invalid params"}
		}
		for _, t := range mcpTools {
			if t.Name == p.Name {
				text, err := a.mcpCall(ctx, t, p.Arguments)
				if err != nil {
					return mcpResult(err.Error(), true), nil
				}
				return mcpResult(text, false), nil
			}
		}
		return nil, &rpcError{-32602, "unknown tool: " + p.Name}
	}
	return nil, &rpcError{-32601, "method not found: " + method}
}

func mcpResult(text string, isError bool) map[string]any {
	return map[string]any{"content": []map[string]any{{"type": "text", "text": text}}, "isError": isError}
}

// seconds is a duration argument given as a number of seconds or as "30m", "2h".
type seconds int64

func (s *seconds) UnmarshalJSON(b []byte) error {
	var n float64
	if json.Unmarshal(b, &n) == nil {
		*s = seconds(n)
		return nil
	}
	var str string
	if err := json.Unmarshal(b, &str); err != nil {
		return errors.New("a duration is a number of seconds or a string like 30m")
	}
	v, err := parseSeconds(str)
	*s = seconds(v)
	return err
}

// mcpArgs holds every tool's arguments; each tool's schema says which apply.
type mcpArgs struct {
	ID       string  `json:"id"`
	Image    string  `json:"image"`
	Name     string  `json:"name"`
	TTL      seconds `json:"ttl"`
	Network  string  `json:"network"`
	CPUs     float64 `json:"cpus"`
	MemoryMB int     `json:"memory_mb"`
	RepoURL  string  `json:"repo_url"`

	FromSnapshot string  `json:"from_snapshot"`
	IdlePause    seconds `json:"idle_pause"`
	Snapshot     string  `json:"snapshot"`
	Count        int     `json:"count"`
	Command      string  `json:"command"`
	Cwd          string  `json:"cwd"`
	Timeout      seconds `json:"timeout"`
	Path         string  `json:"path"`
	Content      *string `json:"content"`
	By           seconds `json:"by"`
}

type mcpTool struct {
	Name        string         `json:"name"`
	Description string         `json:"description"`
	InputSchema map[string]any `json:"inputSchema"`
	run         func(ctx context.Context, c api.API, id string, x mcpArgs) (string, error)
	needsID     bool
}

func (a *App) mcpCall(ctx context.Context, t *mcpTool, raw json.RawMessage) (string, error) {
	var x mcpArgs
	if len(raw) > 0 && string(raw) != "null" {
		if err := json.Unmarshal(raw, &x); err != nil {
			return "", fmt.Errorf("invalid arguments: %w", err)
		}
	}
	for _, req := range t.InputSchema["required"].([]string) {
		if (req == "id" && x.ID == "") || (req == "command" && x.Command == "") || (req == "path" && x.Path == "") ||
			(req == "snapshot" && x.Snapshot == "") || (req == "content" && x.Content == nil) || (req == "by" && x.By <= 0) {
			return "", fmt.Errorf("%s is required", req)
		}
	}
	c, err := a.api()
	if err != nil {
		return "", err
	}
	id := ""
	if t.needsID {
		if id, err = resolveSandbox(ctx, c, x.ID); err != nil {
			return "", err
		}
	}
	return t.run(ctx, c, id, x)
}

func compactJSON(v any) (string, error) {
	b, err := json.Marshal(v)
	return string(b), err
}

func schema(required []string, props map[string]any) map[string]any {
	if required == nil {
		required = []string{}
	}
	return map[string]any{"type": "object", "properties": props, "required": required, "additionalProperties": false}
}

func prop(typ, desc string) map[string]any { return map[string]any{"type": typ, "description": desc} }

var idProp = prop("string", "The sandbox's id or name (from sandbox_create or sandbox_list).")

// durationProp accepts "30m"/"2h" or seconds.
func durationProp(desc string) map[string]any {
	return map[string]any{"type": []string{"string", "integer"}, "description": desc}
}

// formatExec is sandbox_exec's text: the exit code, then each stream.
func formatExec(r api.SandboxExecResult) string {
	var b strings.Builder
	fmt.Fprintf(&b, "exit code: %d (%d ms)\n", r.ExitCode, r.DurationMS)
	if r.TimedOut {
		b.WriteString("timed out: the command was stopped at its timeout; run long jobs in the background (nohup … > /tmp/job.log 2>&1 &) and poll the log\n")
	}
	for _, s := range []struct{ name, out string }{{"stdout", r.Stdout}, {"stderr", r.Stderr}} {
		if s.out == "" {
			fmt.Fprintf(&b, "%s: (empty)\n", s.name)
			continue
		}
		fmt.Fprintf(&b, "%s:\n%s", s.name, s.out)
		if !strings.HasSuffix(s.out, "\n") {
			b.WriteString("\n")
		}
	}
	if r.Truncated {
		b.WriteString("(output truncated at 256 KB per stream: redirect to a file and read parts of it)\n")
	}
	return b.String()
}

var mcpTools = []*mcpTool{
	{
		Name: "sandbox_create",
		Description: "Create a disposable Linux sandbox and wait until it runs (up to about a minute). It is isolated by " +
			"gVisor: you are root inside and can install or break anything; nothing reaches the host, its sites or private " +
			"networks. Defaults: image ubuntu:24.04, 1 CPU, 1024 MB memory, 10 GB disk, a 1 h time limit, network internet. " +
			"Returns the sandbox as JSON; pass its id (or name) to the other sandbox tools. Kill it with sandbox_kill when " +
			"you are done; it is killed at expires_at anyway (sandbox_extend adds time).",
		InputSchema: schema(nil, map[string]any{
			"image":         prop("string", "Container image, e.g. ubuntu:24.04, node:22, python:3.13 (default ubuntu:24.04)."),
			"name":          prop("string", "A name to refer to it by (default: generated, e.g. brave-otter)."),
			"ttl":           durationProp("Time limit: \"30m\", \"2h\" or seconds (default 1h; the organization caps it)."),
			"network":       map[string]any{"type": "string", "enum": []string{"internet", "none"}, "description": "internet (default): the public internet only, never private networks or cloud metadata; none: loopback only."},
			"cpus":          prop("number", "CPUs (default 1)."),
			"memory_mb":     prop("integer", "Memory in MB (default 1024). Going over it kills the whole sandbox."),
			"repo_url":      prop("string", "A git repository to clone into the sandbox (public, or one the organization's secrets give access to). URL@ref picks a branch or tag."),
			"from_snapshot": prop("string", "A snapshot's name or id: the sandbox continues where it was taken (files, memory, processes) with its image, size and network. Not combined with image, cpus, memory_mb, network or repo_url."),
			"idle_pause":    durationProp("Pause the sandbox after this long unused (at least \"1m\"), e.g. \"10m\". Any later exec, file call or console resumes it."),
		}),
		run: func(ctx context.Context, c api.API, _ string, x mcpArgs) (string, error) {
			r := api.SandboxCreateRequest{Image: x.Image, Name: x.Name, TTL: int64(x.TTL), Network: x.Network, CPUs: x.CPUs, MemoryMB: x.MemoryMB,
				Snapshot: x.FromSnapshot, IdlePause: int64(x.IdlePause)}
			if x.FromSnapshot != "" && (x.Image != "" || x.Network != "" || x.CPUs != 0 || x.MemoryMB != 0 || x.RepoURL != "") {
				return "", errors.New("from_snapshot takes the snapshot's image, size and network: drop image, cpus, memory_mb, network and repo_url")
			}
			if r.IdlePause != 0 && r.IdlePause < 60 {
				return "", errors.New("idle_pause must be at least 1m")
			}
			if x.RepoURL != "" {
				u, ref := splitRepo(x.RepoURL)
				r.Repo = &api.SandboxRepo{URL: u, Ref: ref}
			}
			s, err := c.CreateSandbox(ctx, r, true)
			if err != nil {
				return "", err
			}
			if s.Status == "failed" {
				reason := "no reason given"
				if s.KillReason != nil {
					reason = *s.KillReason
				}
				return "", fmt.Errorf("sandbox %s failed to start: %s", s.Name, reason)
			}
			return compactJSON(s)
		},
	},
	{
		Name:        "sandbox_list",
		Description: "List the organization's sandboxes as JSON (id, name, status, image, server, limits, expires_at). Check it before creating another one: there is a cap on running sandboxes.",
		InputSchema: schema(nil, map[string]any{}),
		run: func(ctx context.Context, c api.API, _ string, _ mcpArgs) (string, error) {
			list, err := c.Sandboxes(ctx, "")
			if err != nil {
				return "", err
			}
			return compactJSON(list)
		},
	},
	{
		Name: "sandbox_exec",
		Description: "Run a shell command in a sandbox and return its exit code, stdout and stderr. It runs as root via " +
			"sh -lc, so pipes, && and redirects work; each call is a new shell (cd and exports don't carry over: use cwd " +
			"or chain with &&). It waits up to timeout seconds (default 60, max 120): start longer jobs in the background " +
			"(nohup cmd > /tmp/job.log 2>&1 &) and poll the log with later calls. Output is capped at 256 KB per stream.",
		InputSchema: schema([]string{"id", "command"}, map[string]any{
			"id":      idProp,
			"command": prop("string", "The shell command line, e.g. \"apt-get update && apt-get install -y curl\"."),
			"cwd":     prop("string", "Working directory (absolute path)."),
			"timeout": map[string]any{"type": "integer", "minimum": 1, "maximum": 120, "description": "Seconds to wait (default 60)."},
		}),
		needsID: true,
		run: func(ctx context.Context, c api.API, id string, x mcpArgs) (string, error) {
			if x.Timeout < 0 || x.Timeout > 120 {
				return "", errors.New("timeout must be 1 to 120 seconds; run longer jobs in the background with nohup")
			}
			res, err := c.SandboxExec(ctx, id, api.SandboxExecRequest{Cmd: x.Command, Cwd: x.Cwd, Timeout: int(x.Timeout)})
			if err != nil {
				return "", err
			}
			return formatExec(res), nil
		},
	},
	{
		Name:        "sandbox_read_file",
		Description: "Read a file from a sandbox (up to 5 MB). Text comes back as is; binary files as base64. For a large log, read part of it with sandbox_exec (tail -n 200 /path) instead.",
		InputSchema: schema([]string{"id", "path"}, map[string]any{
			"id":   idProp,
			"path": prop("string", "Absolute path of the file."),
		}),
		needsID: true,
		run: func(ctx context.Context, c api.API, id string, x mcpArgs) (string, error) {
			b, truncated, err := c.SandboxReadFile(ctx, id, x.Path)
			if err != nil {
				return "", err
			}
			note := ""
			if truncated {
				note = "\n[truncated at 5 MB]"
			}
			if utf8.Valid(b) {
				return string(b) + note, nil
			}
			return "binary file (" + strconv.Itoa(len(b)) + " bytes), base64:\n" + base64.StdEncoding.EncodeToString(b) + note, nil
		},
	},
	{
		Name:        "sandbox_write_file",
		Description: "Write a text file in a sandbox (up to 5 MB), creating parent directories and replacing the file if it exists (mode 0644; chmod with sandbox_exec). Good for scripts and config files.",
		InputSchema: schema([]string{"id", "path", "content"}, map[string]any{
			"id":      idProp,
			"path":    prop("string", "Absolute path of the file."),
			"content": prop("string", "The file's full content."),
		}),
		needsID: true,
		run: func(ctx context.Context, c api.API, id string, x mcpArgs) (string, error) {
			n, err := c.SandboxWriteFile(ctx, id, x.Path, "", []byte(*x.Content))
			if err != nil {
				return "", err
			}
			return compactJSON(map[string]any{"path": x.Path, "size": n})
		},
	},
	{
		Name:        "sandbox_list_files",
		Description: "List a directory in a sandbox as JSON entries (name, type file/dir/link/other, size, mode, mtime; at most 1000).",
		InputSchema: schema([]string{"id", "path"}, map[string]any{
			"id":   idProp,
			"path": prop("string", "Absolute path of the directory."),
		}),
		needsID: true,
		run: func(ctx context.Context, c api.API, id string, x mcpArgs) (string, error) {
			raw, err := c.SandboxListFiles(ctx, id, x.Path)
			if err != nil {
				return "", err
			}
			var b bytes.Buffer
			if json.Compact(&b, raw) != nil {
				return string(raw), nil
			}
			return b.String(), nil
		},
	},
	{
		Name:        "sandbox_extend",
		Description: "Add time to a sandbox's limit (the organization caps the total). Returns the sandbox with its new expires_at.",
		InputSchema: schema([]string{"id", "by"}, map[string]any{
			"id": idProp,
			"by": durationProp("Time to add: \"30m\", \"2h\" or seconds."),
		}),
		needsID: true,
		run: func(ctx context.Context, c api.API, id string, x mcpArgs) (string, error) {
			s, err := c.ExtendSandbox(ctx, id, int64(x.By))
			if err != nil {
				return "", err
			}
			return compactJSON(s)
		},
	},
	{
		Name:        "sandbox_kill",
		Description: "Kill a sandbox: its processes and files are gone for good. Copy out anything you need first (sandbox_read_file). Do this when you are done.",
		InputSchema: schema([]string{"id"}, map[string]any{"id": idProp}),
		needsID:     true,
		run: func(ctx context.Context, c api.API, id string, x mcpArgs) (string, error) {
			if err := c.KillSandbox(ctx, id); err != nil {
				return "", err
			}
			return "killed " + x.ID, nil
		},
	},
	{
		Name: "sandbox_snapshot",
		Description: "Snapshot a running sandbox: its disk, memory and running processes are frozen as they are now, and the sandbox " +
			"keeps running. Take one before a risky step (an install, a migration, a rewrite); if it goes wrong, sandbox_fork the " +
			"snapshot to get a copy back from that moment. Pass a name to reuse the snapshot as a template (sandbox_create " +
			"from_snapshot). Returns the snapshot as JSON; snapshots stay until deleted and count against a storage quota.",
		InputSchema: schema([]string{"id"}, map[string]any{
			"id":   idProp,
			"name": prop("string", "A name for the snapshot (default: generated). Unique in the organization."),
		}),
		needsID: true,
		run: func(ctx context.Context, c api.API, id string, x mcpArgs) (string, error) {
			s, err := c.SnapshotSandbox(ctx, id, api.SnapshotRequest{Name: x.Name}, true)
			if err != nil {
				return "", err
			}
			if s.Status == "failed" {
				return "", fmt.Errorf("snapshot %s failed", s.Name)
			}
			return compactJSON(s)
		},
	},
	{
		Name: "sandbox_fork",
		Description: "Create count sandboxes (default 1, at most 10) from a snapshot, each continuing from the exact moment it was " +
			"taken, each with its own copy of the disk and memory. Use it to try several approaches in parallel: fork, run one " +
			"approach per fork, compare the results, and sandbox_kill the forks you don't keep. Forks count against the running-sandbox " +
			"cap and the memory and disk quotas. Returns the forks as JSON.",
		InputSchema: schema([]string{"snapshot"}, map[string]any{
			"snapshot": prop("string", "The snapshot's name or id (from sandbox_snapshot or sandbox_list_snapshots)."),
			"count":    prop("integer", "How many forks (1-10, default 1)."),
			"ttl":      durationProp("Time limit for each fork: \"30m\", \"2h\" or seconds (default 1h)."),
		}),
		run: func(ctx context.Context, c api.API, _ string, x mcpArgs) (string, error) {
			list, err := c.ForkSnapshot(ctx, x.Snapshot, api.ForkRequest{Count: x.Count, TTL: int64(x.TTL)}, true)
			if err != nil {
				return "", err
			}
			return compactJSON(list)
		},
	},
	{
		Name:        "sandbox_pause",
		Description: "Pause a running sandbox: it uses no CPU while paused, but its memory stays allocated and its time limit keeps running. Any exec or file call resumes it automatically; sandbox_resume does it explicitly.",
		InputSchema: schema([]string{"id"}, map[string]any{"id": idProp}),
		needsID:     true,
		run: func(ctx context.Context, c api.API, id string, _ mcpArgs) (string, error) {
			s, err := c.PauseSandbox(ctx, id)
			if err != nil {
				return "", err
			}
			return compactJSON(s)
		},
	},
	{
		Name:        "sandbox_resume",
		Description: "Resume a paused sandbox. Returns the sandbox with its status.",
		InputSchema: schema([]string{"id"}, map[string]any{"id": idProp}),
		needsID:     true,
		run: func(ctx context.Context, c api.API, id string, _ mcpArgs) (string, error) {
			s, err := c.ResumeSandbox(ctx, id)
			if err != nil {
				return "", err
			}
			return compactJSON(s)
		},
	},
	{
		Name:        "sandbox_list_snapshots",
		Description: "List the organization's snapshots as JSON (id, name, status, source sandbox, server, image, size_bytes). Check it for a snapshot to fork or create from before taking a new one.",
		InputSchema: schema(nil, map[string]any{}),
		run: func(ctx context.Context, c api.API, _ string, _ mcpArgs) (string, error) {
			list, err := c.Snapshots(ctx, "")
			if err != nil {
				return "", err
			}
			return compactJSON(list)
		},
	},
}
