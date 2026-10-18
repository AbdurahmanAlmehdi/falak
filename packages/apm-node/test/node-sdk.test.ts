import { beforeAll, describe, expect, test } from 'bun:test';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { fakeOtlpServer, otlpSpans } from './helpers.js';

const root = join(import.meta.dir, '..');
const dist = join(root, 'dist');

beforeAll(() => {
  if (!existsSync(join(dist, 'register.js'))) {
    const build = Bun.spawnSync(['bun', 'run', 'build'], { cwd: root });
    if (build.exitCode !== 0) throw new Error(build.stderr.toString());
  }
});

describe('Node.js: import "@othmanhaba/apm-node/register" (NodeSDK + auto-instrumentation)', () => {
  test('http server + undici fetch spans are mapped, linked and exported to the agent', async () => {
    const agent = await fakeOtlpServer();

    try {
      const proc = Bun.spawn(['node', '--import', join(dist, 'register.js'), join(import.meta.dir, 'fixtures/node-app.mjs')], {
        cwd: root,
        env: {
          ...process.env,
          FALAK_OTLP_ENDPOINT: agent.url,
          FALAK_SERVICE_NAME: 'node-app',
          FALAK_SITE_ID: '01JNODE',
          FALAK_APM_METRICS: 'false',
          FALAK_DIST_INDEX: join(dist, 'index.js'),
        },
        stdout: 'pipe',
        stderr: 'pipe',
      });

      const [code, out, err] = await Promise.all([proc.exited, new Response(proc.stdout).text(), new Response(proc.stderr).text()]);
      expect({ code, err }).toEqual({ code: 0, err: '' });

      const spans = otlpSpans(agent.received);
      const byType = (t: string) => spans.filter((s) => s.attrs['falak.event.type'] === t);

      const hello = byType('request').find((s) => s.attrs['url.path'] === '/hello')!;
      expect(hello).toBeDefined();
      expect(hello.kind).toBe(2);
      expect(hello.attrs).toMatchObject({ 'http.request.method': 'GET', 'http.response.status_code': 200 });
      expect(hello.resource).toMatchObject({ 'service.name': 'node-app', 'falak.site.id': '01JNODE' });

      const outgoing = byType('outgoing_request').find((s) => String(s.attrs['url.full']).includes('/downstream'))!;
      expect(outgoing).toBeDefined();
      expect(outgoing.kind).toBe(3);
      expect(outgoing.traceId).toBe(hello.traceId);
      expect(outgoing.attrs['url.full']).toContain('api_key=%5Bredacted%5D');
      expect(outgoing.attrs['http.response.status_code']).toBe(200);

      // traceparent propagated to the downstream request
      const { traceparent } = JSON.parse(out) as { traceparent: string };
      expect(traceparent).toStartWith(`00-${hello.traceId}-`);

      const boom = byType('request').find((s) => s.attrs['url.path'] === '/boom')!;
      expect(boom.status.code).toBe(2);
      const event = boom.events.find((e: { name: string }) => e.name === 'exception');
      expect(event.attributes.find((a: { key: string }) => a.key === 'falak.exception.handled').value.boolValue).toBe(true);

      // the exporter's own requests to the agent are not traced
      expect(spans.some((s) => String(s.attrs['url.full'] ?? '').startsWith(agent.url))).toBe(false);
    } finally {
      await agent.close();
    }
  }, 30_000);
});
