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
| # | Feature | Where |
|---|---|---|
| 1 | Default admin + more admin users | Admin users |
| 2 | Batches: name, cover image, order, active | Batches |
| 3 | Classes: name, fee, batch, grade (own page), month+year, description, student limit (+ physical/online, venue, due date) | Classes, Grades |
| 4 | Materials inside a class: YouTube recording (link stored AES-256 encrypted, optional per-student view count) or Document upload; optional description | Classes → Manage → Materials |
| 5 | Enrolled students: view, edit, delete, change password, view payments | Manage → Enrolled students |
| 6 | Zoom & live: create Zoom meeting by API, paste a Zoom link, or YouTube live link; start time + duration minutes | Manage → Zoom & live |
| 7 | Site settings: about us, image, home slides, WhatsApp groups, phone/WhatsApp/YouTube/Facebook/Instagram/TikTok, bank details, SMS (Dialog eSMS), Zoom keys | Site settings |
| – | Offline payment approvals (approve = enrol) | Payments |

## Student flow
Register (name, mobile, optional NIC, Physical/Online, batch, class e.g. *Senara – Kegalle*, password) → OTP SMS if enabled → pick class, upload bank slip → admin approves → class unlocked (materials, live links, payments).
Create physical classes like "Senara – Kegalle" in *Classes* (type = Physical, venue = Senara – Kegalle) so they appear in registration.

## SMS (Dialog eSMS, API v1.7)
Site settings → SMS: choose **API** (username/password → token cached 12h, auto re-login) or **GET key** (`esmsqk`, needs Dialog to enable it). Mask max 11 chars. Use *Send test* to verify. Tick *Enable OTP* to require verification at registration. Failures are logged (last 8 shown).

## Zoom
Zoom Marketplace → Build App → Server-to-Server OAuth, scope `meeting:write:meeting:admin`, activate, paste Account ID / Client ID / Client secret in Site settings → Zoom → *Test*.

## Security notes
Prepared statements, CSRF tokens, bcrypt passwords, login/OTP throttling, uploads renamed + type-checked, documents and slips only served through access-checked scripts, `inc/` and `sql/` blocked. YouTube links are never in page HTML for the class list; the player page decrypts server-side (the embed URL is visible to an enrolled student while watching – a determined student can always find a video URL, so the view limit is a deterrent, not DRM). Use HTTPS.
