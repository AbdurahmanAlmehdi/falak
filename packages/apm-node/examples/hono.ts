// Hono on Bun / Deno / Node: wrap app.fetch.
// @ts-nocheck — hono is not a dependency of this package
import { Hono } from 'hono';
import { start, withFalakRequest } from '@othmanhaba/apm-node';

start();

const app = new Hono();
app.get('/users/:id', (c) => c.json({ id: c.req.param('id') }));

export default {
  fetch: withFalakRequest(app.fetch, {
    // Hono exposes the matched route after dispatch; a static map or regex works up front.
    route: (req) => (new URL(req.url).pathname.startsWith('/users/') ? '/users/:id' : undefined),
  }),
};
