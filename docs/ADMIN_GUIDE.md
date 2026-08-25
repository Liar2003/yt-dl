# Admin Guide — TikTok · Facebook · YouTube Downloader Bot

Complete reference for running the bot: setup, every admin command, and the features you control from chat.

---

## 1. Who is an admin

Two kinds, both always active:

1. **Bootstrap admin** — the `admin_telegram_id` in `config/config.php`. Works even with no database. Cannot be removed via commands.
2. **Table admins** — added with `/addadmin <telegram_id>`, stored in the `admins` table, removable with `/removeadmin`.

Admins keep full user access *plus* the command set below. Anything an admin **forwards** to the bot is intercepted (see §6 Ads and §7 Broadcasting).

---

## 2. First-time deployment

Requirements: PHP 8+ (curl, pdo_mysql), MySQL/MariaDB, HTTPS hosting.

1. Fill in `config/config.php`:
   - `db` — database credentials
   - `bot_token` — from @BotFather
   - `webhook_url` — public HTTPS URL of `webhook.php`
   - `webhook_secret` — random string Telegram echoes back in a header
   - `admin_telegram_id` — your numeric Telegram ID
   - `google_api_key` — YouTube Data API v3 key (powers search)
2. Create the tables — either:
   - CLI: `php create_table.php`, or
   - From any Telegram chat: send `/setup` (applies `database/schema.sql` + `database/migrate_v5.sql`; both idempotent). This works even when the DB was broken, because slash commands route before the DB is touched.
3. Register the webhook: `php bin/set-webhook.php` (or point BotFather at `webhook.php` manually).

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
| `/setup` | (Re)create all tables from SQL files — safe to rerun |

---

## 4. What the bot does automatically

- **New-user alerts** — every first-time user triggers a full report to all admins (name, @username, ID, profile link, language, premium status, where they found the bot, total user count).
- **Facebook URL cleanup** — tracking parameters and trailing slashes are stripped; `m.`/`web.` hosts folded onto `www.`; `fb.watch` short links resolved — tool77 only accepts clean `facebook.com/<type>/<id>` shapes.
- **Big-file handling** — videos over 20 MB are downloaded to temp storage and uploaded as a file automatically.
- **Caching** — extraction results are cached in the `cache` table (`tool77_cache_ttl`). For YouTube this is also the lifetime of menu buttons; buttons redirect through your own `index.php?dl=1…`.

## 5. Web downloader (`index.php`)

The same host serves a paste-a-link web UI ("reel") using the same extractors:

- `POST ?ajax=1` — JSON API behind the page
- `GET ?dl=1&id=…&kind=video&h=1080` / `kind=audio&fmt=m4a` — redirector the YouTube menu buttons point to
- `GET ?proxy=1&id=…&kind=video|audio` — streams YouTube files through your server (googlevideo rejects foreign IPs)

Edit `$botUsername` near the bottom of `index.php` to link the page to your bot.

## 6. Maintenance notes

- **Logs**: `storage/logs/app.log` (flat file) plus the `logs` table. `/logs` reads the file, `/errors` reads the table.
- **Temp files** for large uploads go to `storage/temp` and are cleaned after send.
- **Legacy**: `bin/poll-single.php` and the `youtube_downloads` table belong to an older YouTube flow; current YouTube handling is entirely tool77-based.
- **Never commit** filled-in credentials — `config/config.php` holds secrets.
