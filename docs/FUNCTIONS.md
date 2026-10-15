# Cloud Functions

A **Function** is a service whose code you write in Falak itself: no repository, no Dockerfile. Deploying takes a few
seconds, the function gets a URL like any other service, and it **scales to zero** when nobody calls it. On
traffic, it starts again and scales out.

A function runs on **Bun, Node.js, Deno, Python or Go**, and can serve HTTP, run on schedules, or both.

## Create one

On the canvas: **Create → Function**.

1. Pick a **runtime**: Bun, Node.js, Deno, Python or Go.
2. Pick a **starter**. Each one is ready to use, and the TypeScript ones are shared by Bun, Node and Deno.

   | Category | Starter |
   |---|---|
   | Basics | Hello API |
   | Data | JSON API + Postgres (notes CRUD) |
   | Webhooks | Signed webhook receiver (HMAC, GitHub style) · Stripe webhooks (signature check, checkout/invoice/subscription events) |
   | Bots | Telegram bot (commands, `/setup` registers the webhook) |
   | Notifications | Slack / Discord notifier (token-protected relay) |
   | Scheduled | Scheduled job (hourly) · Uptime monitor (every 5 minutes, alerts Slack/Discord, `GET /status`) |
   | APIs | Caching API proxy (hides the upstream key, CORS, GET cache) |
   | Forms | Contact form → email (Resend, spam honeypot) |

   A starter creates the **variables** it reads. Secrets it needs, such as webhook secrets and tokens, are
   generated; fill in the rest in Variables. Scheduled starters also create their **schedule**.
3. Pick a **server** and a **domain**, then **Create function**. The starter becomes version 1 of a draft: its panel
   opens on the Code tab so you can change the code, variables, schedules and settings first. Nothing runs (no
   deployment, no gateway release, no schedules) until you press **Deploy**; it is live a few seconds later.

The server needs Docker and a Falak agent 0.4 or newer (it runs the *function gateway*).

### TypeScript (Bun, Node.js, Deno)

```ts
import { Hono } from 'hono'
import postgres from 'postgres'

const sql = postgres(process.env.DATABASE_URL!)
const app = new Hono()

app.get('/', (c) => c.json({ hello: 'world' }))
app.get('/users/:id', async (c) => c.json(await sql`select * from users where id = ${c.req.param('id')}`))

export default app                          // a Hono app, { fetch }, or a fetch(request) function
export async function scheduled(event) { }  // optional: runs on the function's schedules
```

- The entry file is `index.ts`. Packages you import are installed when you deploy, and their versions are pinned
  per code version:

  | Runtime | Installed with |
  |---|---|
  | Bun | `bun install` |
  | Node.js 24 | `npm`; Node runs TypeScript natively, so use erasable syntax only (no `enum`, no `namespace`) |
  | Deno 2 | `npm:` packages; it runs with network, env and read access to `/app` only |

- Write against Hono, the Web APIs (`fetch`, `crypto.subtle`, `Request`, `Response`) and npm packages that run
  everywhere, and the same file works on all three runtimes.

### Python

```python
# /// script
# dependencies = ["fastapi", "httpx"]
# ///
from fastapi import FastAPI

app = FastAPI()                 # any ASGI app: FastAPI, Starlette, …

@app.get("/hello/{name}")
def hello(name: str):
    return {"message": f"Hello, {name}!"}

async def scheduled(event):     # optional: runs on the function's schedules (def or async def)
    ...
```

- The entry file is `main.py`, served by uvicorn.
- Dependencies come from `# /// script` blocks (PEP 723) or a `requirements.txt`. With several files, each `.py`
  file can have its own block; they are merged. They are installed with `uv` when you deploy and locked per code
  version.

### Go

```go
package main

import (
	"context"
	"net/http"
)

// Handler serves HTTP: an http.Handler (a ServeMux, a router) or a func(http.ResponseWriter, *http.Request).
var Handler = routes()

func routes() *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /hello/{name}", func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("Hello, " + r.PathValue("name") + "!"))
	})
	return mux
}

// Optional: runs on the function's schedules. An error (or a panic) marks the run failed.
func Scheduled(ctx context.Context, event Event) error { return nil }
```

- The entry file is `main.go`, in `package main`, **without** a `main()`: Falak adds it, with the server, the
  `Event` type (`Name`, `Schedule`, `Cron`, `Trigger`, `ScheduledTime`) and telemetry. Names starting with `falak`
  are reserved.
- Go 1.27. When you deploy, Falak runs `go mod tidy` and builds one static binary; modules you import are resolved
  then, and `go.mod` / `go.sum` are kept per code version. Without a `go.mod`, the module is called `function`, so a
  folder `lib/` is imported as `"function/lib"`. Add your own `go.mod` to choose versions.
