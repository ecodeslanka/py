-- ============================================================
-- Migration: add Products + Product Variations
-- Run this ONLY if your database was created before this update
-- (i.e. it already has the `categories` table but no `products` table).
-- Usage: mysql -u root -p emax_store < config/migration_products_variations.sql
-- ============================================================
USE emax_store;

CREATE TABLE IF NOT EXISTS products (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(150) NOT NULL,
  slug         VARCHAR(170) NOT NULL UNIQUE,
  sku          VARCHAR(60)  DEFAULT NULL,
  base_price   DECIMAL(12,2) NOT NULL DEFAULT 0,
  description  TEXT DEFAULT NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order   INT          NOT NULL DEFAULT 0,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id)
    REFERENCES categories(id) ON DELETE CASCADE,
  INDEX idx_prod_cat (category_id)
) ENGINE=InnoDB;

-- Note: Variations are handled separately as a global library —
-- see config/migration_variation_options.sql
