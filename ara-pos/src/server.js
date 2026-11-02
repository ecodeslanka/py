const express = require('express');
const path = require('path');
const cron = require('node-cron');
const cfg = require('./config');
const setup = require('./setup');
const { HttpError, wrap } = require('./util');
const M = require('./services/messaging');
const { invoicePdf, tablePdf } = require('./services/pdf');
const backup = require('./services/backup');
const reminders = require('./services/reminders');
const { settings } = require('./util');

const app = express();
app.set('trust proxy', 1);
app.use(express.json({ limit: '2mb' }));

// simple login rate-limit (per IP)
const fails = new Map();
app.use('/api/auth/login', (req, res, next) => {
  const k = req.ip, e = fails.get(k) || { n: 0, t: Date.now() };
  if (Date.now() - e.t > 15 * 60e3) { e.n = 0; e.t = Date.now(); }
  if (e.n >= 10) return res.status(429).json({ error: 'Too many attempts. Try again in 15 minutes.' });
  res.on('finish', () => { if (res.statusCode === 401) { e.n++; fails.set(k, e); } else if (res.statusCode === 200) fails.delete(k); }); next();
});

// public signed links (sent to customers on WhatsApp / email)
app.get('/p/invoice/:id/:sig.pdf', wrap(async (req, res) => {
  if (!M.verifySig('invoice', req.params.id, req.params.sig)) return res.status(404).send('Not found');
  const b = await invoicePdf(+req.params.id); if (!b) return res.status(404).send('Not found');
  res.type('pdf').set('Content-Disposition', 'inline; filename="invoice.pdf"').send(b);
}));
const salesRouter = require('./routes/sales');
app.get('/p/statement/:id/:sig.pdf', wrap(async (req, res) => {
  if (!M.verifySig('statement', req.params.id, req.params.sig)) return res.status(404).send('Not found');
  res.type('pdf').send(await salesRouter.statementPdf(+req.params.id, req.query.from || undefined, req.query.to || undefined));
}));
app.use('/api', require('./routes/admin'));
app.use('/api', require('./routes/inventory'));
app.use('/api', salesRouter);
app.use('/api', require('./routes/finance'));
app.use('/api', (req, res) => res.status(404).json({ error: 'Not found' }));

app.use(express.static(path.join(__dirname, '../public'), { extensions: ['html'] }));
app.get('*', (req, res) => res.sendFile(path.join(__dirname, '../public/index.html')));

app.use((err, req, res, next) => {
  if (err instanceof HttpError) return res.status(err.status).json({ error: err.message });
  if (err.code === 'ER_DUP_ENTRY') return res.status(400).json({ error: 'Duplicate entry – that value already exists' });
  if (err.code === 'ER_ROW_IS_REFERENCED_2') return res.status(400).json({ error: 'This record is used elsewhere and cannot be deleted' });
  if (err.name === 'MulterError' || /Only images/.test(err.message)) return res.status(400).json({ error: err.message });
  console.error(err); res.status(500).json({ error: 'Server error: ' + err.message });
});

if (cfg.jwtSecret.startsWith('dev-secret') || cfg.jwtSecret.startsWith('change-this')) console.warn('⚠  Set a long random JWT_SECRET in .env before going live!');
setup().then(() => {
  app.listen(cfg.port, () => console.log(`ARA POS running on http://localhost:${cfg.port}`));
  cron.schedule('*/30 * * * *', () => reminders.scan().catch(e => console.error('reminder', e.message)));
  cron.schedule('0 2 * * *', async () => { if ((await settings()).backup_enabled === '1') backup.createBackup().then(n => console.log('Backup', n)).catch(e => console.error(e)); });
  setTimeout(() => reminders.scan().catch(() => { }), 3000);
}).catch(e => { console.error('Startup failed:', e.message); process.exit(1); });
