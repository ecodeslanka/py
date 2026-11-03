// Creates tables + seeds default data. Safe to run repeatedly.
const fs = require('fs'), path = require('path'), bcrypt = require('bcryptjs');
const mysql = require('mysql2/promise');
const cfg = require('./config');
const { DEFAULT_ROLES } = require('./permissions');

const TEMPLATES = [
  ['invoice', 'Invoice to customer', `Hello {{customer}},\n\nThank you for shopping with *{{company}}*.\n\n🧾 Invoice: *{{invoice_no}}*\n📅 Date: {{date}}\n💰 Total: *{{total}}*\n✅ Paid: {{paid}}\n⏳ Balance: *{{balance}}*\n\n📎 View / download your invoice:\n{{link}}\n\nThank you! 🙏\n{{company}} | {{company_phone}}`, 'Invoice {{invoice_no}} from {{company}}'],
  ['payment_received', 'Payment received', `Hello {{customer}},\n\nWe received your payment of *{{amount}}* ({{method}}) for invoice *{{invoice_no}}* on {{date}}.\nRemaining balance: *{{balance}}*\n\nThank you! 🙏\n{{company}}`, 'Payment received - {{invoice_no}}'],
  ['overdue_reminder', 'Overdue payment reminder', `Dear {{customer}},\n\nThis is a friendly reminder that invoice *{{invoice_no}}* dated {{date}} has an outstanding balance of *{{balance}}* (due {{due_date}}).\n\nKindly arrange the payment at your earliest convenience.\n{{link}}\n\nThank you,\n{{company}} | {{company_phone}}`, 'Payment reminder - {{invoice_no}}'],
  ['statement', 'Customer statement', `Dear {{customer}},\n\nPlease find your account statement ({{period}}) from {{company}}.\nOutstanding balance: *{{balance}}*\n\n📎 {{link}}\n\nThank you,\n{{company}}`, 'Account statement - {{company}}'],
  ['cheque_bounced', 'Bounced cheque notice', `Dear {{customer}},\n\nYour cheque *{{cheque_no}}* ({{bank}}) for *{{amount}}* was returned unpaid. Invoice {{invoice_no}} balance is now *{{balance}}*.\nPlease contact us to settle this.\n\n{{company}} | {{company_phone}}`, 'Returned cheque {{cheque_no}}'],
  ['admin_customer_cheque', 'Alert: customer cheque', `🔔 *Customer cheque alert*\n{{title}}\nCheque {{cheque_no}} ({{bank}}) - {{amount}}\nCustomer: {{customer}}\nDeposit date: {{deposit_date}}`, null],
  ['admin_supplier_cheque', 'Alert: supplier cheque', `🔔 *Supplier cheque alert*\n{{title}}\nCheque {{cheque_no}} ({{bank}}) - {{amount}}\nSupplier: {{supplier}}\nPresent date: {{present_date}}`, null],
  ['supplier_payment', 'Payment made to supplier', `Dear {{supplier}},\n\nWe have made a payment of *{{amount}}* ({{method}}) for {{grn_no}} on {{date}}.{{cheque_line}}\nBalance remaining with us: *{{balance}}*\n\nThank you,\n{{company}} | {{company_phone}}`, 'Payment made - {{grn_no}}'],
  ['admin_daily_digest', 'Daily cheque reminder (to you)', `🔔 *{{company}} - Daily reminder ({{date}})*\n{{today_customer}}{{today_supplier}}{{due_supplier}}{{overdue}}{{upcoming}}`, null],
  ['admin_low_stock', 'Alert: low stock', `⚠️ *Low stock alert*\n{{message}}`, null],
];
const SETTINGS = {
  company_name: 'ARA GROUP', company_tagline: 'Footwear Manufacturers & Distributors', company_address: 'Sri Lanka',
  public_url: '', company_phone: '', company_email: '', currency: 'LKR', invoice_prefix: 'ARA-', invoice_footer: 'Thank you for your business!',
  payment_terms_days: '30', allow_negative_stock: '0', default_country_code: '94', remind_days_before: '3',
  smtp_host: '', smtp_port: '587', smtp_secure: '0', smtp_user: '', smtp_pass: '', smtp_from: '',
  auto_email_invoice: '1', auto_whatsapp_invoice: '1',
  wa_enabled: '0', wa_api_url: 'https://ecodes.online', wa_send_path: '/api/send-message', wa_method: 'POST',
  wa_api_key: '', wa_sender: '', wa_headers: '{}',
  wa_body: '',
  notify_whatsapp_to: '', notify_email_to: '', notify_via_whatsapp: '0', notify_via_email: '0', remind_customers_whatsapp: '0',
  digest_enabled: '1', digest_time: '08:00', digest_whatsapp_to: '', digest_email_to: '', digest_last: '',
  backup_enabled: '1', backup_keep: '14',
};
const MIGRATIONS = [
  ['goods_receipts', 'due_date', 'DATE NULL'], ['goods_receipts', 'paid', 'DECIMAL(14,2) NOT NULL DEFAULT 0'], ['goods_receipts', 'cheque_pending', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
  ['goods_receipts', 'balance', 'DECIMAL(14,2) NOT NULL DEFAULT 0'], ['goods_receipts', 'status', "ENUM('unpaid','partial','cheque_pending','paid') NOT NULL DEFAULT 'unpaid'"], ['goods_receipts', 'updated_by', 'INT NULL'],
  ['product_variants', 'size', "VARCHAR(30) NOT NULL DEFAULT ''"], ['product_variants', 'location', "VARCHAR(60) NOT NULL DEFAULT ''"], ['product_variants', 'label', "VARCHAR(160) NOT NULL DEFAULT ''"],
  ['expense_categories', 'kind', "VARCHAR(12) NOT NULL DEFAULT 'general'"], ['expenses', 'item', 'VARCHAR(100) NULL'],
  ['customer_cheques', 'deposit_id', 'INT NULL'],
  ['supplier_payments', 'grn_id', 'INT NULL'], ['supplier_payments', 'cleared', 'TINYINT NOT NULL DEFAULT 1'], ['supplier_cheques', 'payment_id', 'INT NULL'], ['supplier_cheques', 'grn_id', 'INT NULL'],
];
const EXP_CATS = ['Factory Material Purchases', 'Employee Salaries & Wages', 'Factory Expenses', 'Electricity & Water Bills', 'Transport & Delivery', 'Repairs & Maintenance', 'Rent', 'Other / Miscellaneous'];

async function setup(log = console.log) {
  // On shared hosting (Hostinger) the DB already exists and the user cannot create databases - that's fine.
  try {
    const root = await mysql.createConnection({ host: cfg.db.host, port: cfg.db.port, user: cfg.db.user, password: cfg.db.password });
    await root.query(`CREATE DATABASE IF NOT EXISTS \`${cfg.db.database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
    await root.end();
  } catch (e) { log('Skipping CREATE DATABASE (' + e.code + ') - using existing database ' + cfg.db.database); }
  const { pool, q, one } = require('./db');
  const sql = fs.readFileSync(path.join(__dirname, '../db/schema.sql'), 'utf8');
  for (const stmt of sql.split(/;\s*\n/).map(s => s.trim()).filter(s => s && !s.startsWith('--') || /CREATE/.test(s))) {
    const s = stmt.replace(/^--.*\n/gm, '').trim(); if (s) await q(s);
  }
  for (const [t, c, ddl] of MIGRATIONS) {
    const ex = await one('SELECT 1 x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [t, c]);
    if (!ex) await q(`ALTER TABLE \`${t}\` ADD COLUMN \`${c}\` ${ddl}`);
  }
  // variants: colour + size + location is the unique key (replaces the old colour-only key)
  const hasIdx = async n => !!(await one("SELECT 1 x FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_variants' AND INDEX_NAME=?", [n]));
  if (!(await hasIdx('uq_pv2'))) await q('ALTER TABLE product_variants ADD UNIQUE KEY uq_pv2 (product_id, colour, size, location)');
  if (await hasIdx('uq_pv')) await q('ALTER TABLE product_variants DROP INDEX uq_pv');
  await q("UPDATE product_variants SET label=colour WHERE label=''");
  for (const [t, c] of [['expenses', 'payee'], ['expenses', 'item']]) { const ix = await one("SELECT 1 x FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?", [t, c]); if (!ix) await q(`ALTER TABLE \`${t}\` ADD INDEX (\`${c}\`)`); }
  await q("ALTER TABLE payments MODIFY method ENUM('cash','bank','cheque','deposit') NOT NULL");
  // grant the new deposit permissions to the default roles once (Administrator always gets everything)
  const adm = await one("SELECT id FROM roles WHERE name='Administrator'"); if (adm) await q('UPDATE roles SET permissions=? WHERE id=?', [JSON.stringify(require('./permissions').ALL), adm.id]);
  if (!(await one("SELECT v FROM settings WHERE k='migr_deposit_perms'"))) {
    for (const rn of ['Manager', 'Cashier', 'Accountant']) { const r = await one('SELECT id,permissions FROM roles WHERE name=?', [rn]); if (!r) continue; const ps = new Set(JSON.parse(r.permissions || '[]')); ps.add('deposits.view'); ps.add('deposits.manage'); await q('UPDATE roles SET permissions=? WHERE id=?', [JSON.stringify([...ps]), r.id]); }
    await q("INSERT INTO settings (k,v) VALUES ('migr_deposit_perms','1')");
  }
  await q("ALTER TABLE supplier_payments MODIFY method ENUM('cash','bank','cheque') NOT NULL DEFAULT 'cash'");
  await q('UPDATE goods_receipts SET balance=total WHERE paid=0 AND balance=0 AND total>0'); // legacy receipts
  for (const r of DEFAULT_ROLES) {
    const ex = await one('SELECT id FROM roles WHERE name=?', [r.name]);
    if (!ex) await q('INSERT INTO roles (name,description,permissions,is_system) VALUES (?,?,?,?)', [r.name, r.description, JSON.stringify(r.permissions), r.is_system || 0]);
  }
  const admin = await one('SELECT id FROM users LIMIT 1');
  if (!admin) {
    const role = await one("SELECT id FROM roles WHERE name='Administrator'");
    await q('INSERT INTO users (name,username,password_hash,role_id) VALUES (?,?,?,?)', ['Administrator', cfg.adminUser, bcrypt.hashSync(cfg.adminPassword, 10), role.id]);
    log(`Created admin user "${cfg.adminUser}" (change the password after first login!)`);
  }
  for (const [k, v] of Object.entries(SETTINGS)) await q('INSERT IGNORE INTO settings (k,v) VALUES (?,?)', [k, v]);
  for (const [code, name, body, subj] of TEMPLATES) await q('INSERT IGNORE INTO wa_templates (code,name,body,email_subject) VALUES (?,?,?,?)', [code, name, body, subj]);
  await q("UPDATE settings SET v='/api/send-message' WHERE k='wa_send_path' AND v='/api/send'"); // upgrade old default
  for (const c of EXP_CATS) await q('INSERT IGNORE INTO expense_categories (name) VALUES (?)', [c]);
  await q("UPDATE expense_categories SET kind='salary' WHERE kind='general' AND name LIKE '%Salar%'");
  await q("UPDATE expense_categories SET kind='material' WHERE kind='general' AND name LIKE '%Material%'");
  for (const c of ['Slippers - Men', 'Slippers - Women', 'Slippers - Kids', 'Sandals']) await q('INSERT IGNORE INTO categories (name) VALUES (?)', [c]);
  log('Database ready.');
  return pool;
}
module.exports = setup;
if (require.main === module) setup().then(() => process.exit(0)).catch(e => { console.error(e); process.exit(1); });
