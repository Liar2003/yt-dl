# User Guide — TikTok · Facebook · YouTube Downloader Bot

Everything the bot can do and how to use it. No setup knowledge needed — this is for people who chat with the bot.

---

## 1. Quick start

| You send | You get |
|---|---|
| A **TikTok** link | The video (no watermark) + 🎵 audio button |
| A **Facebook** link | Best-quality video + 🎵 audio button |
| A **YouTube** link | A menu of download buttons (video + audio) |
| Any **search text** | YouTube results you can pick from |
| `/username @handle` | A TikTok user's recent videos to pick from |

Just paste a link in the chat — no commands required.

---

## 2. Downloading by platform

### TikTok
Send any TikTok link (`vm.tiktok.com/…`, `vt.tiktok.com/…`, `www.tiktok.com/@user/video/…`).

- **Video post** → video arrives with a `🎵 Download Audio` button underneath.
- **Photo carousel** → all slides arrive as an album (slides that have a "live photo" are sent as short videos), followed by a caption message with the same audio button.
- Short `vm.` / `vt.` links are resolved automatically — just paste whatever you copied from the app.
- Tap **🎵 Download Audio** within a while after the video (the audio link is cached); if it expired, resend the link.

### Facebook
Send any Facebook video/reel link:

```
https://www.facebook.com/reel/1501719754917442
https://www.facebook.com/watch/?v=1234567890
https://fb.watch/rAbCdEfGhI/
```

- Tracking junk in the URL (`?mibextid=…&s=…`) is stripped automatically — messy app-share links work fine.
- `fb.watch` short links are followed automatically.
- You get the best combined audio+video file, plus a `🎵 Download Audio` button.

### YouTube
Send any YouTube link (`youtube.com/watch?v=…`, `youtu.be/…`, `youtube.com/shorts/…`).

The bot replies with the video's cover image and a button menu instead of a file:

| Button | Meaning |
|---|---|
| 🎬 **1080p / 720p / 480p / 360p** | Video download in that resolution |
| …with 🔇 | Video-only stream (no sound — how YouTube serves >360p separately) |
| 🎵 **M4A · 128kbps**, **OPUS**, … | Audio-only downloads |

Buttons open your browser and the download starts there. They live about an hour — after that you'll see *"That download menu expired"*; just resend the link.

### Search → YouTube
Type anything that isn't a link or command (e.g. `lofi study mix`). You get 10 results as buttons:

- Tap a result → its download menu opens.
- **➡️ Next** → next page of results.

---

## 3. Browse a TikTok user's videos

```
/username @tiktokhandle
/story @tiktokhandle        ← same thing
```

Lists their recent videos (title + play count), 12 per screen with **➡️ Next** pagination. Tap one to download it exactly like a normal TikTok link.

---

## 4. Commands

| Command | What it does |
|---|---|
| `/start` | Welcome message + your lifetime download count |
| `/help` | Quick usage summary |
| `/about` | What the bot is |
| `/username @handle` | Browse a TikTok user's recent videos |

The bot also works when added to a group (use `/command@BotUsername` syntax there).

---

## 5. Things you might see

**🔒 "Join required"** — the bot's owner requires joining one or more Telegram channels first. Join via the buttons, then tap **✅ Check Again**. Your original request resumes automatically — you don't have to resend the link.

**🛠 "Under maintenance"** — the owner paused the bot temporarily. Try again later.

**🚫 "You've been banned"** — the owner blocked your account. Contact the bot's support.

**⚠️ "The bot isn't set up yet"** — brand-new deployment; check back soon.

**Expired buttons** — download menus and audio buttons hold cached links that expire. Resend the original link to get fresh ones.

**Couldn't fetch / private / deleted** — the content isn't publicly accessible or was removed. Nothing to fix on your side.

---

## 6. Good to know

- Files over ~20 MB are downloaded by the server and uploaded to Telegram directly — delivery just takes a bit longer.
- Every successful download may be followed by a promotional message from the bot's owner (an "ad"). That's normal.
- Nothing is stored about *what* you watch beyond a log of which links were downloaded, used only for statistics.