- Observability names requests by the `ServeMux` pattern (`GET /hello/{name}`). Outgoing calls are recorded when
  they go through `http.DefaultClient` (or a client with `Transport: http.DefaultClient.Transport`) with the
  request's context: `http.NewRequestWithContext(r.Context(), …)`, or the `ctx` of `Scheduled`.

## Edit and deploy

The **Code** tab is a full editor, with TypeScript autocomplete for Hono and Bun.

- **Drafts:** your edits autosave as *your* draft; teammates don't see them until you deploy.
- **Deploy** (⌘S): saves the code as a new version with an optional message, and deploys it.
- **Deploy history:** the deploy appears in the Deployments tab like any other service's deploy.
- **When the new version fails:** if it doesn't install or start, the deploy fails and the previous version keeps
  serving.
- **If a teammate deployed while you were editing:** Falak shows their version next to yours, file by file. You can
  take theirs, keep editing, or deploy yours on top.

### Several files

A function can have several files in folders: the file list beside the editor adds (**+**), renames and deletes
them; the entry file stays. Import them with relative paths:

```ts
import { users } from './routes/users.ts'   // Node and Deno need the extension; Bun accepts both
```

```python
from lib.db import connect                  # main.py's folder is on the import path; folders need no __init__.py
```

- Changed files are marked **A** (added), **M** (modified) or **D** (deleted) against the newest version.
- A version holds all its files; rollback brings all of them back.
- Paths use letters, digits, `.`, `_`, `-` and `/`, up to 8 levels deep. No dot-files, and no `node_modules` or
  `__pycache__` (the server creates those).
- Functions with more than one file need Falak agent 0.4.4 or newer on the function's server.

## Versions and rollback

Each version is immutable and records its author, message and a short hash (the hash is the deployment's commit).
In the **Versions** tab you can:

- **Compare** any version against the live one, file by file; each version also lists what it changed.
- **Deploy this version** to roll back. The server keeps recent releases installed, so a rollback is instant.
- **Restore to editor** to start a new change from an old version.

## Schedules (cron)

A function can also run on a schedule. Export a `scheduled` handler:

```ts
export async function scheduled(event) {
    // event = { name, schedule, cron, trigger: 'cron' | 'manual', scheduledTime }
    await sql`delete from sessions where expires_at < now()`
}

export default app // the HTTP side is optional for scheduled-only functions
```

`export default { fetch: app.fetch, scheduled }` works too. In Python, define `def scheduled(event)` or
`async def scheduled(event)` in `main.py`; `event` is a dict with the same fields (`scheduled_time` instead of
`scheduledTime`). In Go, define `func Scheduled(ctx context.Context, event Event) error` (`ctx` is cancelled when
the run times out).

In the **Schedules** tab, add one or more schedules:

| Field | Options |
|---|---|
| When | a preset, a 5-field cron expression, `@hourly`…`@yearly`, or `@every 10m` |
| Timezone | any timezone |
| Timeout | the run is stopped after this many seconds |
| If still running | skip the next run (default), or run anyway |

Each run is a new container on the function's server, with the same release, variables, limits and isolation as
its HTTP instances. It doesn't touch the instances that serve traffic, so a function that sleeps stays asleep
between runs.

A run succeeds when the handler resolves, and fails when it throws.

**Run now** runs a schedule immediately and shows its output live.

**History:**
- Runs, failures, timeouts and missed runs appear under **Observability → Scheduled tasks**, like any scheduled
  job.
- Errors become **Issues**, with the stack trace.

The **Scheduled job** starter comes with an hourly schedule.

## Access control

**Settings → Access** restricts who can call a function. The gateway checks this before it wakes the function, so a
rejected request costs nothing.

- **API keys:** with at least one key, every request must send `Authorization: Bearer <key>` or
  `X-Falak-Key: <key>`.
  - Without a valid key, the caller gets `401`.
  - A key is shown once; Falak stores only its hash.
  - The key header never reaches your code. Use `X-Falak-Key` when your code reads `Authorization` for its own
    scheme.
- **IP allowlist:** IPs or CIDR ranges (IPv4 or IPv6). Anyone else gets `403`. Behind Cloudflare, the visitor's
  address is checked.

Schedules are not affected. Changes redeploy the live version in a few seconds.

## Paths on other sites

**Settings → Paths** serves the function on a path of another site, e.g. `shop.example.com/api/*`, next to that
site's own pages.

- **Strip the path:** optional; the function then sees `/users` for `/api/users`.
- **The site's rules apply:** its IP rules and basic auth still cover the path.
- **How it's routed:** when the function runs on the same server, the site's Caddy hands the path to the local
  gateway. Otherwise it proxies to the function's own domain over HTTPS.

## Test requests

The **Code** tab has a **Send a test request** panel: method, path, headers and body. The request goes through the
function's real URL, so TLS, cold start and access rules apply, and the panel shows the status, timing, headers and
body.

## CLI and API

`falak fn` (see the CLI section in the README) works on functions from your terminal or CI:

```bash
falak fn list
falak fn pull hooks ./hooks          # the code + .falak-function.json (your base version)
falak fn deploy hooks ./hooks -m "Handle refunds" --wait
falak fn versions hooks
falak fn rollback hooks 3 --wait
falak fn run hooks "Nightly cleanup" # streams the run; exits with its code
falak fn invoke hooks /status -H 'X-Falak-Key: kfn_…'
falak fn logs hooks --follow
```

`falak fn deploy` sends the **whole directory** as the function's files, so files you add are deployed and files you
delete are removed from the new version.

- It leaves out dot-files and dot-folders (`.git`, `.env`, `.falak-function.json`), `node_modules`, `__pycache__`,
  `.venv` and `venv`, and whatever a `.falakignore` lists (one name or glob per line, e.g. `dist` or `*.log`).
- It skips, with a note: files that look like secrets (`id_rsa`, `*.pem`, `*.key`, `*.p12`, `credentials*.json`,
  `service-account*.json`, `*.tfvars`, `*.tfstate`, `secrets.yml`…; rename one that really is code), symlinks,
  names Falak doesn't accept, and binary files. An entrypoint it can't send is an error.
- Files the function doesn't have yet are listed and need a yes: an interactive prompt, or `--yes` (required in
  scripts and CI).

