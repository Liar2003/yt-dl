# yt-dl — TikTok · Facebook · YouTube Downloader

A Node.js + TypeScript Telegram bot (no framework) plus a matching web downloader, backed by Supabase. Send it a link, get the file.

- **TikTok** → watermark-free video or photo carousel + audio button
- **Facebook** → best video + audio button (messy share links auto-cleaned)
- **YouTube** → quality menu (1080p→360p + m4a/opus) via server-side redirect buttons
- **Search** → any text becomes a YouTube search with tappable results
- `/username @handle` → browse & download from any TikTok account
- Web UI for the same three platforms
- Admin suite: force-join gate, ads, broadcasts, bans, stats, logs, maintenance mode

## Guides

| Guide | For |
|---|---|
| [User guide](docs/USER_GUIDE.md) | Anyone using the bot in Telegram |
| [Admin guide](docs/ADMIN_GUIDE.md) | Deployment, every admin command, operations |

## Stack

- **Runtime**: Node.js 18+ (20+ recommended) · **Package manager**: [Bun](https://bun.sh)
- **HTTP**: [Hono](https://hono.dev) on `@hono/node-server`
- **Database**: [Supabase JS](https://supabase.com/docs/reference/javascript) (Postgres, service-role key)

## Layout

```
src/app.ts           Routes: /webhook, /, /ajax, /dl, /proxy
                     (+ legacy /index.php?dl=1|proxy=1|ajax=1, /webhook.php)
src/server.ts        Standalone entry — binds the port (dev / `bun start`)
api/index.ts         Vercel entry — adapts the same app to (req, res)
scripts/             embed-view.ts — inlines src/views/index.html
src/views/           index.html (source of truth) + generated indexHtml.ts
src/controllers/     BotController (user flow), AdminController
src/services/        Telegram API, extractors (Tikwm, Tool77), ads, broadcast,
                     force-join, statistics, media handling
src/models/          Thin table wrappers (users, downloads, settings, cache, …)
src/core/            Supabase client, webhook router, TTL cache
src/helpers/         Validator, logger, text helpers
src/views/           index.html — the web downloader page
bin/                 set-webhook.ts, poll-single.ts (legacy)
.env                 Credentials & tuning (never commit real values)
database/            schema.postgres.sql — run once in the Supabase SQL editor
```

## Quick deploy

```bash
bun install                      # install dependencies
cp .env.example .env             # fill in Supabase + Telegram credentials
                                 # then run database/schema.postgres.sql in Supabase
bun run set-webhook              # register webhook_url with Telegram
bun run dev                      # or: bun run build && bun start
```

## Deploy to Vercel

`api/index.ts` adapts the same Hono app to a serverless function and
`vercel.json` rewrites every path to it, so `/webhook`, `/`, `/ajax`, `/dl`,
`/proxy` and the legacy `index.php` / `webhook.php` URLs all keep working
unchanged.

1. Push the repo and import it at vercel.com/new (Bun is detected from
   `bun.lock`; `bun run build` runs automatically and embeds the page).
2. In **Project → Settings → Environment Variables** add everything from
   `.env.example`, plus two serverless-specific ones — the project directory
   is read-only, only `/tmp` is writable:
   `TEMP_DIR=/tmp` and `LOG_FILE=/tmp/app.log` (leave `LOG_FILE` empty to
   log to the `logs` table and console only).
3. Deploy, then register the webhook against the new URL:
   `WEBHOOK_URL=https://<app>.vercel.app/webhook bun run set-webhook`.

Two things to know before you ship:

- `vercel.json` caps the function at **60s** (the Hobby plan limit). The
  large-media path allows up to 120s, so on a Pro plan raise
  `functions["api/index.ts"].maxDuration` to `300`, or lower
  `MAX_URL_UPLOAD_BYTES` to keep files under the limit.
- `/proxy` streams YouTube files through Vercel, so that counts against your
  bandwidth quota (and your Supabase/Telegram egress, where the real media
  transfer happens).
