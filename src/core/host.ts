import { AsyncLocalStorage } from 'node:async_hooks';

/**
 * Host of the HTTP request currently being served.
 *
 * Scoped with AsyncLocalStorage instead of a module variable: the bot
 * reads it several `await`s after the webhook arrives, and a second
 * request landing in between would otherwise overwrite it (PHP read
 * `$_SERVER['HTTP_HOST']`, which is per-request by construction).
 * Outside a request — CLI scripts — the store is empty and callers
 * fall back to the configured webhook URL.
 */
const hostScope = new AsyncLocalStorage<string | null>();

export function runWithHost<T>(host: string | null, run: () => T): T {
  return hostScope.run(host, run);
}

export function getRequestHost(): string | null {
  return hostScope.getStore() ?? null;
}
