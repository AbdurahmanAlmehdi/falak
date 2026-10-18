# @othmanhaba/apm-node

Falak APM for **Node.js 20+, Bun and Deno**. It is a thin preset over the official OpenTelemetry JS SDK. It exports traces, logs and metrics as OTLP/HTTP JSON to the local `falak-agent` (`http://127.0.0.1:4318`). Spans are normalised to the [Falak telemetry contract](../../contracts/telemetry/README.md) (`falak.event.type` = `request` / `outgoing_request` / `query` / `cache`).

## Install

```bash
npm i @othmanhaba/apm-node      # or: bun add @othmanhaba/apm-node / pnpm add @othmanhaba/apm-node
```

## Node.js

Load it before your app so that auto-instrumentation can patch modules as they are imported:

```bash
node --import @othmanhaba/apm-node/register server.js
# or: NODE_OPTIONS="--import @othmanhaba/apm-node/register"
```

You can also put `import '@othmanhaba/apm-node/register';` on the first line of the entry file. That covers CommonJS modules and Node built-ins. Use `--import` when the app loads `pg`/`express`/… through ESM `import`.

Auto-instrumented: `http`/`https` (incoming and outgoing), `undici`/global `fetch`, `pg`, `mysql2`, `ioredis`, `express` and `fastify`. Each instrumentation turns on only when its module is loaded.

Programmatic setup:

```ts
import { start, shutdown } from '@othmanhaba/apm-node';

start({ serviceName: 'shop', sampleRate: 0.5, redactKeys: ['password', 'token', 'ssn'] });
process.on('SIGTERM', () => shutdown().finally(() => process.exit(0)));
```

## Next.js

`instrumentation.ts` in the project root (see `examples/next-instrumentation.ts`):

```ts
export async function register() {
  const { registerFalak } = await import('@othmanhaba/apm-node/next');
  await registerFalak();
}
```

Only the Node.js runtime is instrumented. Next's own spans are exported and mapped too: the route comes from `next.route`.

## Nuxt / Nitro

```ts
// server/plugins/falak.ts
import falak from '@othmanhaba/apm-node/nitro';
export default defineNitroPlugin(falak);
```

The plugin starts the SDK and records errors from Nitro's `error` hook as unhandled. It also sets `http.route` from the matched route. On presets without Node http instrumentation (Bun, Deno), it opens a `request` span per request.

## Bun / Deno / fetch-style handlers

Node's auto-instrumentation does not hook `Bun.serve` or `Deno.serve`. On those runtimes `start()` uses a lightweight tracer provider with the same exporters, and you wrap your handler:

```ts
import { start, withFalakRequest, setUser, recordException } from '@othmanhaba/apm-node';

start();

Bun.serve({
  fetch: withFalakRequest(handler, {
    route: (req) => '/users/:id',          // low-cardinality route template
    user: (req) => session(req)?.userId,   // enduser.id
    captureHeaders: ['x-request-id'],
  }),
});
```

The wrapper continues an incoming `traceparent`. It records thrown errors as `falak.exception.handled=false` with an ERROR status and rethrows them. Responses with status 5xx also mark the span ERROR. The same wrapper works for Hono (`withFalakRequest(app.fetch)`), Deno and Next route handlers. See `examples/`.

## Exceptions

```ts
import { recordException } from '@othmanhaba/apm-node';

try { await charge(); } catch (e) { recordException(e); /* handled=true, span status untouched */ }
recordException(err, { handled: false }); // span → ERROR
```

- Every `exception` span event carries `falak.exception.handled`. For exceptions recorded by instrumentations, the flag comes from the span status: an ERROR status means the exception escaped.
- Uncaught exceptions are captured through `uncaughtExceptionMonitor`, and unhandled rejections are captured too. Both are recorded as `handled=false`. The process still crashes as it would by default. Set `captureProcessErrors: false` to opt out.

## Configuration

| Option | Env | Default |
|---|---|---|
| `enabled` | `FALAK_APM_ENABLED` | `true` |
| `endpoint` | `FALAK_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://127.0.0.1:4318` |
| `serviceName` | `FALAK_SERVICE_NAME`, `OTEL_SERVICE_NAME` | `npm_package_name` (the agent overrides it with the site slug) |
| `sampleRate` (root, parent-based) | `FALAK_SAMPLE_RATE` | `1` |
| `autoInstrument` | `FALAK_APM_AUTO_INSTRUMENT` | `true` (Node only) |
| `logs` | `FALAK_APM_LOGS` | `true` |
| `metrics` | `FALAK_APM_METRICS` | `true` (Node only, 60 s interval) |
| `redactKeys` | | `password, token, secret, authorization, cookie, api_key` |
| `redactQueryLiterals` | | `true` |
| `redact(attributes, eventType)` | | none |
| `captureRequestHeaders` | | `['user-agent']` |
| `captureProcessErrors` | | `true` |

Resource attributes are read from `FALAK_SITE_ID`, `FALAK_SERVER_ID`, `FALAK_DEPLOYMENT_ID`, `FALAK_RELEASE_ID`, `FALAK_ORG_ID` and `FALAK_ENVIRONMENT` (falling back to `NODE_ENV`). Deployments inject them, and the agent also sets them authoritatively.

## Mapping and redaction

The Falak span processor runs before export and does the following:

| Span | `falak.event.type` | Normalised attributes |
|---|---|---|
| SERVER + HTTP | `request` | `http.request.method`, `http.route`, `http.response.status_code`, `url.path` (from old `http.*` names when needed); 5xx → ERROR |
| CLIENT + HTTP | `outgoing_request` | `http.request.method`, `url.full`, `http.response.status_code` |
| `db.system` = sql | `query` | `db.system.name`, `db.query.text`, `db.namespace`, `falak.query.connection` (`host:port`) |
| ioredis key/value commands | `cache` | `falak.cache.op` (`hit`/`miss` from the reply, `write`, `forget`), `falak.cache.key`, `falak.cache.store` |

Redaction then applies:

- Attribute keys that contain a denylisted word (including captured headers) become `[redacted]`.
- The same goes for denylisted query parameters in `url.full`, `url.query` and `http.target`, and for a password in the URL userinfo.
- Quoted SQL literals become `?`.
- Cache keys that contain a denylisted word are redacted.
- Log record attributes are filtered with the same denylist.
- Your `redact` callback runs last. It can change the attributes in place or return a replacement map. A callback that throws is ignored.

## Development

```bash
bun install
bun run typecheck && bun run build   # tsc → dist/*.js + .d.ts
bun test                             # includes a real `node --import dist/register.js` roundtrip
```
