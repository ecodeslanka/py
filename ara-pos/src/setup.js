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
  ['admin_low_stock', 'Alert: low stock', `⚠️ *Low stock alert*\n{{message}}`, null],
];
const SETTINGS = {
  company_name: 'ARA GROUP', company_tagline: 'Footwear Manufacturers & Distributors', company_address: 'Sri Lanka',
  public_url: '', company_phone: '', company_email: '', currency: 'LKR', invoice_prefix: 'ARA-', invoice_footer: 'Thank you for your business!',
  payment_terms_days: '30', allow_negative_stock: '0', default_country_code: '94', remind_days_before: '3',
  smtp_host: '', smtp_port: '587', smtp_secure: '0', smtp_user: '', smtp_pass: '', smtp_from: '',
  auto_email_invoice: '1', auto_whatsapp_invoice: '1',
  wa_enabled: '0', wa_api_url: 'https://ecodes.online', wa_send_path: '/api/send', wa_method: 'POST',
  wa_api_key: '', wa_sender: '', wa_headers: '{}',
  wa_body: '{"api_key":"{{api_key}}","sender":"{{sender}}","number":"{{to}}","message":"{{message}}","media_url":"{{media_url}}","filename":"{{filename}}"}',
  notify_whatsapp_to: '', notify_email_to: '', notify_via_whatsapp: '0', notify_via_email: '0', remind_customers_whatsapp: '0',
  backup_enabled: '1', backup_keep: '14',
};
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
  for (const c of EXP_CATS) await q('INSERT IGNORE INTO expense_categories (name) VALUES (?)', [c]);
  for (const c of ['Slippers - Men', 'Slippers - Women', 'Slippers - Kids', 'Sandals']) await q('INSERT IGNORE INTO categories (name) VALUES (?)', [c]);
  log('Database ready.');
  return pool;
}
module.exports = setup;
if (require.main === module) setup().then(() => process.exit(0)).catch(e => { console.error(e); process.exit(1); });
