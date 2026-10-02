/**
 * Vercel serverless entry point.
 *
 * Vercel functions take a plain (req, res) listener rather than listening
 * on a port, so the same Hono app the standalone server runs is adapted
 * here instead of being re-implemented. `getRequestListener` bridges
 * Node's IncomingMessage/ServerResponse to Hono's fetch-style handler.
 *
 * vercel.json rewrites every path to this function, which keeps the
 * original request URL for routing (/webhook, /dl, /proxy, /ajax, the
 * legacy /index.php and /webhook.php entry points …).
 */
import { getRequestListener } from '@hono/node-server';
import { app } from '../src/app.js';

export default getRequestListener(app.fetch);
