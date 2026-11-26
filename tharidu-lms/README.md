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

## Past papers & MCQ quizzes
**Admin → Exams**
- **Past papers**: year, paper name, paper file (PDF/Word/image), optional marking scheme, optional batch. Students download the paper and the marking scheme from **Papers → Past papers** (grouped by year, searchable).
- **MCQ quizzes** (admin MCQ maker). Per quiz you choose how it is sent to students:
  - **Exam** – optional *opens / closes* time, time limit (auto-submit), attempts per student, when answers are shown (after submit / after close / never);
  - **Practice** – always open, unlimited tries, instant correct answer + explanation after every question;
  - **Who**: everyone (common), a batch, or only students of a specific lesson (class). Optional SMS to those students on publish.
- **Gemini scanner** (Settings → AI Chatbot key needed): upload a paper PDF / DOCX / photo (+ optional marking scheme) or paste text → Gemini builds every MCQ with options, the correct answer and a short explanation. With a marking scheme the answers come from it (100%); otherwise Gemini solves them and shows its confidence. Questions it is unsure about are marked ⚠ — the admin only taps the right option (or “Answer is right / Confirm all”) and publishes. A stored past paper can be turned into a quiz with one button (*Make MCQ with AI*).
- Results page: ranking, average, hardest questions, reset an attempt.
**Pictures in questions & options** – in the question editor every question and every answer option can have a picture: **drag & drop** a file, **Ctrl+V** a copied picture / screenshot (it goes into the option you are typing in, or the question box), or click to browse. Pictures are shrunk to 1600 px, served only to logged-in users (students only for published quizzes) and deleted when no longer used.
**Auto-cut by AI** – on *Scan a paper with Gemini* keep “✂ Auto-cut diagrams & structures” ticked: the PDF is rendered page by page in the admin's browser (bundled pdf.js – nothing to install), Gemini returns the position of every diagram / structure / graph, and the server (PHP GD) cuts each one out and attaches it to the right question or option. Options that are only a picture are handled too. Works for a PDF, several photos of pages, or a stored past paper (*Make MCQ with AI*). Needs the PHP **GD** extension (standard on Hostinger).
Hosting note: scanning big papers can take up to ~1 minute – ask Hostinger for `max_execution_time` ≥ 120 s and `upload_max_filesize` ≥ 20 MB if scans/uploads time out (use ≤ 20-page files otherwise). `.doc` is not readable – save as `.docx` or PDF.

### Troubleshooting: “MySQL server has gone away” while scanning
A long Gemini scan keeps the database connection idle and some hosts close it. The site now reconnects automatically and retries (no action needed). If an AI scan still stops, ask the host for `max_execution_time` ≥ 120 s or scan fewer pages.

## Upgrading an existing install
Upload the new files. The database upgrades itself on the first page load.

## SMS (Dialog eSMS, API v1.7)
Site settings → SMS: choose **API** (username/password → token cached 12h, auto re-login) or **GET key** (`esmsqk`, needs Dialog to enable it). Mask max 11 chars. Use *Send test* to verify. Tick *Enable OTP* to require verification at registration. Failures are logged (last 8 shown).

## Zoom
Zoom Marketplace → Build App → Server-to-Server OAuth, scope `meeting:write:meeting:admin`, activate, paste Account ID / Client ID / Client secret in Site settings → Zoom → *Test*.

## Security notes
Prepared statements, CSRF tokens, bcrypt passwords, login/OTP throttling, uploads renamed + type-checked, documents and slips only served through access-checked scripts, `inc/` and `sql/` blocked. YouTube links are never in page HTML for the class list; the player page decrypts server-side (the embed URL is visible to an enrolled student while watching – a determined student can always find a video URL, so the view limit is a deterrent, not DRM). Use HTTPS.
