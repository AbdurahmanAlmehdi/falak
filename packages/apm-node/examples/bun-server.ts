// bun run examples/bun-server.ts
import { recordException, start, withFalakRequest } from '@othmanhaba/apm-node';

start(); // Bun: lightweight provider (no Node auto-instrumentation)

Bun.serve({
  port: 3000,
  fetch: withFalakRequest(
    async (req) => {
      try {
        return Response.json({ path: new URL(req.url).pathname });
      } catch (error) {
        recordException(error); // handled
        return new Response('degraded', { status: 200 });
      }
    },
    { route: (req) => new URL(req.url).pathname.replace(/\/\d+/g, '/:id') },
  ),
});
