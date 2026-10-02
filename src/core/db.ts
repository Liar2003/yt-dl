import { createClient, type SupabaseClient } from '@supabase/supabase-js';
import { Config } from '../config.js';

let instance: SupabaseClient | null = null;

/**
 * Single shared Supabase client, created lazily on first use.
 *
 * The service-role key is used deliberately: this is a server-side app
 * that owns its schema, so it needs to write past Row Level Security.
 * The schema file therefore enables RLS on every table *without*
 * policies — anonymous/authenticated keys are locked out entirely,
 * while the service role bypasses RLS by design.
 *
 * Every query call site in the app goes through this client, so this
 * is the only place the project URL / key actually matter.
 */
export function db(): SupabaseClient {
  if (instance === null) {
    const url = Config.get<string>('supabase.url');
    const key = Config.get<string>('supabase.service_role_key');

    if (!url || !key) {
      throw new Error('SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY must be set (see .env.example)');
    }

    instance = createClient(url, key, {
      auth: { persistSession: false, autoRefreshToken: false },
      global: { headers: { 'x-client-info': 'yt-dl' } },
    });
  }
  return instance;
}

/**
 * Reads every row a query matches, in pages.
 *
 * Supabase caps a single response at the project's "Max rows" setting
 * (1000 by default), so an unbounded select() silently returns only the
 * first N rows — which would leave /broadcast reaching a tenth of the
 * audience while still reporting "Sent to 1000/1000". Queries must
 * order by a unique column (the primary key) for the paging to be
 * stable across pages.
 */
export async function allRows<T>(
  page: (from: number, to: number) => PromiseLike<{ data: T[] | null; error: { message: string } | null }>,
  what: string,
  pageSize = 1000,
): Promise<T[]> {
  const rows: T[] = [];
  for (let from = 0; ; from += pageSize) {
    const batch = unwrap(await page(from, from + pageSize - 1), what);
    rows.push(...batch);
    if (batch.length < pageSize) return rows;
  }
}

/**
 * Throws when Supabase answers with an error instead of returning rows.
 * NonNullable on the way out: `data` is nullable in the response type
 * (empty select, no matching row), so the row case is what callers
 * want when there was no error.
 */
export function unwrap<T>(
  result: { data: T; error: { message: string } | null },
  what: string,
): NonNullable<T> {
  if (result.error) {
    throw new Error(`${what}: ${result.error.message}`);
  }
  return result.data as NonNullable<T>;
}
