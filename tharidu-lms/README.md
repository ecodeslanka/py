# Tharidu Chemistry – Online Class System (PHP + MySQL)

Plain PHP 8+ / MySQL. No framework, no Composer. URLs hide the `.php` extension (Apache `.htaccess`).

## Install (cPanel / Hostinger shared hosting)
1. Create a MySQL database + user in hPanel/cPanel.
2. Upload everything to `public_html` (or a sub-folder).
3. Edit `inc/config.php`: DB_HOST / DB_NAME / DB_USER / DB_PASS, and set `APP_KEY` to a long random string (do this BEFORE adding links; changing it later breaks stored encrypted links).
4. Open `https://yoursite/install` and press **Run installer**. Then **delete `install.php`**.
5. Login at `/login` – default admin: mobile **0700000000**, password **Admin@123**. Change it at *Admin users* immediately.

Requires PHP extensions: pdo_mysql, curl, openssl, mbstring, fileinfo (all standard). Needs `mod_rewrite` (Apache/LiteSpeed).

## Admin panel (`/admin`) – Apple-style dashboard
| Section | What it does |
|---|---|
| **Classes** | Only a class **name** (e.g. *Senara – Kegalle*) with Edit / Delete. Students pick it at registration; lessons link to it. |
| **Batches** | Name, cover image, order, active |
| **Grades** | Own page; used when creating lessons |
| **Lessons** | Lesson name, fee, batch, grade, month + year, description, student limit (+ physical/online, class, due date). Shown as boxes. |
| Lesson → **Materials** | YouTube recording (AES-256 encrypted link, optional per-student view count) or Document upload; optional description |
| Lesson → **Enrolled students** | View, edit, change password, delete / remove, view payments |
| Lesson → **Zoom & live** | Create Zoom meeting by API, paste a Zoom link, or YouTube live link; start time + duration |
| **Payments** | Approve / reject offline payments (approve = student joins the lesson) |
| **Students**, **Admin users** | Manage accounts; default admin is created at install |
| **Home page** | Edit EVERY text, icon, box and photo of the public home page |
| **Site settings** | Slides, WhatsApp groups, phone + social links, bank details, Dialog SMS + OTP, Zoom keys |

## Student flow
Register (mobile, name, optional NIC, Physical/Online, **class** for physical, batch, password) → OTP SMS if enabled → dashboard shows the **lessons** for their batch/class → Join & pay (upload slip) → admin approves → lesson unlocked (materials, live links, payments, due date).

## Upgrading an existing install
Just upload the new files. The database upgrades itself on the first page load (adds Classes, moves old lesson "venues" into Classes).

## SMS (Dialog eSMS, API v1.7)
Site settings → SMS: choose **API** (username/password → token cached 12h, auto re-login) or **GET key** (`esmsqk`, needs Dialog to enable it). Mask max 11 chars. Use *Send test* to verify. Tick *Enable OTP* to require verification at registration. Failures are logged (last 8 shown).

## Zoom
Zoom Marketplace → Build App → Server-to-Server OAuth, scope `meeting:write:meeting:admin`, activate, paste Account ID / Client ID / Client secret in Site settings → Zoom → *Test*.

## Security notes
Prepared statements, CSRF tokens, bcrypt passwords, login/OTP throttling, uploads renamed + type-checked, documents and slips only served through access-checked scripts, `inc/` and `sql/` blocked. YouTube links are never in page HTML for the class list; the player page decrypts server-side (the embed URL is visible to an enrolled student while watching – a determined student can always find a video URL, so the view limit is a deterrent, not DRM). Use HTTPS.
