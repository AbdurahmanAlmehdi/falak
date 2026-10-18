/**
 * Side-effect entry point:
 *
 *   node --import @othmanhaba/apm-node/register server.js
 *   // or first line of your entry file:
 *   import '@othmanhaba/apm-node/register';
 *
 * Configured entirely from FALAK_* / OTEL_* environment variables.
 */
import { runtime } from './env.js';
import { start } from './sdk.js';

if (runtime() === 'node') {
  // ESM auto-instrumentation (pg, mysql2, ioredis, express, fastify imported via `import`)
  // needs the import-in-the-middle loader hook, registered before the app's modules load.
  try {
    const mod = await import('node:module');
    if (typeof mod.register === 'function') {
      mod.register('@opentelemetry/instrumentation/hook.mjs', import.meta.url);
    }
  } catch {
    // Older Node or restricted environment: CJS + builtins are still instrumented.
  }
}

start();
