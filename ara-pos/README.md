# ARA Group – POS & Store Management System

Mobile-app-style web system (PWA) built with **Node.js + Express + MySQL**. Works on phones, tablets and desktops.
On a phone it behaves like an app (bottom tab bar, bottom sheets, add-to-home-screen); on a desktop it becomes a sidebar dashboard.

User manuals (PDF): [Desktop edition](docs/ARA-POS-Desktop-User-Manual.pdf) · [Phone edition](docs/ARA-POS-Phone-User-Manual.pdf)

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

## Responsive design
The same app adapts automatically: **under 1024px** it is a mobile app (bottom tab bar, bottom sheets, floating buttons); **1024px and wider** it is a desktop app (dark sidebar, top bar with user, data tables, wide dashboard, two-panel POS with sticky cart).

## What's included

| Area | Features |
|---|---|
| **Products** | Add / edit / delete. Name, code, category, brand, **supplier (select or add new)**, colour variants, cost / wholesale / selling price, **opening quantity per colour**, low-stock level |
| **GRN / Purchases** | Supplier-wise goods received notes (items, unit costs, due date) → stock goes up; **pay suppliers in cash / bank / cheque, now or later, in parts**; GRN status (Unpaid / Partial / Paid); Supplier Dues page; supplier statements (PDF); edit / delete (stock reversed) |
| **Stock** | Colour + **size** + **location** lines; filter by **ART NO.**, product, category, size, colour, location; complete movement history; Receive via GRN → auto-increase; sales auto-deduct; customer returns, supplier returns, damages, adjustments; stock by product & colour; full movement history; stock value |
| **POS / Invoices** | Mobile POS, automatic invoice numbers (`ARA-2026-00001`), wholesale/retail pricing, line + invoice discounts, edit / delete (stock restored), PDF invoice with ARA branding |
| **Flexible payments** | One invoice can be split across **Cash + Bank + Cheque + Pending** (amounts or %), later payments any date, multiple payments per invoice, auto status: Unpaid / Partially Paid / Pending / Paid |
| **Cheques** | Customer cheques (received → deposited → cleared / bounced) – **counted as paid the moment they are received; only a bounced cheque goes back onto the balance**. Supplier cheques (issued → presented → cleared / returned). Automatic reminders |
| **Pending payments** | Dedicated page with filters (customer, dates, min amount, overdue), payment history, one-tap WhatsApp reminder |
| **Customer deposits** | Advance payments by cash / bank / cheque (or a mix) with receipt numbers; **cheques count at once; only a bounced cheque is taken back**; **overpayments (cash / bank / cheque) are saved automatically as advance**, shown on every invoice as **deposit before / deducted / remaining after** (PDF, page, WhatsApp & email via {{advance_line}}); uncleared cheque deposits are listed separately and never counted; each deduction is recorded in the ledger with balance before and after and auto-deducted on the next invoice; automatic deduction on new invoices (full or partial, mixed with cash / bank / cheque / pending); overpaid balance stays as advance; refunds; full traceable ledger; customer account statement (PDF); Deposit dashboard (advances received, available balances, awaiting clearance, customer-wise history) |
| **Customers** | Per-customer history: totals, pairs bought, week / month / year, invoices, payments, cheques, products, **statements** (PDF + send by WhatsApp / email) |
| **Suppliers** | Material / finished-goods / other suppliers, balances, payments, cheques, goods received |
| **Expenses** | All categories (editable), receipt photo/PDF upload, who created / edited |
| **Advanced filters** | One filter system on Reports, Expenses, Cheques and Stock: every filter can be combined, with daily / weekly / monthly / yearly / custom / all-dates ranges and **PDF + Excel export** of exactly what is on screen (filters and dates are printed on the file) |
| **Expenses** (salaries & materials) | Salary payments per **employee** (total paid, payment dates, full history); material purchases per **item** (rexine, glue, boxes, PU soles …) |
| **Reports** | Daily / weekly / monthly / yearly / custom: Profit & Loss, day-by-day, cash & bank book, sales, products, customers, suppliers, expenses, cheques, stock → **PDF & Excel** |
| **Dashboard** | Today / month sales, income, expenses, profit-loss, stock value, customer & supplier balances, upcoming cheques, low stock, 7-day chart |
| **Users & security** | Unlimited users, custom **roles with 25+ permissions**, deactivate accounts, activity log of every action, login rate-limit, hashed passwords, JWT sessions |
| **Notifications** | In-app bell (checked every 30 min) + **daily WhatsApp / email "cheques today" digest** (customer cheques to deposit, supplier cheques to present, supplier payments due, overdue) at a time you choose; optional instant forwarding |
| **Backups** | Automatic nightly SQL backup (no `mysqldump` needed), manual backup + download |

