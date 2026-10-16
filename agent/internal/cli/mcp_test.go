package cli

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/cli/api"
)

type rpcReply struct {
	ID     json.RawMessage `json:"id"`
	Result json.RawMessage `json:"result"`
	Error  *rpcError       `json:"error"`
}

// mcpSession sends the lines to `falak mcp` and returns the replies (one per stdout line).
func mcpSession(t *testing.T, h *harness, lines ...string) []rpcReply {
	t.Helper()
	if code := h.runStdin(strings.Join(lines, "\n")+"\n", "mcp"); code != 0 {
		t.Fatalf("mcp exit %d: %s", code, h.err.String())
	}
	var out []rpcReply
	for _, l := range strings.Split(strings.TrimSpace(h.out.String()), "\n") {
		if l == "" {
			continue
		}
		var r rpcReply
		if err := json.Unmarshal([]byte(l), &r); err != nil {
			t.Fatalf("stdout line isn't JSON-RPC: %q", l)
		}
		out = append(out, r)
	}
	return out
}

type toolResult struct {
	Content []struct {
		Type string `json:"type"`
		Text string `json:"text"`
	} `json:"content"`
	IsError bool `json:"isError"`
}

func callTool(t *testing.T, h *harness, name string, args any) toolResult {
	t.Helper()
	b, _ := json.Marshal(map[string]any{"jsonrpc": "2.0", "id": 7, "method": "tools/call", "params": map[string]any{"name": name, "arguments": args}})
	replies := mcpSession(t, h, string(b))
	if len(replies) != 1 || replies[0].Error != nil {
		t.Fatalf("%s: %+v", name, replies)
	}
	var r toolResult
	if err := json.Unmarshal(replies[0].Result, &r); err != nil || len(r.Content) != 1 || r.Content[0].Type != "text" {
		t.Fatalf("%s: result %s", name, replies[0].Result)
	}
	return r
}

func TestMCPHandshake(t *testing.T) {
	h := newHarness(t, nil) // not logged in: the handshake and the tool list still work
	replies := mcpSession(t, h,
		`{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}`,
		`{"jsonrpc":"2.0","method":"notifications/initialized"}`,
		`{"jsonrpc":"2.0","id":"two","method":"ping"}`,
		`{"jsonrpc":"2.0","id":3,"method":"tools/list"}`,
		`{"jsonrpc":"2.0","id":4,"method":"resources/list"}`,
		`not json`,
		`{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"nope","arguments":{}}}`,
		`{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"sandbox_list","arguments":{}}}`,
	)
	if len(replies) != 7 { // the notification gets none
		t.Fatalf("%d replies: %s", len(replies), h.out.String())
	}
	var init struct {
		ProtocolVersion string                     `json:"protocolVersion"`
		Capabilities    map[string]json.RawMessage `json:"capabilities"`
		ServerInfo      struct{ Name, Version string }
	}
	if err := json.Unmarshal(replies[0].Result, &init); err != nil || init.ProtocolVersion != "2025-06-18" || init.ServerInfo.Name != "falak" || init.ServerInfo.Version != "test" || string(init.Capabilities["tools"]) != "{}" {
		t.Fatalf("initialize: %s", replies[0].Result)
	}
	if string(replies[1].ID) != `"two"` || string(replies[1].Result) != "{}" {
		t.Fatalf("ping: %+v", replies[1])
	}

	var list struct {
		Tools []struct {
			Name        string         `json:"name"`
			Description string         `json:"description"`
			InputSchema map[string]any `json:"inputSchema"`
		} `json:"tools"`
	}
	if err := json.Unmarshal(replies[2].Result, &list); err != nil {
		t.Fatal(err)
	}
	var names []string
	for _, tool := range list.Tools {
		names = append(names, tool.Name)
		props, _ := tool.InputSchema["properties"].(map[string]any)
		if tool.InputSchema["type"] != "object" || props == nil || len(tool.Description) < 40 {
			t.Errorf("%s: schema %v", tool.Name, tool.InputSchema)
		}
		req, _ := tool.InputSchema["required"].([]any)
		for _, r := range req {
			p, _ := props[r.(string)].(map[string]any)
			if p == nil || p["description"] == "" {
				t.Errorf("%s: required %v isn't a described property", tool.Name, r)
			}
		}
	}
	if got := strings.Join(names, ","); got != "sandbox_create,sandbox_list,sandbox_exec,sandbox_read_file,sandbox_write_file,sandbox_list_files,sandbox_extend,sandbox_kill" {
		t.Fatalf("tools %s", got)
	}

	if replies[3].Error == nil || replies[3].Error.Code != -32601 || string(replies[3].ID) != "4" {
		t.Fatalf("unknown method: %+v", replies[3])
	}
	if replies[4].Error == nil || replies[4].Error.Code != -32700 || string(replies[4].ID) != "null" {
		t.Fatalf("parse error: %+v", replies[4])
	}
	if replies[5].Error == nil || replies[5].Error.Code != -32602 {
		t.Fatalf("unknown tool: %+v", replies[5])
	}
	var r toolResult
	_ = json.Unmarshal(replies[6].Result, &r)
	if !r.IsError || !strings.Contains(r.Content[0].Text, "falak login") {
		t.Fatalf("no login: %s", replies[6].Result)
	}
	if h.err.Len() != 0 {
		t.Fatalf("stderr %q", h.err.String())
	}
}

