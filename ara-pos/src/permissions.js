// Every permission key, grouped for the role editor UI.
const GROUPS = [
  ['Dashboard', { 'dashboard.view': 'View dashboard' }],
  ['Sales & Invoices', { 'invoices.view': 'View invoices', 'invoices.create': 'Create invoices (POS)', 'invoices.edit': 'Edit invoices', 'invoices.delete': 'Delete invoices', 'payments.manage': 'Record / edit payments', 'invoices.send': 'Send invoices (email / WhatsApp)' }],
  ['Customers', { 'customers.view': 'View customers & statements', 'customers.manage': 'Add / edit / delete customers' }],
  ['Customer Deposits', { 'deposits.view': 'View customer deposits, advance balances & ledger', 'deposits.manage': 'Record deposits, refunds & delete deposits' }],
  ['Inventory', { 'products.view': 'View products & stock', 'products.manage': 'Add / edit / delete products', 'stock.manage': 'Receive goods, returns, damages, adjustments' }],
  ['Suppliers', { 'suppliers.view': 'View suppliers', 'suppliers.manage': 'Add / edit / delete suppliers & payments' }],
  ['Cheques', { 'cheques.view': 'View cheques', 'cheques.manage': 'Add / update cheques' }],
  ['Expenses', { 'expenses.view': 'View expenses', 'expenses.manage': 'Add / edit / delete expenses' }],
  ['Reports', { 'reports.view': 'View & export financial reports' }],
  ['Administration', { 'users.manage': 'Manage users', 'roles.manage': 'Manage roles & permissions', 'settings.manage': 'System, WhatsApp, email & templates', 'logs.view': 'View activity log', 'backup.manage': 'Create / download backups' }],
];
const ALL = GROUPS.flatMap(([, p]) => Object.keys(p));
const pick = (...ks) => ks.flatMap(k => k.endsWith('*') ? ALL.filter(a => a.startsWith(k.slice(0, -1))) : [k]);
const DEFAULT_ROLES = [
  { name: 'Administrator', description: 'Full access to everything', permissions: ALL, is_system: 1 },
  { name: 'Manager', description: 'Everything except users, roles & settings', permissions: ALL.filter(p => !['users.manage', 'roles.manage', 'settings.manage', 'backup.manage'].includes(p)) },
  { name: 'Cashier', description: 'POS sales and customer payments', permissions: pick('dashboard.view', 'invoices.view', 'invoices.create', 'invoices.send', 'payments.manage', 'customers.view', 'customers.manage', 'deposits.*', 'products.view', 'cheques.view') },
  { name: 'Store Keeper', description: 'Inventory and stock only', permissions: pick('products.*', 'stock.manage', 'suppliers.view') },
  { name: 'Accountant', description: 'Payments, cheques, expenses & reports', permissions: pick('dashboard.view', 'invoices.view', 'payments.manage', 'customers.view', 'deposits.*', 'suppliers.*', 'cheques.*', 'expenses.*', 'reports.view', 'products.view') },
];
module.exports = { GROUPS, ALL, DEFAULT_ROLES };
