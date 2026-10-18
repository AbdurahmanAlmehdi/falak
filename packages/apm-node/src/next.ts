/**
 * Next.js helper. In `instrumentation.ts` at the project root:
 *
 *   export async function register() {
 *     const { registerFalak } = await import('@othmanhaba/apm-node/next');
 *     await registerFalak();
 *   }
 *
 * Only the Node.js runtime is instrumented; the Edge runtime is skipped. Next.js emits its own
 * OpenTelemetry spans (route handlers, rendering, fetch) which Falak maps and exports.
 */
import type { FalakOptions } from './config.js';
import { env } from './env.js';
import type { FalakHandle } from './sdk.js';

export async function registerFalak(options: FalakOptions = {}): Promise<FalakHandle | undefined> {
  if (env().NEXT_RUNTIME !== 'nodejs') return undefined;

  const { start } = await import('./sdk.js');

  // Next bundles its own http server; keep auto-instrumentation for outgoing calls and databases.
  return start({ serviceName: env().FALAK_SERVICE_NAME ?? env().npm_package_name, ...options });
}

export { withFalakRequest, setUser } from './fetch.js';
export { recordException } from './exceptions.js';
