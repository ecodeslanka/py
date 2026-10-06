# ECLOTHING Store — PHP + MySQL Platform

## Folder structure
```
emax/
├── .htaccess                  → hides .php extension, pretty URLs, security rules
├── index.php                  → home page (theme, uses shared header/footer)
├── category.php               → /category/{slug} landing page
├── config/
│   ├── config.php             → DB credentials, sessions, security headers
│   └── database.sql           → full schema + sample data (import this)
├── includes/
│   ├── header.php             → shared site header (dynamic 3-level category nav)
│   ├── footer.php             → shared site footer
│   └── functions.php          → CSRF, escaping, slugs, category tree, throttling
├── assets/css/
│   ├── style.css              → store theme (extracted from your HTML) + dropdown menus
│   └── admin.css              → control panel theme
└── admin/
    ├── login.php              → default login page   (/admin/login)
    ├── logout.php
    ├── index.php              → dashboard            (/admin/index)
    ├── categories.php         → main categories      (/admin/categories)
    ├── subcategories.php      → sub categories       (/admin/subcategories)
    ├── sub-subcategories.php  → sub-sub categories   (/admin/sub-subcategories)
    └── includes/              → auth guard + panel layout (blocked from web)
```

## Install (5 steps)
1. Copy the `emax` folder into your web root (e.g. `htdocs/emax` or `/var/www/html`).
2. Import the database:  `mysql -u root -p < config/database.sql`
   (or import `config/database.sql` in phpMyAdmin).
3. Edit `config/config.php` → set `DB_USER` / `DB_PASS` (create a limited-privilege
   MySQL user in production, not root).
4. Make sure Apache has `mod_rewrite` enabled and `AllowOverride All` for the folder.
   If the site lives in a subfolder, uncomment/adjust `RewriteBase` in `.htaccess`.
5. Open the site:
   - Store front:   http://localhost/emax/
   - Admin login:   http://localhost/emax/admin/login

## Default admin credentials
```
Username: admin
Password: Admin@123
```
⚠ Change this immediately. To make a new hash, run:
`php -r "echo password_hash('YourNewPassword', PASSWORD_DEFAULT);"`
and update the `password_hash` column in the `admins` table.

## How categories link to the theme
- Admin → **Categories** creates level-1 items → appear in the red category nav bar
  and the footer "Categories" column.
- Admin → **Sub Categories** creates level-2 items under a main category → appear as
  hover dropdowns in the nav.
- Admin → **Sub-Sub Categories** creates level-3 items → appear as fly-out menus
  inside each sub-category dropdown, and as tiles on /category/{slug} pages.
- "Hide" toggles an item off everywhere on the site without deleting it.
- Deleting a category cascades to all of its children (FK ON DELETE CASCADE).

## Security features included
- PDO **prepared statements** everywhere (SQL-injection safe), emulation disabled
- `password_hash` / `password_verify` (bcrypt) — no plain-text passwords
- **CSRF tokens** on every form (login + all admin actions), timing-safe compare
- **Login throttling**: 5 failed attempts per IP → 15-minute lockout (DB-backed)
- Session hardening: HttpOnly + SameSite cookies, strict mode, ID regeneration on
  login and every 5 minutes, 30-minute idle timeout, user-agent fingerprint binding
- All output escaped with `htmlspecialchars` (XSS protection)
- Generic login errors (no username enumeration)
- `.htaccess`: directory listing off, `config/` + `includes/` blocked, `.sql/.log/.env`
  blocked, PHP execution disabled in `/assets`, security headers, optional HTTPS redirect
- Errors logged, never displayed (`ENVIRONMENT = 'production'`)

## Hiding .php in the URL
Handled in `.htaccess`:
- `/admin/login.php` → 301 redirects to `/admin/login`
- `/admin/login` → internally serves `login.php`
- `/category/mobile-phones` → internally serves `category.php?slug=mobile-phones`

## ECLOTHING — sizes, colours & stock
- **Admin → Items → Variations** ("This item has variations"): every row is one **size + colour** with its
  own **stock**, optional price/special price and photo. Use the quick builder: type sizes (or click a preset
  such as XS–XXL / 28–38 / Kids / Free size), add colours (name + swatch colour), set "Stock each" and press
  **Create rows**, then adjust each row's stock and Save. Leave size *or* colour empty if the item doesn't use it.
- Website behaviour:
  - Product page: size buttons first, then the colours available in that size. Sold-out sizes/colours are
    crossed out; picking a colour also crosses out the sizes that colour is sold out in. Live message:
    "In stock" / "Only 2 left" / "Sold out". Quantity can't go above the stock of that size/colour.
  - Upload a photo on one row of a colour — every size of that colour shows it when the colour is picked.
  - Product cards: colour dots + a "Quick add · pick size" bar on hover (sold-out sizes crossed out).
  - Shop page: **Size** and **Colour** filters (only shows items that are in stock in that size/colour).
  - Cart + checkout check stock again; placing an order **takes the quantity off** that size/colour, and
    **cancelling** the order in Admin → Orders puts it back.
- Admin → Items list shows total pieces and how many size/colour rows are sold out.

## ECLOTHING — home page content from the admin panel
- **Admin → Home Slides**: full-width hero banners (image + optional heading/text/buttons). With no slides,
  a built-in hero shows a collage of your newest product photos.
- **Admin → Categories**: top-level categories (Men, Women, Kids, Accessories) are the menu tabs; their
  sub-categories become the mega-menu columns and the "Shop by category" image tiles. Upload a tall
  (3:4) **image** on each category.
- **Admin → Home Collections**: the big image tiles ("Shop the collections"). Upload a photo for each;
  a *Featured* tile is shown double-size.
- **Admin → Items**: "Flash Sale" → *On sale now*, "Top Item" → *Best sellers*; newest items appear in
  *New arrivals* automatically. The 2nd photo of an item shows when its card is hovered.
- **Admin → Brands**: brands with a logo appear in the "Shop by brand" strip.
- Size guide text: `includes/size_guide.php`.

## Theme
- Style layer: `assets/css/theme-eclothing.css` (loaded last). Colours at the top in `:root`
  (`--ec-blue`, `--ec-ink` …). Fonts: Archivo (headings) + Inter (text).
- Logos: `assets/images/eclothing-logo.png` (header), `eclothing-logo-white.png` (footer),
  `eclothing-mark.png` (favicon).
- **Existing live database:** import `config/migration_eclothing.sql` once (adds size/colour columns,
  renames the store, resets collections, adds clothing categories). Products, orders and customers
  are not touched.
