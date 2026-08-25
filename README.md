# yt-dl — TikTok · Facebook · YouTube Downloader

A pure-PHP Telegram bot (no framework) plus a matching web downloader. Send it a link, get the file.

- **TikTok** → watermark-free video or photo carousel + audio button
- **Facebook** → best video + audio button (messy share links auto-cleaned)
- **YouTube** → quality menu (1080p→360p + m4a/opus) via server-side redirect buttons
- **Search** → any text becomes a YouTube search with tappable results
- `/username @handle` → browse & download from any TikTok account
- Web UI (`index.php`) for the same three platforms
- Admin suite: force-join gate, ads, broadcasts, bans, stats, logs, maintenance mode

## Guides

| Guide | For |
|---|---|
| [User guide](docs/USER_GUIDE.md) | Anyone using the bot in Telegram |
| [Admin guide](docs/ADMIN_GUIDE.md) | Deployment, every admin command, operations |

## Layout

```
app/Controllers/   BotController (user flow), AdminController
app/Services/      Telegram API, extractors (Tikwm, Tool77), ads, broadcast,
                   force-join, statistics, media handling
app/Models/        Thin table wrappers (users, downloads, settings, cache, …)
app/Core/          Config, Database (PDO singleton), Router (webhook)
bin/               set-webhook.php, poll-single.php (legacy)
config/config.php  Credentials & tuning (never commit real values)
database/          schema.sql + migrations; /setup applies them from chat
index.php          Web downloader page + ?dl=1 redirector + ?proxy=1 streamer
webhook.php        Telegram webhook entry point
```

## Quick deploy

```bash
php create_table.php     # or send /setup to the bot after it's live
php bin/set-webhook.php  # register webhook_url with Telegram
```
