# The Falak skill for AI agents

`skills/falak` is an [Agent Skill](https://docs.anthropic.com/en/docs/agents-and-tools/agent-skills/overview): a
`SKILL.md` with instructions plus reference files that teach an AI coding agent (Claude Code, Codex, Cursor and other
harnesses that read `SKILL.md`) to operate Falak through the `falak` CLI, `falak.yaml` and the `/api/v1` API: deploys
and rollbacks, config as code, secrets, functions, database containers, templates, logs, consoles, network tools and
sandboxes, with the rules for what needs a human's confirmation.

```
skills/falak/
├── SKILL.md                 # when to use it, ground rules, core workflows (loaded when the skill triggers)
└── references/
    ├── cli.md               # every falak command, flag, default and exit code
    ├── config.md            # the falak.yaml schema (v1), condensed
    ├── api.md               # tokens, abilities, the main /api/v1 endpoints
    ├── workflows.md         # recipes: repo, template, databases, functions, secrets, domains, logs, consoles, net, sandboxes
    ├── safety.md            # destructive actions, secret handling, least-privilege tokens
    └── llms.txt             # a short index for the website's /llms.txt
```

The agent reads `SKILL.md` when a task mentions Falak and opens a reference file only when it needs it.

## Install

### With the CLI

A `falak` CLI that has the `skill` command (`falak help` lists `skill install`) writes the copy it ships with:

```bash
falak skill install                 # ~/.claude/skills/falak (every project, your user)
falak skill install --project       # .claude/skills/falak in the current directory (commit it for your team)
falak skill install --dir ~/.codex/skills/falak   # any other agent's skills folder
```

It refuses to overwrite existing files; `--force` replaces them (run it again after `falak update` to refresh the
skill). `--json` prints the directory and the files written.

### By hand

Copy the folder from the repository (or a release's source archive):

```bash
git clone --depth 1 https://github.com/falak-sh/falak /tmp/falak
cp -R /tmp/falak/skills/falak ~/.claude/skills/            # user-wide, Claude Code
cp -R /tmp/falak/skills/falak .claude/skills/              # one project, Claude Code
```

- **Claude Code** loads skills from `~/.claude/skills/<name>/SKILL.md` and `.claude/skills/<name>/SKILL.md`. Start a
  new session after installing.
- **Codex and other SKILL.md harnesses**: copy the same folder into the harness's skills directory (for Codex CLI,
  `~/.codex/skills/` at the time of writing — check your harness's docs), or use `falak skill install --dir`.
- **Tools without skill support** (Cursor rules, a system prompt): point them at the raw files, or paste `SKILL.md`
  and the reference you need:
  `https://raw.githubusercontent.com/falak-sh/falak/main/skills/falak/SKILL.md` and
  `…/skills/falak/references/<file>`. `references/llms.txt` is the index for tools that read `/llms.txt`.

## MCP server (sandboxes)

The CLI is also an MCP server: `falak mcp` speaks MCP on stdio and gives any MCP client (Claude Code, Codex, Cursor…)
tools to create, use and kill [sandboxes](SANDBOXES.md), disposable machines where an agent can run anything without
touching your servers. It uses the CLI's credentials (below).

```bash
claude mcp add falak -- falak mcp
claude mcp add falak -e FALAK_URL=https://falak.example.com -e FALAK_TOKEN=… -- falak mcp   # a dedicated token
```

Other clients: run the command `falak mcp`. Tools: `sandbox_create`, `sandbox_list`, `sandbox_exec`,
`sandbox_read_file`, `sandbox_write_file`, `sandbox_list_files`, `sandbox_extend`, `sandbox_kill`, `sandbox_snapshot`,
`sandbox_fork`, `sandbox_pause`, `sandbox_resume`, `sandbox_list_snapshots`.

## Authenticate the CLI for an agent

The skill drives the `falak` CLI, so the agent needs a working `falak` and credentials. Install the CLI
(`curl -fsSL https://falak.sh/install-cli.sh | sh`) and pick one:

**You sit next to the agent (interactive)**: log in yourself, once:

```bash
falak login --url https://falak.example.com
```

The browser sign-in (an RFC 8628 device flow: a code, an approval page with your password and 2FA) stores a token
named `CLI — <host>` with **your role's** permissions (`*` ability) in the CLI's credentials file (0600). It
expires 90 days after its last use (a year after the login at most). `falak logout` revokes it. Convenient, but the
agent then acts with everything your role allows.

**The agent runs unattended, or you want to limit it (recommended)**: create an API token for it in the panel,
**Settings → API tokens**, with only the abilities it needs and an expiry, and give it through the environment:

```bash
export FALAK_URL=https://falak.example.com
export FALAK_TOKEN=…            # from your secret manager; never commit it or paste it into the chat
falak whoami                    # checks the token: user, organization, role, token name and abilities
```

Or store it for the CLI: `printf '%s\n' "$FALAK_TOKEN" | falak login --url "$FALAK_URL" --token-stdin`
(`falak logout` then forgets it but doesn't revoke it: revoke pasted tokens in Settings → API tokens).
`FALAK_CONFIG_DIR` keeps an agent's credentials apart from yours.

### Least-privilege tokens

A token is pinned to one organization, and a request passes only when the token has the ability **and** the token
owner's role has the permission, so an agent can never do more than the person who created its token. You can't
grant abilities you don't hold. Suggested sets:

| The agent should | Abilities |
|---|---|
| Look around (read-only) | `sites.view`, `servers.view`, `deployments.view`, `telemetry.view`, `projects.view`, `databases.view`, `functions.view`, `config.view` |
| Deploy and roll back existing sites (CI) | `sites.view`, `deployments.view`, `deployments.create`, `deployments.rollback` |
| Work with falak.yaml | `projects.view`, `config.view`, `config.apply`, `secrets.view`, `secrets.manage` (+ `deployments.view`) |
| Deploy functions | `functions.view`, `functions.deploy`, `deployments.view`, `deployments.create`, `telemetry.view` |
| Edit a site's `.env` | `sites.view`, `sites.env.view`, `sites.env.manage` |
| Deploy templates | `projects.view`, `projects.manage`, `templates.view`, `sites.create`, `deployments.create` |
| Use sandboxes (CLI or `falak mcp`) | `sandboxes.view`, `sandboxes.use` |

Keep these for humans unless you decided otherwise: `*`, `config.approve` (approving risky changes),
`config.destroy` (deleting data from falak.yaml), `databases.manage` (deleting database containers),
`sites.delete`, `servers.delete`, `secrets.reveal`, `terminal.open` (consoles), `network.tools`, `network.capture`.
Without them the agent asks you instead, which is what the skill tells it to do anyway.

## What the skill tells the agent

- Use the CLI with `--json` and branch on exit codes (3 deployment failed, 4 function conflict, 5 invalid
  falak.yaml, 6 waiting for an approval, 7 drift).
- Never print tokens, `.env` contents or secret values; pass secrets on stdin; reference them in falak.yaml.
- Ask before destructive or irreversible actions: deletions, `--allow-destroy`, approving risky runs, `env push`,
  rotating or deleting secrets, production rollbacks, consoles and packet captures, restores and anything on the
  control-plane host (`falak-ctl`).
- `validate` → `plan` → `apply` for config as code; look before changing.

## Keeping it current

The skill documents the CLI and API of this repository, verified against the source. When a command, flag or
endpoint changes, update `skills/falak` and copy it to `agent/internal/cli/skill/falak` (the CLI embeds that copy;
`go test ./internal/cli` fails while they differ):

```bash
rm -rf agent/internal/cli/skill/falak && cp -R skills/falak agent/internal/cli/skill/falak
```