func TestMCPNotificationOnly(t *testing.T) {
	h := newHarness(t, nil)
	if code := h.runStdin(`{"jsonrpc":"2.0","method":"notifications/initialized"}`+"\n", "mcp"); code != 0 || h.out.Len() != 0 {
		t.Fatalf("exit %d, stdout %q", code, h.out.String())
	}
}

func TestMCPTools(t *testing.T) {
	h, f := sbxHarness(t)

	r := callTool(t, h, "sandbox_exec", map[string]any{"id": "brave-otter", "command": "ls / && false", "cwd": "/root", "timeout": 30})
	if r.IsError || r.Content[0].Text != "exit code: 3 (12 ms)\nstdout:\nout\nstderr:\nerr\n" {
		t.Fatalf("exec: %+v", r)
	}
	var body map[string]any
	f.body("POST "+api.Path(api.PathSandboxExec, "sandbox", sbxID), &body)
	if body["cmd"] != "ls / && false" || body["cwd"] != "/root" || body["timeout"] != 30.0 || len(body) != 3 {
		t.Fatalf("exec body %v", body)
	}

	if r := callTool(t, h, "sandbox_read_file", map[string]any{"id": sbxID, "path": "/etc/hostname"}); r.IsError || r.Content[0].Text != "brave-otter\n" {
		t.Fatalf("read: %+v", r)
	}
	if r := callTool(t, h, "sandbox_read_file", map[string]any{"id": sbxID, "path": "/var/big.log"}); r.IsError || r.Content[0].Text != "first 5 MB\n[truncated at 5 MB]" {
		t.Fatalf("read truncated: %+v", r)
	}
	if r := callTool(t, h, "sandbox_read_file", map[string]any{"id": sbxID, "path": "/bin/blob"}); !strings.HasSuffix(r.Content[0].Text, "base64:\n/wD+") {
		t.Fatalf("read binary: %+v", r)
	}
	if r := callTool(t, h, "sandbox_write_file", map[string]any{"id": sbxID, "path": "/tmp/a.sh", "content": "echo hi\n"}); r.IsError || r.Content[0].Text != `{"path":"/tmp/a.sh","size":8}` || string(f.files["/tmp/a.sh"]) != "echo hi\n" {
		t.Fatalf("write: %+v", r)
	}
	if r := callTool(t, h, "sandbox_list_files", map[string]any{"id": sbxID, "path": "/etc"}); r.IsError || r.Content[0].Text != `{"entries":[{"name":"hostname","size":12,"type":"file"}]}` {
		t.Fatalf("list files: %+v", r)
	}
	if r := callTool(t, h, "sandbox_create", map[string]any{"image": "python:3.13", "ttl": "30m", "repo_url": "https://github.com/acme/shop@main"}); r.IsError || !strings.Contains(r.Content[0].Text, `"id":"`+sbxID+`"`) {
		t.Fatalf("create: %+v", r)
	}
	body = nil
	f.body("POST "+api.PathSandboxes, &body)
	if got, _ := json.Marshal(body); string(got) != `{"image":"python:3.13","repo":{"ref":"main","url":"https://github.com/acme/shop"},"ttl":1800}` {
		t.Fatalf("create body %s", got)
	}
	if r := callTool(t, h, "sandbox_extend", map[string]any{"id": sbxID, "by": 900}); r.IsError || !strings.Contains(r.Content[0].Text, `"name":"brave-otter"`) {
		t.Fatalf("extend: %+v", r)
	}
	if r := callTool(t, h, "sandbox_list", map[string]any{}); r.IsError || !strings.HasPrefix(r.Content[0].Text, `[{"id":"`+sbxKilledID) {
		t.Fatalf("list: %+v", r)
	}
	if r := callTool(t, h, "sandbox_kill", map[string]any{"id": sbxID}); r.IsError || r.Content[0].Text != "killed "+sbxID {
		t.Fatalf("kill: %+v", r)
	}

	// Errors are tool results with isError, not protocol errors.
	if r := callTool(t, h, "sandbox_read_file", map[string]any{"id": sbxID, "path": "/missing"}); !r.IsError || !strings.Contains(r.Content[0].Text, "File not found.") {
		t.Fatalf("api error: %+v", r)
	}
	if r := callTool(t, h, "sandbox_exec", map[string]any{"id": sbxID}); !r.IsError || r.Content[0].Text != "command is required" {
		t.Fatalf("missing arg: %+v", r)
	}
	if r := callTool(t, h, "sandbox_exec", map[string]any{"id": "ghost", "command": "true"}); !r.IsError || !strings.Contains(r.Content[0].Text, `"ghost" not found`) {
		t.Fatalf("unknown sandbox: %+v", r)
	}
	if r := callTool(t, h, "sandbox_extend", map[string]any{"id": sbxID, "by": "soon"}); !r.IsError {
		t.Fatalf("bad duration: %+v", r)
	}
}
