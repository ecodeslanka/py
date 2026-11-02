-- ARA Group POS & Store Management - schema (MySQL 5.7+ / MariaDB 10.3+)
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  permissions TEXT NOT NULL,
  is_system TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(120) NULL,
  phone VARCHAR(30) NULL,
  password_hash VARCHAR(100) NOT NULL,
  role_id INT NOT NULL,
  active TINYINT NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  user_name VARCHAR(100) NULL,
  action VARCHAR(40) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id INT NULL,
  details TEXT NULL,
  ip VARCHAR(60) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (created_at), INDEX (entity, entity_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wa_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  body TEXT NOT NULL,
  email_subject VARCHAR(200) NULL,
  active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS brands (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  type ENUM('material','other','product') NOT NULL DEFAULT 'other',
  contact_person VARCHAR(100) NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  category_id INT NULL,
  brand_id INT NULL,
  supplier_id INT NULL,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  low_stock_level INT NOT NULL DEFAULT 10,
  active TINYINT NOT NULL DEFAULT 1,
  created_by INT NULL, updated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_variants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  colour VARCHAR(50) NOT NULL DEFAULT 'Default',
  qty INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_pv (product_id, colour),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS goods_receipts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grn_no VARCHAR(30) NOT NULL,
  supplier_id INT NULL,
  date DATE NOT NULL,
  ref VARCHAR(60) NULL,
  note VARCHAR(255) NULL,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (supplier_id), INDEX (date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  variant_id INT NOT NULL,
  type ENUM('opening','receive','sale','return_in','return_out','damage','adjust','sale_cancel') NOT NULL,
  qty INT NOT NULL,
  balance_after INT NOT NULL DEFAULT 0,
  unit_cost DECIMAL(12,2) NULL,
  ref_type VARCHAR(20) NULL,
  ref_id INT NULL,
  note VARCHAR(255) NULL,
  user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (variant_id), INDEX (product_id), INDEX (created_at), INDEX (ref_type, ref_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  phone VARCHAR(30) NULL,
  whatsapp VARCHAR(30) NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT NULL,
  customer_name VARCHAR(120) NULL,
  date DATE NOT NULL,
  due_date DATE NULL,
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  cheque_pending DECIMAL(14,2) NOT NULL DEFAULT 0,
  balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('unpaid','partial','pending','cheque_pending','paid') NOT NULL DEFAULT 'unpaid',
  had_credit TINYINT NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  created_by INT NULL, updated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (customer_id), INDEX (date), INDEX (status),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  product_id INT NULL,
  variant_id INT NULL,
  description VARCHAR(255) NOT NULL,
  colour VARCHAR(50) NULL,
  qty INT NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  customer_id INT NULL,
  date DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method ENUM('cash','bank','cheque') NOT NULL,
  cleared TINYINT NOT NULL DEFAULT 1,
  reference VARCHAR(100) NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (invoice_id), INDEX (customer_id), INDEX (date),
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_cheques (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NULL,
  invoice_id INT NULL,
  payment_id INT NULL,
  cheque_no VARCHAR(40) NOT NULL,
  bank VARCHAR(80) NULL,
  cheque_date DATE NULL,
  amount DECIMAL(14,2) NOT NULL,
  deposit_date DATE NULL,
  status ENUM('received','pending','deposited','cleared','bounced') NOT NULL DEFAULT 'received',
  status_date DATE NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (status), INDEX (deposit_date), INDEX (customer_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier_cheques (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT NOT NULL,
  cheque_no VARCHAR(40) NOT NULL,
  bank VARCHAR(80) NULL,
  amount DECIMAL(14,2) NOT NULL,
  cheque_date DATE NULL,
  present_date DATE NULL,
  status ENUM('issued','presented','cleared','returned') NOT NULL DEFAULT 'issued',
  status_date DATE NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (status), INDEX (present_date),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT NOT NULL,
  date DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method ENUM('cash','bank') NOT NULL DEFAULT 'cash',
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expense_categories (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  date DATE NOT NULL,
  category_id INT NULL,
  description VARCHAR(255) NULL,
  amount DECIMAL(14,2) NOT NULL,
  method ENUM('cash','bank','cheque') NOT NULL DEFAULT 'cash',
  supplier_id INT NULL,
  payee VARCHAR(120) NULL,
  attachment VARCHAR(255) NULL,
  created_by INT NULL, updated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (date), INDEX (category_id),
  FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dedupe_key VARCHAR(120) NOT NULL UNIQUE,
  type VARCHAR(40) NOT NULL,
  severity ENUM('info','warn','danger') NOT NULL DEFAULT 'info',
  title VARCHAR(160) NOT NULL,
  message VARCHAR(500) NULL,
  link VARCHAR(120) NULL,
  is_read TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (is_read), INDEX (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS message_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel ENUM('email','whatsapp') NOT NULL,
  kind VARCHAR(30) NOT NULL DEFAULT 'invoice',
  invoice_id INT NULL,
  customer_id INT NULL,
  recipient VARCHAR(150) NULL,
  subject VARCHAR(200) NULL,
  body TEXT NULL,
  status ENUM('sent','failed','manual') NOT NULL,
  error VARCHAR(500) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (invoice_id), INDEX (created_at)
) ENGINE=InnoDB;
