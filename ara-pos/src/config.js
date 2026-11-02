require('dotenv').config();
const path = require('path'), fs = require('fs');
process.env.TZ = process.env.TZ || 'Asia/Colombo';
// Uploads & backups live in DATA_DIR (set it outside the app folder on Hostinger so redeploys never delete them)
const dataDir = process.env.DATA_DIR ? path.resolve(process.env.DATA_DIR) : path.join(__dirname, '..');
const dir = n => { const d = path.join(dataDir, n); try { fs.mkdirSync(d, { recursive: true }); } catch (e) { console.warn('Cannot create', d, e.message); } return d; };
module.exports = {
  uploadsDir: dir('uploads'), backupsDir: dir('backups'), dbPool: +process.env.DB_POOL || 5,
  port: +process.env.PORT || 3000,
  db: {
    host: process.env.DB_HOST || 'localhost', port: +process.env.DB_PORT || 3306,
    user: process.env.DB_USER || 'root', password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'ara_pos',
  },
  jwtSecret: process.env.JWT_SECRET || 'dev-secret-change-me',
  publicUrl: (process.env.PUBLIC_URL || 'http://localhost:3000').replace(/\/$/, ''),
  adminUser: process.env.ADMIN_USER || 'admin',
  adminPassword: process.env.ADMIN_PASSWORD || 'admin123',
};
