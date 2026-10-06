-- ============================================================
-- Migration: switch Variations from "per-product" to a global,
-- reusable Variation Options library (Option -> Values).
-- Safe to run even if product_variations doesn't exist yet.
-- Usage: mysql -u root -p emax_store < config/migration_variation_options.sql
-- ============================================================
USE emax_store;

DROP TABLE IF EXISTS product_variations;

CREATE TABLE IF NOT EXISTS variation_options (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60)  NOT NULL UNIQUE,
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS variation_option_values (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  option_id  INT UNSIGNED NOT NULL,
  value      VARCHAR(60)  NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_val_option FOREIGN KEY (option_id)
    REFERENCES variation_options(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_option_value (option_id, value),
  INDEX idx_val_option (option_id)
) ENGINE=InnoDB;
