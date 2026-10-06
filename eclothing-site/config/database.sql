-- ============================================================
-- ECLOTHING Store — Database Schema  (MySQL 5.7+ / MariaDB 10.3+)
-- Import:  mysql -u root -p < config/database.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS emax_store
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE emax_store;

-- ------------------------------------------------------------
-- Admin users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  email         VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(100) DEFAULT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login    DATETIME     DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default admin  →  username: admin   password: Admin@123
-- (hash generated with PASSWORD_DEFAULT — CHANGE THIS AFTER FIRST LOGIN)
INSERT INTO admins (username, email, password_hash, full_name)
VALUES ('admin', 'admin@emax.lk',
        '$2y$12$TfVApuVmEFM2vBdkGNwBtuyBLRLW5ZPAYlRxBwlVUXIqwsIG7jXlC',
        'Store Administrator')
ON DUPLICATE KEY UPDATE username = username;

-- ------------------------------------------------------------
-- Categories — one table, 3 levels via parent_id
--   Level 1: parent_id IS NULL          (Main category)
--   Level 2: parent is a level-1 row    (Sub category)
--   Level 3: parent is a level-2 row    (Sub-sub category)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id  INT UNSIGNED DEFAULT NULL,
  name       VARCHAR(100) NOT NULL,
  slug       VARCHAR(120) NOT NULL UNIQUE,
  icon       VARCHAR(16)  DEFAULT NULL,        -- emoji shown in nav
  level      TINYINT(1)   NOT NULL DEFAULT 1,  -- 1, 2, 3
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id)
    REFERENCES categories(id) ON DELETE CASCADE,
  INDEX idx_parent (parent_id),
  INDEX idx_active (is_active)
) ENGINE=InnoDB;

-- Sample data — ECLOTHING starter categories (top level = menu tabs)
INSERT INTO categories (parent_id, name, slug, icon, level, sort_order) VALUES
(NULL, 'Men',         'men',         NULL, 1, 1),
(NULL, 'Women',       'women',       NULL, 1, 2),
(NULL, 'Kids',        'kids',        NULL, 1, 3),
(NULL, 'Accessories', 'accessories', NULL, 1, 4);

INSERT INTO categories (parent_id, name, slug, icon, level, sort_order) VALUES
(1, 'T-Shirts',        'men-t-shirts',   NULL, 2, 1),
(1, 'Shirts',          'men-shirts',     NULL, 2, 2),
(1, 'Polos',           'men-polos',      NULL, 2, 3),
(1, 'Denims',          'men-denims',     NULL, 2, 4),
(1, 'Trousers',        'men-trousers',   NULL, 2, 5),
(1, 'Shorts',          'men-shorts',     NULL, 2, 6),
(2, 'Tops & Tees',     'women-tops',     NULL, 2, 1),
(2, 'Dresses',         'women-dresses',  NULL, 2, 2),
(2, 'Blouses',         'women-blouses',  NULL, 2, 3),
(2, 'Pants & Skirts',  'women-bottoms',  NULL, 2, 4),
(3, 'Boys',            'kids-boys',      NULL, 2, 1),
(3, 'Girls',           'kids-girls',     NULL, 2, 2),
(4, 'Caps',            'caps',           NULL, 2, 1),
(4, 'Bags',            'bags',           NULL, 2, 2),
(4, 'Belts & Socks',   'belts-socks',    NULL, 2, 3);

