# Admin Guide — TikTok · Facebook · YouTube Downloader Bot

Complete reference for running the bot: setup, every admin command, and the features you control from chat.

---

## 1. Who is an admin

Two kinds, both always active:

1. **Bootstrap admin** — the `ADMIN_TELEGRAM_ID` in `.env`. Works even with no database. Cannot be removed via commands.
2. **Table admins** — added with `/addadmin <telegram_id>`, stored in the `admins` table, removable with `/removeadmin`.

Admins keep full user access *plus* the command set below. Anything an admin **forwards** to the bot is intercepted (see §6 Ads and §7 Broadcasting).

---

## 2. First-time deployment

Requirements: Node.js 18+ (20+ recommended), [Bun](https://bun.sh) as the package manager, a Supabase project, HTTPS hosting.

1. Install dependencies and fill in `.env` (copy `.env.example`):
   - `SUPABASE_URL` / `SUPABASE_SERVICE_ROLE_KEY` — Supabase → Settings → API. The service-role key stays on the server only.
   - `BOT_TOKEN` — from @BotFather
   - `WEBHOOK_URL` — public HTTPS URL of this app's `/webhook` route
   - `WEBHOOK_SECRET` — optional shared secret; when set it is sent to Telegram as `secret_token` and checked on every incoming webhook
   - `ADMIN_TELEGRAM_ID` — your numeric Telegram ID
   - `GOOGLE_API_KEY` — YouTube Data API v3 key (powers search)
   - `BOT_USERNAME` — @username the web page links to
2. Create the tables: run `database/schema.postgres.sql` once in the Supabase SQL editor (idempotent — safe to re-run). It also installs the two RPC functions the app uses.
   - From any Telegram chat you can send `/setup` to *verify* which tables exist; slash commands route before the DB is touched, so it works even when the schema is missing.
3. Register the webhook: `bun run set-webhook` (or point BotFather at `/webhook` manually).
4. Start the app: `bun run build && bun start`, or `bun run dev` for a quick start. The same process serves the webhook, the web UI, and the download endpoints.

---

## 3. Command reference

Send `/admin` anytime for the built-in dashboard listing all of these.

### Overview & users

| Command | Action |
|---|---|
| `/admin` | Dashboard: total users/downloads, ads stored, feature toggle states |
| `/users` | 30 most recent users (`🚫/✅` ban state, ID, @username) |
| `/ban <telegram_id>` | Block a user (they get a banned notice on any message) |
| `/unban <telegram_id>` | Unblock |
| `/history <telegram_id>` | A user's last 15 downloads (type + timestamp) |
| `/list <telegram_id>` | Same but shows the actual URLs (last 20) |
| `/top` | Top 10 downloaders by count |

Get IDs from `/users` or the automatic new-user alert (§5).

### Force join

| Command | Action |
|---|---|
| `/forcejoin on` \| `off` | Master switch for the membership gate |
| `/addchannel @channel [Title]` | Add a required channel |
| `/removechannel @channel` | Remove one |
| `/channels` | List configured channels |

**Important:** the bot must be an **admin in each required channel**, otherwise it can't check membership and every check fails closed ("not joined"). Users who haven't joined get a button list + **✅ Check Again**; their pending request resumes automatically once they pass.

### Broadcasting

| Command | Action |
|---|---|
| `/broadcast <text>` | Send plain text to all users |
| `/forward` | Arm a media broadcast, then forward any message to the bot within 10 minutes |

`/forward` broadcasts **any content type** (photo, video, poll, …) because it reuses your forwarded message instead of text. Both report `sent/total (failed)` when done.

### Ads

Ads are shown to users after successful downloads (one random ad per download) while ads are ON.

| Command | Action |
|---|---|
| *(no command)* | **Forward any message to the bot** → it's saved as an ad automatically |
| `/ads on` \| `off` | Toggle ad delivery |
| `/adslist` | Stored ads with IDs |
| `/adsremove <id>` | Delete one |

An ad is stored as a pointer to the forwarded message in your chat — don't delete the original from your chat history, or delivery will fail silently for users.

### Monitoring

| Command | Action |
|---|---|
| `/stats` | Last 7 days: downloads + new users per day |
| `/logs` | Last 15 lines of `storage/logs/app.log` (file-backed — works even when the DB is down) |
| `/errors` | Last 15 entries logged at `error` level |

Watch `app.log` for `Tool77 API returned an error` — that's the extraction API failing; Facebook links are auto-normalized before sending (`?mibextid=…` tracking junk stripped), so most such errors mean the content itself is unavailable.

### Access control

| Command | Action |
|---|---|
| `/addadmin <telegram_id>` | Grant admin commands |
| `/removeadmin <telegram_id>` | Revoke (bootstrap admin unaffected) |
| `/admins` | List table admins + reminder about the bootstrap ID |

### System

| Command | Action |
|---|---|
| `/maintenance on` \| `off` | When ON, non-admins get a maintenance notice; admins unaffected |
| `/setup` | Check which tables exist and report anything missing — apply `database/schema.postgres.sql` to fix |

---

## 4. What the bot does automatically

- **New-user alerts** — every first-time user triggers a full report to all admins (name, @username, ID, profile link, language, premium status, where they found the bot, total user count).
- **Facebook URL cleanup** — tracking parameters and trailing slashes are stripped; `m.`/`web.` hosts folded onto `www.`; `fb.watch` short links resolved — tool77 only accepts clean `facebook.com/<type>/<id>` shapes.
- **Big-file handling** — videos over 20 MB are downloaded to temp storage and uploaded as a file automatically.
- **Caching** — extraction results are cached in the `cache` table (`TOOL77_CACHE_TTL`). For YouTube this is also the lifetime of menu buttons; buttons redirect through your own `/dl?id=…`.

## 5. Web downloader

The same host serves a paste-a-link web UI ("reel") using the same extractors (`src/views/index.html`):

- `GET /` — the page itself (`POST /` is an alias of `/ajax`)
- `POST /ajax` — JSON API behind the page
- `POST /webhook` — Telegram updates (POST only; a GET is not a webhook)
- `GET /dl?id=…&kind=video&h=1080` / `kind=audio&fmt=m4a` — redirector the YouTube menu buttons point to
- `GET /proxy?id=…&kind=video|audio` — streams YouTube files through your server (googlevideo rejects foreign IPs)

Legacy PHP URLs keep working so links already in users' chat history and
the webhook path Telegram is currently registered against still answer:
`GET /index.php?dl=1…` and `?proxy=1…`, `POST /index.php?ajax=1`, and
`POST /webhook.php`. Plain `GET /index.php` redirects to `/`.

Set `BOT_USERNAME` in `.env` to link the page to your bot.

## 6. Maintenance notes

- **Logs**: `storage/logs/app.log` (flat file) plus the `logs` table. `/logs` reads the file, `/errors` reads the table.
- **Temp files** for large uploads go to `storage/temp` and are cleaned after send.
- **Legacy**: `bin/poll-single.ts` and the `youtube_downloads` table belong to an older YouTube flow; current YouTube handling is entirely tool77-based.
- **Never commit** filled-in credentials — `.env` is gitignored and holds every secret. The service-role key grants full database access: keep it server-side.
