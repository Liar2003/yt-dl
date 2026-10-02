/** Text helpers replacing PHP's mb_* / number_format functions. */

export function mbSubstr(text: string, start: number, length?: number): string {
  const chars = Array.from(text);
  return chars.slice(start, length === undefined ? undefined : start + length).join('');
}

export function mbStrlen(text: string): number {
  return Array.from(text).length;
}

export function numberFormat(n: number): string {
  return n.toLocaleString('en-US');
}

export function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/** PHP's round($n, 1). */
export function round1(n: number): number {
  return Math.round(n * 10) / 10;
}

/** PHP's ucfirst(). */
export function ucFirst(text: string): string {
  return text.length === 0 ? text : text[0].toUpperCase() + text.slice(1);
}

/** gmdate('Y-m-d H:i:s') — UTC timestamp in SQLite/MySQL's default format. */
export function utcDateTime(date: Date = new Date()): string {
  return date.toISOString().slice(0, 19).replace('T', ' ');
}

/** DATE('now') — calendar day in UTC. */
export function utcDate(date: Date = new Date()): string {
  return date.toISOString().slice(0, 10);
}

/** Renders a DB timestamp (ISO-8601 from Postgres) as 'YYYY-MM-DD HH:MM:SS'. */
export function formatTimestamp(value: unknown): string {
  const raw = String(value ?? '');
  const parsed = new Date(raw);
  return Number.isNaN(parsed.getTime()) ? raw : utcDateTime(parsed);
}