-- ------------------------------------------------------------
-- Products — belong to a category (any level)
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Variation Options — a global, reusable attribute library
-- (NOT tied to any product/category — e.g. "Color" -> Red, Blue, Green)
-- How these get applied to products/categories is decided separately.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS variation_options (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60)  NOT NULL UNIQUE,   -- e.g. Color, Size, Storage
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS variation_option_values (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  option_id  INT UNSIGNED NOT NULL,
  value      VARCHAR(60)  NOT NULL,          -- e.g. Red, XL, 128GB
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_val_option FOREIGN KEY (option_id)
    REFERENCES variation_options(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_option_value (option_id, value),
  INDEX idx_val_option (option_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Login throttling (brute-force protection)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address   VARBINARY(16) NOT NULL,
  username     VARCHAR(50)   NOT NULL,
  attempted_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Brands (admin/brands.php) — also self-migrated by admin/items.php
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS brands (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  image      VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_brand_name (name)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Items (admin/items.php)
-- NOTE: this page also self-migrates these tables on first load,
-- so running this file is optional if the page has already run once.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku            VARCHAR(60)  NOT NULL,
  name           VARCHAR(300) NOT NULL,
  category_id    INT UNSIGNED DEFAULT NULL,
  brand_id       INT UNSIGNED DEFAULT NULL,
  description    TEXT,
  condition_type ENUM('new','old') NOT NULL DEFAULT 'new',
  warranty       VARCHAR(150) DEFAULT NULL,
  stock_status   ENUM('in_stock','out_of_stock','pre_order') NOT NULL DEFAULT 'in_stock',
  stock_qty      INT NOT NULL DEFAULT 0,
  cost_price     DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price  DECIMAL(12,2) NOT NULL DEFAULT 0,
  special_price  DECIMAL(12,2) DEFAULT NULL,
  has_variations TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sku (sku)
) ENGINE=InnoDB;
-- category_id / brand_id are plain nullable references (no FK constraint on
-- purpose, so deleting a category/brand never blocks or cascades into items).

CREATE TABLE IF NOT EXISTS item_images (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id     INT UNSIGNED NOT NULL,
  image_path  VARCHAR(255) NOT NULL,
  is_feature  TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_item_images_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS item_variations (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id        INT UNSIGNED NOT NULL,
  variation_name VARCHAR(150) NOT NULL,
  size_label     VARCHAR(40)  DEFAULT NULL,   -- e.g. M, XL, 32
  color_name     VARCHAR(60)  DEFAULT NULL,   -- e.g. Black
  color_hex      VARCHAR(9)   DEFAULT NULL,   -- e.g. #111111 (swatch colour)
  image_path     VARCHAR(255) DEFAULT NULL,
  cost_price     DECIMAL(12,2) DEFAULT NULL,
  selling_price  DECIMAL(12,2) DEFAULT NULL,
  special_price  DECIMAL(12,2) DEFAULT NULL,
  stock_qty      INT NOT NULL DEFAULT 0,
  sort_order     INT NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_item_variations_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- NOTE: this page also self-migrates these tables on first load,
-- so running this file is optional if the page has already run once.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
  id             TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  site_logo      VARCHAR(255) DEFAULT NULL,
  footer_logo    VARCHAR(255) DEFAULT NULL,
  company_name   VARCHAR(150) NOT NULL DEFAULT '',
  address        TEXT,
  facebook_url   VARCHAR(255) DEFAULT NULL,
  linkedin_url   VARCHAR(255) DEFAULT NULL,
  youtube_url    VARCHAR(255) DEFAULT NULL,
  twitter_url    VARCHAR(255) DEFAULT NULL,
  instagram_url  VARCHAR(255) DEFAULT NULL,
  tiktok_url     VARCHAR(255) DEFAULT NULL,
  enable_koko    TINYINT(1) NOT NULL DEFAULT 0,
  enable_mintpay TINYINT(1) NOT NULL DEFAULT 0,
  enable_payzy   TINYINT(1) NOT NULL DEFAULT 0,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT IGNORE INTO site_settings (id, company_name) VALUES (1, 'ECLOTHING');

CREATE TABLE IF NOT EXISTS site_contact_numbers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone       VARCHAR(40)  NOT NULL,
  description VARCHAR(150) DEFAULT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_emails (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(150) NOT NULL,
  description VARCHAR(150) DEFAULT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_branches (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  address     TEXT NOT NULL,
  details     VARCHAR(255) DEFAULT NULL,
  is_main     TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Google Sign-In columns on site_settings
-- (also self-migrated by includes/functions.php: get_site_settings())
-- ------------------------------------------------------------
ALTER TABLE site_settings
  ADD COLUMN IF NOT EXISTS enable_google_signin TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS google_client_id VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS google_client_secret VARCHAR(255) DEFAULT NULL;

-- ------------------------------------------------------------
-- Customer accounts (Sign Up / Sign In) — self-migrated by
-- includes/functions.php: ensure_customer_tables()
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name          VARCHAR(80)  NOT NULL,
  last_name           VARCHAR(80)  NOT NULL,
  email               VARCHAR(150) NOT NULL UNIQUE,
  mobile              VARCHAR(20)  NOT NULL UNIQUE,
  password_hash       VARCHAR(255) DEFAULT NULL,
  google_id           VARCHAR(64)  DEFAULT NULL UNIQUE,
  avatar              VARCHAR(255) DEFAULT NULL,
  mobile_verified     TINYINT(1)   NOT NULL DEFAULT 0,
  agreed_terms        TINYINT(1)   NOT NULL DEFAULT 0,
  marketing_opt_in    TINYINT(1)   NOT NULL DEFAULT 0,
  welcome_email_sent  TINYINT(1)   NOT NULL DEFAULT 0,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  last_login          DATETIME     DEFAULT NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cust_email (email),
  INDEX idx_cust_mobile (mobile)
) ENGINE=InnoDB;

-- One-time mobile OTP codes used during Sign Up verification
CREATE TABLE IF NOT EXISTS customer_otps (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mobile      VARCHAR(20)  NOT NULL,
  code_hash   VARCHAR(255) NOT NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at  DATETIME     NOT NULL,
  verified_at DATETIME     DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_otp_mobile (mobile)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Email / SMS / welcome-email settings (Admin -> Email Settings)
-- self-migrated by includes/functions.php: get_email_settings()
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_settings (
  id                     TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  smtp_host              VARCHAR(150) DEFAULT NULL,
  smtp_port              SMALLINT UNSIGNED DEFAULT 587,
  smtp_username          VARCHAR(150) DEFAULT NULL,
  smtp_password          VARCHAR(255) DEFAULT NULL,
  smtp_encryption        ENUM('none','ssl','tls') NOT NULL DEFAULT 'tls',
  from_email             VARCHAR(150) DEFAULT NULL,
  from_name              VARCHAR(150) DEFAULT NULL,
  send_welcome_email     TINYINT(1) NOT NULL DEFAULT 1,
  welcome_email_subject  VARCHAR(200) DEFAULT 'Welcome to {{company_name}}!',
  welcome_email_body     TEXT,
  sms_gateway_url        VARCHAR(255) DEFAULT NULL,
  sms_gateway_api_key    VARCHAR(255) DEFAULT NULL,
  sms_sender_id          VARCHAR(30)  DEFAULT NULL,
  require_mobile_verify  TINYINT(1) NOT NULL DEFAULT 1,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT IGNORE INTO email_settings (id, from_name) VALUES (1, 'ECLOTHING');
