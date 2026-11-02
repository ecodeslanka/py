const mysql = require('mysql2/promise');
const cfg = require('./config');
const pool = mysql.createPool({
  ...cfg.db, waitForConnections: true, connectionLimit: 10,
  decimalNumbers: true, dateStrings: true, multipleStatements: false, charset: 'utf8mb4',
});
const q = async (sql, params = [], conn) => (await (conn || pool).query(sql, params))[0];
const one = async (sql, params, conn) => (await q(sql, params, conn))[0] || null;
async function tx(fn) {
  const c = await pool.getConnection();
  try { await c.beginTransaction(); const r = await fn(c); await c.commit(); return r; }
  catch (e) { await c.rollback(); throw e; } finally { c.release(); }
}
module.exports = { pool, q, one, tx };