### Payment status rules
* `Paid` – payments (cash, bank, cheque, deposit) cover the full invoice; a cheque counts as paid when taken, a bounced one is added back
* `Partially Paid` – part received, balance remains
* `Pending` – invoice was created on credit (nothing paid yet)
* `Unpaid` – no payment at all

### Profit
* **PROFIT = selling price (or wholesale price) entered on the sales screen − cost price − discounts** (line discounts + the whole-invoice discount, shared over the items by value).
* **Net profit = Profit − Expenses.** Expenses (salaries, materials, rent …) are shown separately.
* Shown live on the sales screen, on each invoice, on the dashboard, and in **Reports → Profit**: by invoice, by item (every invoice line), by product, by customer, by month, plus Profit & Loss and Day-by-day profit. Cost and profit are hidden from users without report / stock permission.

## WhatsApp (ecodes.online) & Email

**Settings → WhatsApp**: tick *Enable*, paste your ecodes.online **API key**, save, then press **Check status** (shows whether your number is connected and today's usage) and **Send test**.
The system calls `POST https://ecodes.online/api/send-message` with `Authorization: Bearer <key>` and the fields `to`, `text`, and for invoices `documentUrl` + `fileName` (the PDF).
Documented errors are shown in plain words (401 wrong key, 403 blocked, 409 number not connected - rescan the QR, 422 invalid / not on WhatsApp, 429 daily limit).
The PDF is attached only when **Public URL** (Settings → Company) is a real internet address; otherwise the message contains the text only.
If the gateway is off or fails, every WhatsApp button falls back to opening **wa.me** with the message pre-filled.

**Settings → Templates**: edit every message (invoice, payment received, overdue reminder, statement, bounced cheque, admin alerts) or add custom ones.
**Settings → Email**: SMTP host/port/user/password. Invoices are emailed automatically with the PDF attached.
**Settings → Company → Public URL** must be the address customers can reach (e.g. `https://pos.aragroup.lk`) so invoice links work.
Every send is recorded (Settings → Sent log, and on each invoice).

## Roles & permissions
Defaults: Administrator, Manager, Cashier, Store Keeper, Accountant. Create your own in **Users → Roles**, tick exactly what each role may do
(view/create/edit/delete invoices, payments, stock, expenses, reports, settings …). Menu items and API calls are both permission-checked.

## Deployment notes
* **Hostinger:** see [HOSTINGER.md](HOSTINGER.md) (upload `ARA-POS-Hostinger.zip`, set env vars, deploy).
* Run behind HTTPS (nginx / Hostinger Node.js hosting) and set a strong `JWT_SECRET`.
* Keep the `uploads/` and `backups/` folders on persistent storage.
* `npm start` (or `pm2 start server.js --name ara-pos`). Set `DATA_DIR` to keep uploads/backups outside the app folder.
* Smoke test (server running): `node test/smoke.js` – 180 end-to-end checks of stock, split payments, cheques, reports and permissions.

## Folder layout
```
src/            Express API (routes/, services/ for PDF, WhatsApp, email, reminders, backup)
public/         Mobile-first single-page app (no build step)
db/schema.sql   MySQL tables
test/smoke.js   End-to-end API test
```


## Company logo & invoice design
Settings → Company: upload / remove the logo (PNG or JPG, max 1.5 MB) and fill name, tagline, address, phone, email, website, registration no. and bank details. They are printed on the navy-style invoice PDF, statements and reports; the logo also shows in the menu and on the login screen.