`falak fn pull` writes every file of the newest version. Files an earlier pull or deploy wrote that the version no
longer has are removed, unless you changed them locally (they are kept, with a warning).

If someone deployed after your `pull`, `falak fn deploy` stops with exit code 4 (pull, or `--force`). The same
operations are in the API (`/api/v1/functions…`, see `docs/API.md`).

## Variables and databases

Functions use the **Variables** tab like every service, including references such as
`DATABASE_URL=${{ postgres.DATABASE_URL }}`. Bun's built-in `sql` client reads `DATABASE_URL`. Falak sets `PORT`
itself, so don't define it.

## Scaling

**Settings → Scaling**:

| Setting | Default | What it does |
|---|---|---|
| Min instances | 0 | 0 = scale to zero; 1+ keeps instances warm (no cold starts) |
| Max instances | 5 | Upper bound under load |
| Concurrency | 50 | Requests per instance before another one starts |
| Idle timeout | 300 s | An instance with no requests this long is stopped |
| Memory / CPU | 256 MB / 0.5 | Per instance |
| Request timeout | 30 s | Longer requests get a 504 |

**How it works:** Caddy sends the function's traffic to `falak-fn-gateway` on the server.

- **Nothing running:** the gateway holds the first request, starts an instance (its container already exists, so this
  is a `docker start`) and forwards the request when the runtime is ready. A Bun cold start takes about 0.2 s.
- **Busy:** when every instance is at its concurrency limit, the gateway starts another one, up to max instances.
- **Idle:** instances are stopped after the idle timeout, down to min instances.

The gateway runs as its own systemd service, so agent upgrades don't interrupt function traffic.

## Observability

Functions report to Falak without any package.

**Observability tab** (every runtime)
- Requests, error rate and p95, with the slow routes listed by their route (`GET /users/:id` in Hono,
  `GET /hello/{name}` in FastAPI and Go's `ServeMux`). Paths no route
  matches are grouped as `(unmatched)`.
- Issues from uncaught errors (panics in Go), with the stack trace.
- Outgoing calls: `fetch` in TypeScript, `httpx` and `requests` in Python, `net/http` with the request's context in
  Go. URLs are recorded without query strings or user:password, and path segments that look like secrets are
  replaced by `{redacted}` (Telegram's `/bot<token>/`, long or mixed-case tokens, `<id>:<secret>`). The gateway
  applies the same rules to every span it relays, error messages included.
- Cold starts are marked on the request that waited for one (`faas.coldstart`).
- Requests the gateway answers itself are listed as `(function unavailable)`: a release that fails to start, a start
  timeout, or a full queue.

**Code tab:** the live state, *Sleeping* or *N running*, with requests in flight, request and cold-start counts, and
the time of the last request.

**Logs tab:** `console.log` / `print` / `log.Println` output.

**How it works:** each function reports on its own socket. The gateway stamps the function's identity on what
arrives there, so a function can't report as another one, and hands it to the agent.

Set `FALAK_TELEMETRY=off` in Variables to turn the built-in tracing off.

## Isolation

Every instance runs with:

- a non-root user and a read-only root filesystem, with the code mounted read-only
- a small temporary `/tmp`
- no Linux capabilities and `no-new-privileges`
- memory, CPU and process limits
- no Docker socket

Instances live on their own Docker network (`falak-fn`). They reach the internet and your databases' private
addresses.

## Limits

- 1 MB of code per version, 50 files, 8 folder levels. Source files only (UTF-8 text).
- A function runs on one server; multi-server functions come with load balancing.
- Health checks don't apply: a health probe would keep the function awake.
