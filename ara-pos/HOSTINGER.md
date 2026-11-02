# Installing on Hostinger (Node.js hosting)

Works on Hostinger **Business / Cloud** web hosting plans with the **Node.js Web App** feature (and any VPS with Node + MySQL).

## 1. Create the MySQL database
hPanel → **Databases → Management** → create a database + user.
Note the three values (Hostinger prefixes them, e.g. `u123456789_ara`):
`database name`, `database user`, `password`. Host is `localhost`.

## 2. Upload the app
hPanel → **Websites → Add website → Node.js Apps** → **Upload your website files** → choose **`ARA-POS-Hostinger.zip`**
(the zip has the files at its top level – do not unzip it first).

Build settings:
| Field | Value |
|---|---|
| Framework | Express.js (or "Other") |
| Node version | 20.x or 22.x |
| Package manager | npm |
| Entry file | `server.js` |
| Build / install | `npm install` (default) |
| Start command | `npm start` |

## 3. Environment variables
In the app's **Settings → Environment variables** add:

| Name | Example |
|---|---|
| `DB_HOST` | `localhost` |
| `DB_USER` | `u123456789_ara` |
| `DB_PASSWORD` | *(your DB password)* |
| `DB_NAME` | `u123456789_ara` |
| `JWT_SECRET` | *(any long random text, 40+ chars)* |
| `PUBLIC_URL` | `https://yourdomain.com` |
| `ADMIN_USER` / `ADMIN_PASSWORD` | first admin login (change it afterwards) |
| `DATA_DIR` | `/home/u123456789/ara-data` – keeps uploads & backups safe when you redeploy |
| `TZ` | `Asia/Colombo` |

Do **not** set `PORT` – Hostinger provides it.

## 4. Deploy
Press **Deploy / Restart**. On first start the app creates all tables itself (no SQL import needed).
Open your domain → log in → **More → Password** to change the admin password.
Then in **Settings** enter: company details, WhatsApp (ecodes.online) key, SMTP email, and check **Public URL**.

(Alternative: import `db/schema.sql` via phpMyAdmin first – optional.)

## 5. Domain & HTTPS
Connect your domain in hPanel and enable the free SSL. On a phone open the site and choose
**Add to Home Screen** – it installs like an app.

## Updating later
Upload the new zip again (or connect GitHub for auto-deploy). Your data is safe: it is in MySQL and `DATA_DIR`.

## Troubleshooting
* **"Startup failed: ER_ACCESS_DENIED"** – wrong DB user/password; recheck the prefixed names.
* **"ECONNREFUSED"** – try `DB_HOST=127.0.0.1`.
* **App shows 503** – check the build/runtime log in hPanel; make sure Entry file is `server.js`.
* **WhatsApp/email links wrong** – `PUBLIC_URL` must be your https domain.
* Hosting plans cap Node memory/CPU; the app is light (`DB_POOL=5` connections by default).
