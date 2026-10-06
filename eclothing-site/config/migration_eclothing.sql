-- ============================================================
-- ECLOTHING — switch-over for an EXISTING live database (run ONCE)
--
-- 1. Adds the size / colour columns used for per-size-and-colour stock
--    (the site also adds them automatically on first load — this is a safety net)
-- 2. Renames the store + resets page title / footer texts
-- 3. Replaces the home "Collections" tiles (Admin → Home Collections)
-- 4. Hides the old brands from "Shop by Brand"
-- 5. Adds clothing starter categories (skipped if the slug exists)
--
-- Import in phpMyAdmin (select your database → Import), or:
--   mysql -u USER -p YOUR_DB < config/migration_eclothing.sql
-- Products, orders and customers are NOT touched. If one statement errors
-- because a column already exists, just carry on — the rest still apply.
-- ============================================================
SET NAMES utf8mb4;

-- 1. Size / colour columns --------------------------------------------
ALTER TABLE item_variations ADD COLUMN IF NOT EXISTS size_label VARCHAR(40) DEFAULT NULL AFTER variation_name;
ALTER TABLE item_variations ADD COLUMN IF NOT EXISTS color_name VARCHAR(60) DEFAULT NULL AFTER size_label;
ALTER TABLE item_variations ADD COLUMN IF NOT EXISTS color_hex  VARCHAR(9)  DEFAULT NULL AFTER color_name;

-- 2. Site texts ---------------------------------------------------------
UPDATE site_settings SET company_name = 'ECLOTHING' WHERE id = 1;
UPDATE site_settings SET site_title = 'ECLOTHING — Men''s, Women''s & Kids'' Clothing Online' WHERE id = 1;
UPDATE site_settings SET footer_tagline = 'Everyday clothing that fits right — T-shirts, shirts, denims, dresses and more, with easy size exchanges and island-wide delivery.' WHERE id = 1;
UPDATE site_settings SET footer_copyright_text = '© {year} {company}. All rights reserved.' WHERE id = 1;
UPDATE site_settings SET site_logo = NULL, footer_logo = NULL WHERE id = 1;   -- use the bundled ECLOTHING logo

-- 3. Home collections (upload an image for each in Admin → Home Collections)
ALTER TABLE home_services ADD COLUMN IF NOT EXISTS image VARCHAR(255) DEFAULT NULL AFTER link;
DELETE FROM home_services;
INSERT INTO home_services (title, description, icon, link, sort_order, is_featured, is_active) VALUES
('Men',          'T-shirts, shirts, polos, denims & more', 'fa-solid fa-person',         '/products?category=men',   1, 1, 1),
('Women',        'Tops, dresses, blouses & bottoms',       'fa-solid fa-person-dress',   '/products?category=women', 2, 0, 1),
('New Arrivals', 'Fresh drops every week',                 'fa-solid fa-star',           '/products?sort=newest',    3, 0, 1),
('Kids',         'Comfy everyday wear for little ones',    'fa-solid fa-child-reaching', '/products?category=kids',  4, 0, 1);

-- 4. Brands: hide everything from the old store (re-enable in Admin → Brands)
UPDATE brands SET show_on_home = 0;

-- 5. Starter categories -------------------------------------------------
INSERT IGNORE INTO categories (parent_id, name, slug, level, sort_order) VALUES
(NULL, 'Men',         'men',         1, 1),
(NULL, 'Women',       'women',       1, 2),
(NULL, 'Kids',        'kids',        1, 3),
(NULL, 'Accessories', 'accessories', 1, 4);

INSERT IGNORE INTO categories (parent_id, name, slug, level, sort_order)
SELECT c.id, x.name, x.slug, 2, x.so FROM categories c
JOIN (
  SELECT 'men' p, 'T-Shirts' name, 'men-t-shirts' slug, 1 so UNION ALL
  SELECT 'men', 'Shirts', 'men-shirts', 2 UNION ALL
  SELECT 'men', 'Polos', 'men-polos', 3 UNION ALL
  SELECT 'men', 'Denims', 'men-denims', 4 UNION ALL
  SELECT 'men', 'Trousers', 'men-trousers', 5 UNION ALL
  SELECT 'men', 'Shorts', 'men-shorts', 6 UNION ALL
  SELECT 'women', 'Tops & Tees', 'women-tops', 1 UNION ALL
  SELECT 'women', 'Dresses', 'women-dresses', 2 UNION ALL
  SELECT 'women', 'Blouses', 'women-blouses', 3 UNION ALL
  SELECT 'women', 'Pants & Skirts', 'women-bottoms', 4 UNION ALL
  SELECT 'kids', 'Boys', 'kids-boys', 1 UNION ALL
  SELECT 'kids', 'Girls', 'kids-girls', 2 UNION ALL
  SELECT 'accessories', 'Caps', 'caps', 1 UNION ALL
  SELECT 'accessories', 'Bags', 'bags', 2 UNION ALL
  SELECT 'accessories', 'Belts & Socks', 'belts-socks', 3
) x ON x.p = c.slug;

-- Optional: hide the old store's categories from the menu (uncomment and
-- list their slugs), or delete them in Admin → Categories.
-- UPDATE categories SET is_active = 0 WHERE slug IN ('old-slug-1', 'old-slug-2');
