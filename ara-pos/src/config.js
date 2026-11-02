require('dotenv').config();
process.env.TZ = process.env.TZ || 'Asia/Colombo';
module.exports = {
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
