# ARA Group – POS & Store Management System

Mobile-app-style web system (PWA) built with **Node.js + Express + MySQL**. Works on phones, tablets and desktops.
On a phone it behaves like an app (bottom tab bar, bottom sheets, add-to-home-screen); on a desktop it becomes a sidebar dashboard.

## Quick start

```bash
# 1. requirements: Node.js 18+ and MySQL 5.7+ / MariaDB 10.3+
npm install
cp .env.example .env        # then edit DB_USER / DB_PASSWORD / JWT_SECRET / PUBLIC_URL
npm start
```

Open http://localhost:3000 and sign in with **admin / admin123** (change it: More → Password).
On first start the app creates the database, all tables, default roles, WhatsApp templates and expense categories automatically
(the MySQL user needs `CREATE` rights, or create an empty database named in `DB_NAME` first).
`db/schema.sql` is included if you prefer to import the tables manually.

## What's included

| Area | Features |
|---|---|
| **Products** | Add / edit / delete. Name, code, category, brand, **supplier (select or add new)**, colour variants, cost / wholesale / selling price, **opening quantity per colour**, low-stock level |
| **Stock** | Goods received (GRN) → auto-increase; sales auto-deduct; customer returns, supplier returns, damages, adjustments; stock by product & colour; full movement history; stock value |
| **POS / Invoices** | Mobile POS, automatic invoice numbers (`ARA-2026-00001`), wholesale/retail pricing, line + invoice discounts, edit / delete (stock restored), PDF invoice with ARA branding |
| **Flexible payments** | One invoice can be split across **Cash + Bank + Cheque + Pending** (amounts or %), later payments any date, multiple payments per invoice, auto status: Unpaid / Partially Paid / Pending / Cheque Pending / Paid |
| **Cheques** | Customer cheques (received → deposited → cleared / bounced) – *never counted as money until Cleared*. Supplier cheques (issued → presented → cleared / returned). Automatic reminders |
| **Pending payments** | Dedicated page with filters (customer, dates, min amount, overdue), payment history, one-tap WhatsApp reminder |
| **Customers** | Per-customer history: totals, pairs bought, week / month / year, invoices, payments, cheques, products, **statements** (PDF + send by WhatsApp / email) |
| **Suppliers** | Material / finished-goods / other suppliers, balances, payments, cheques, goods received |
| **Expenses** | All categories (editable), receipt photo/PDF upload, who created / edited |
| **Reports** | Daily / weekly / monthly / yearly / custom: Profit & Loss, day-by-day, cash & bank book, sales, products, customers, suppliers, expenses, cheques, stock → **PDF & Excel** |
| **Dashboard** | Today / month sales, income, expenses, profit-loss, stock value, customer & supplier balances, upcoming cheques, low stock, 7-day chart |
| **Users & security** | Unlimited users, custom **roles with 25+ permissions**, deactivate accounts, activity log of every action, login rate-limit, hashed passwords, JWT sessions |
| **Notifications** | In-app bell + optional forwarding to your WhatsApp / email. Checked every 30 min |
| **Backups** | Automatic nightly SQL backup (no `mysqldump` needed), manual backup + download |

### Payment status rules
* `Paid` – cleared payments cover the full invoice
* `Cheque Pending` – a cheque has been taken but is not cleared yet
* `Partially Paid` – some cleared money received, balance remains
* `Pending` – invoice was created on credit (nothing paid yet)
* `Unpaid` – no payment at all

### Profit / loss
* **Estimated profit/loss** = Sales − Expenses (for the period)
* **Cash profit** = Income actually received (cash + bank + *cleared* cheques) − Expenses
* **Gross margin** = Sales − cost price of items sold

## WhatsApp (ecodes.online) & Email

**Settings → WhatsApp**: enter your ecodes.online API key / sender. Because gateway APIs differ, the request is built from an
editable **JSON body template** with placeholders `{{api_key}} {{sender}} {{to}} {{message}} {{media_url}} {{filename}}`,
plus configurable endpoint path, HTTP method and headers – match them to the API documentation in your ecodes.online account
and press **Send test**. Invoices are sent as a message with a secure PDF link (`{{media_url}}` is the same link for gateways that attach files).
If the gateway is off or fails, every WhatsApp button falls back to opening **wa.me** with the message pre-filled, so staff can still send manually.

**Settings → Templates**: edit every message (invoice, payment received, overdue reminder, statement, bounced cheque, admin alerts) or add custom ones.
**Settings → Email**: SMTP host/port/user/password. Invoices are emailed automatically with the PDF attached.
**Settings → Company → Public URL** must be the address customers can reach (e.g. `https://pos.aragroup.lk`) so invoice links work.
Every send is recorded (Settings → Sent log, and on each invoice).

## Roles & permissions
Defaults: Administrator, Manager, Cashier, Store Keeper, Accountant. Create your own in **Users → Roles**, tick exactly what each role may do
(view/create/edit/delete invoices, payments, stock, expenses, reports, settings …). Menu items and API calls are both permission-checked.

## Deployment notes
* Run behind HTTPS (nginx / Hostinger Node.js hosting) and set a strong `JWT_SECRET`.
* Keep the `uploads/` and `backups/` folders on persistent storage.
* `npm start` (or `pm2 start src/server.js --name ara-pos`).
* Smoke test (server running): `node test/smoke.js` – 49 end-to-end checks of stock, split payments, cheques, reports and permissions.

## Folder layout
```
src/            Express API (routes/, services/ for PDF, WhatsApp, email, reminders, backup)
public/         Mobile-first single-page app (no build step)
db/schema.sql   MySQL tables
test/smoke.js   End-to-end API test
```
