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
src/server.ts        Routes: /webhook, /, /ajax, /dl, /proxy
                     (+ legacy /index.php?dl=1|proxy=1|ajax=1, /webhook.php)
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
