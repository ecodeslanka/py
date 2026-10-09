# The Wedded — PHP Website with Admin Panel

A full wedding-photography website built in PHP, with a mobile-responsive admin
panel to manage everything without touching code.

## What's included

- **Public site**: Home, About Us, Collection, Contact Us, and individual Album pages, plus a custom 404 page.
- **Admin panel** (`/admin`): Dashboard, Hero Slider, Categories, Albums, Album Images, Inquiries, Settings.
- **Database**: your own MySQL/MariaDB database. The app creates all required tables and seed data automatically the first time it connects.
- Fully mobile responsive, front-end and admin panel alike.

## Requirements

- PHP 8.0+ with the `pdo_mysql`, `fileinfo`, and `mbstring` extensions (all standard on virtually every host).
- A MySQL or MariaDB database (5.7+/10.2+), which you create yourself — via cPanel "MySQL Databases", Plesk, phpMyAdmin, or your hosting provider's equivalent.
- Any standard web server (Apache with `.htaccess` support, or Nginx/LiteSpeed with an equivalent 404 rule — see note below).
- Write permission on the `uploads/` folder.

## Installation

1. **Create a MySQL database** through your host's control panel (cPanel → "MySQL Databases" is the usual place). Create a database, a database user, and add that user to the database with **all privileges**. Note down the database name, username, password, and host (almost always `localhost`).
2. Upload the entire contents of this folder to your web server (e.g. into `public_html/` or a subfolder).
3. In `includes/`, **copy `db-config.sample.php` to a new file named `db-config.php`**, then open it and fill in the database details from step 1:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'your_database_name');
   define('DB_USER', 'your_database_user');
   define('DB_PASS', 'your_database_password');
   ```
4. Make sure `uploads/` (and its subfolders `hero`, `albums`, `site`) are writable by PHP (`chmod 775` is usually enough; ask your host if unsure).
5. Visit your site's homepage in a browser. On first load, the app automatically creates all the required tables plus default settings, categories, a sample hero slide, and a sample album — you'll see a clear on-screen message instead of a blank page if anything (like the DB credentials) still needs attention.
6. Go to `/admin/` and log in with the default account:
   - **Username:** `admin`
   - **Password:** `admin123`
7. Immediately go to **Settings → Admin Account** and change the username/password.

## Using the Admin Panel

- **Hero Slider** — Upload one or more images for the homepage hero. Add more than one to get an auto-rotating slider; drag to reorder.
- **Categories** — These are the "What We Capture" services shown on the homepage and the filter buttons on the Collection page (e.g. Weddings, Engagements). Add, edit, delete, reorder.
- **Albums** — Each album belongs to a category. Set a title, description, location, cover image, and two switches:
  - **Featured** → shows the album in the homepage "Featured Stories" section.
  - **Show in Collection** → shows the album on the public Collection page.
- **Album Images** — Click "Images" next to any album to upload/reorder/caption/delete the photos inside it. The first photo uploaded becomes the album cover automatically (you can change this any time).
- **Inquiries** — Every submission from the public Contact form appears here, newest first.
- **Settings**
  - *General*: site name, logo (used in header + footer, with a configurable link), tagline, SEO description.
  - *Contact Details*: phone, WhatsApp number (used for the floating WhatsApp button + Contact page), email, address, social links.
  - *About & Footer Content*: About page title/body/image, footer blurb.
  - *Admin Account*: change your login username/password (current password required).

## Notes on hosting

- **Apache**: the included `.htaccess` files handle the custom 404 page and block direct web access to `includes/` (where your DB password lives). No further setup needed.
- **Nginx/other**: add an equivalent `error_page 404 /404.php;` directive (with `try_files` configured so it's actually executed by PHP), and a rule denying access to the `/includes/` path, if you're not using Apache.
- `includes/db-config.php` contains your database password — never share it or commit it to a public repository. Only `includes/db-config.sample.php` (with no real credentials) is meant to be shared/tracked.

## Folder structure

```
/               Public pages (index.php, about.php, contact.php, collection.php, album.php, 404.php)
/includes       Shared PHP: config.php (DB connection + schema), db-config.php (your credentials, you create this), functions.php (helpers), header.php/footer.php
/assets         Public CSS/JS
/uploads        User-uploaded images (hero, album covers/photos, logo, about image)
/admin          Admin panel (login, dashboard, hero/categories/albums/images/inquiries/settings)
/admin/assets   Admin CSS/JS
```
