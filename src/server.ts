/**
 * Standalone entry point: `bun run dev` (tsx) and `bun run build && bun
 * start` (node dist/src/server.js) both end up here.
 *
 * Everything HTTP-related lives in src/app.ts so other runtimes can mount
 * the same app without binding a port — see api/index.ts for the Vercel
 * handler, which adapts `app.fetch` to a plain (req, res) listener.
 */
import { serve } from '@hono/node-server';
import { app } from './app.js';
import { Config } from './config.js';

const port = Config.get<number>('port', 3000);
const host = Config.get<string>('host', '0.0.0.0');

serve({ fetch: app.fetch, port, hostname: host }, (info) => {
  console.log(`yt-dl listening on http://${info.address}:${info.port}`);
  console.log(`webhook: POST /webhook  ·  web UI: GET /  ·  ajax: POST /ajax`);
});
