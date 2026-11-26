# Tharidu Chemistry – Online Class System (PHP + MySQL)

Plain PHP 8+ / MySQL. No framework, no Composer. URLs hide the `.php` extension (Apache `.htaccess`).

## Install (cPanel / Hostinger shared hosting)
1. Create a MySQL database + user in hPanel/cPanel.
2. Upload everything to `public_html` (or a sub-folder).
3. Edit `inc/config.php`: DB_HOST / DB_NAME / DB_USER / DB_PASS, and set `APP_KEY` to a long random string (do this BEFORE adding links; changing it later breaks stored encrypted links).
4. Open `https://yoursite/install` and press **Run installer**. Then **delete `install.php`**.
5. Login at `/login` – default admin: mobile **0700000000**, password **Admin@123**. Change it at *Admin users* immediately.

Requires PHP extensions: pdo_mysql, curl, openssl, mbstring, fileinfo (all standard). Needs `mod_rewrite` (Apache/LiteSpeed).

## Admin panel (`/admin`)
| Section | What it does |
|---|---|
| **Classes** | Only a class **name** (e.g. *Senara – Kegalle*) with Edit / Delete. Used at student registration only. |
| **Batches / Grades** | Batch: name, cover, order, active. Grades have their own page. |
| **Lessons** | Colourful boxes; View / Create / Edit open in a modal. Fields: name, fee, batch, grade, month + year, description, student limit, due date. (No class selection.) |
| Lesson → **Materials** | Own pages. Types: YouTube Videos, PDF Notes, Google Drive, Notice (image), Assessments (link), Zoom Recordings (+passcode). Group name, optional view count (YouTube), tabs per type, drag-to-reorder, edit modal, preview. All links stored AES-256 encrypted. |
| Lesson → **Enrolled students** | View, edit, change password, remove, payments |
| Lesson → **Zoom & live** | Create Zoom meeting by API, paste Zoom link or YouTube live link |
| **Payments** | Orders: one student payment may contain several lessons; one click approves the whole order and enrols the student in every lesson |
| **Home page** | Edit every text, icon, box and photo of the public home page |
| **Site settings** | Slides, WhatsApp groups, phone + social links, bank details, Dialog SMS + OTP, Zoom keys |

## Student panel (mobile-app style)
Register (mobile, name, optional NIC, class, batch, password, OTP) → **Lessons** screen shows lessons **batch-wise** (switch batch with the chips) → **Add to cart** → **Cart**: one bank slip for all lessons → admin approves → lessons unlock: materials (grouped), live / Zoom links, recordings.

## AI chatbot (Google Gemini) – Admin → Settings → AI Chatbot
Paste a free key from aistudio.google.com/apikey. Students open **Ask AI** (bottom bar): ask A/L chemistry questions in **Sinhala (default) or English**, optionally attach a photo (question paper, structure, working). The bot refuses non-chemistry questions. Settings: on/off, model, **questions per student per day** (resets midnight Sri Lanka time), max image size, memory, bot **name**, **icon** (emoji or image), welcome message, extra teacher instructions, plus a usage table. Counted questions = successful answers only; 3-second flood guard.

## ECODES PLAYER V 5.0 – Admin → Settings → Player
YouTube recordings play in a custom secure player: no YouTube logo / title / share / links, own controls, one-time token for the video id, shortcut / right-click blocking, pause + blur when the window loses focus, PrintScreen blocking.
- **Tap play → full screen** on every device: Android and desktop use the real Fullscreen API (+ landscape lock); iPhone Safari (no element fullscreen) uses a full-screen overlay with a ⟳ rotate-to-landscape button; Back button / ✕ Exit leave full screen.
- **Watermark** (name + mobile): text template ({name} {mobile} {date}), movement **up ⇅ down / random / scroll / fixed**, side, speed, opacity, size, colour. Phones skip the DevTools detector to avoid false pauses.
Note: no website can technically stop OS-level screen recording; the watermark makes leaks traceable and view limits cap sharing.

### Troubleshooting: “Video player configuration error” (YouTube error 153)
YouTube refuses an embed when the page does not send a Referer. This site now sends `Referrer-Policy: strict-origin-when-cross-origin` and builds the iframe with a matching `referrerpolicy`. If it still appears: (1) the site must run on **https**, (2) the video on YouTube must be **Public or Unlisted** with *Allow embedding* switched on, (3) make sure hosting / Cloudflare does not add a `Referrer-Policy: no-referrer` header.

### iPhone / iOS Chrome & Safari
iOS only starts a YouTube video from a tap that lands **on the video itself**, so on iOS the first tap goes straight to the video; the moment it plays the player switches to a full-screen overlay (⟳ rotates to landscape). Browsers on iOS cannot hide their address bar, so for **true full screen** students use *Share → Add to Home Screen* (the site is an installable web app) and open it from the home screen.

## Upgrading an existing install
Upload the new files. The database upgrades itself on the first page load.

## SMS (Dialog eSMS, API v1.7)
Site settings → SMS: choose **API** (username/password → token cached 12h, auto re-login) or **GET key** (`esmsqk`, needs Dialog to enable it). Mask max 11 chars. Use *Send test* to verify. Tick *Enable OTP* to require verification at registration. Failures are logged (last 8 shown).

## Zoom
Zoom Marketplace → Build App → Server-to-Server OAuth, scope `meeting:write:meeting:admin`, activate, paste Account ID / Client ID / Client secret in Site settings → Zoom → *Test*.

## Security notes
Prepared statements, CSRF tokens, bcrypt passwords, login/OTP throttling, uploads renamed + type-checked, documents and slips only served through access-checked scripts, `inc/` and `sql/` blocked. YouTube links are never in page HTML for the class list; the player page decrypts server-side (the embed URL is visible to an enrolled student while watching – a determined student can always find a video URL, so the view limit is a deterrent, not DRM). Use HTTPS.
