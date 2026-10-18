/**
 * Nuxt / Nitro plugin.
 *
 *   // server/plugins/falak.ts
 *   import falak from '@othmanhaba/apm-node/nitro';
 *   export default defineNitroPlugin(falak);
 *
 * Starts the SDK (if not started via --import), opens a SERVER span per request when no
 * instrumentation already did (Bun / Deno / edge presets), and records errors that reach
 * Nitro's error hook as unhandled.
 */
import { context, propagation, SpanKind, SpanStatusCode, trace, type Span } from '@opentelemetry/api';
import type { FalakOptions } from './config.js';
import { recordException } from './exceptions.js';
import { start } from './sdk.js';
import { SCOPE } from './version.js';

interface H3EventLike {
  method?: string;
  path?: string;
  node?: { req?: { method?: string; url?: string; headers?: Record<string, string | string[] | undefined> }; res?: { statusCode?: number } };
  context: Record<string, unknown> & { matchedRoute?: { path?: string } };
}

interface NitroAppLike {
  hooks: { hook(name: string, fn: (...args: never[]) => unknown): unknown };
}

const SPAN_KEY = '__falakSpan';

export function createFalakNitroPlugin(options: FalakOptions = {}) {
  return function falakNitroPlugin(nitroApp: NitroAppLike): void {
    start(options);
    const tracer = trace.getTracer(SCOPE);

    nitroApp.hooks.hook('request', ((event: H3EventLike) => {
      try {
        if (trace.getActiveSpan()) return; // node http instrumentation already owns the request
        const method = event.method ?? event.node?.req?.method ?? 'GET';
        const rawPath = event.path ?? event.node?.req?.url ?? '/';
        const headers = event.node?.req?.headers ?? {};
        const parent = propagation.extract(context.active(), headers);
        event.context[SPAN_KEY] = tracer.startSpan(
          method,
          {
            kind: SpanKind.SERVER,
            attributes: { 'falak.event.type': 'request', 'http.request.method': method, 'url.path': rawPath.split('?')[0] ?? '/' },
          },
          parent,
        );
      } catch {
        // never throw into the app
      }
    }) as never);

    nitroApp.hooks.hook('afterResponse', ((event: H3EventLike) => {
      try {
        const span = event.context[SPAN_KEY] as Span | undefined;
        const target = span ?? trace.getActiveSpan();
        const route = event.context.matchedRoute?.path;
        if (target && route) {
          target.setAttribute('http.route', route);
          target.updateName(`${event.method ?? event.node?.req?.method ?? 'GET'} ${route}`);
        }
        if (!span) return;
        const status = event.node?.res?.statusCode ?? 200;
        span.setAttribute('http.response.status_code', status);
        if (status >= 500) span.setStatus({ code: SpanStatusCode.ERROR });
        span.end();
      } catch {
        // ignore
      }
    }) as never);

    nitroApp.hooks.hook('error', ((error: unknown, ctx?: { event?: H3EventLike }) => {
      const span = (ctx?.event?.context[SPAN_KEY] as Span | undefined) ?? trace.getActiveSpan();
      recordException(error, { handled: false, ...(span ? { span } : {}) });
    }) as never);
  };
}

export default createFalakNitroPlugin();
