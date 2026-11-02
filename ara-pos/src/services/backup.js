// Portable SQL backup (no mysqldump needed) + retention
const fs = require('fs'), path = require('path');
const { q } = require('../db');
const { settings, fmtDate } = require('../util');
const DIR = path.join(__dirname, '../../backups');

const esc = v => v === null || v === undefined ? 'NULL' : typeof v === 'number' ? String(v) : "'" + String(v).replace(/[\\']/g, m => '\\' + m).replace(/\n/g, '\\n').replace(/\r/g, '\\r') + "'";
async function createBackup() {
  fs.mkdirSync(DIR, { recursive: true });
  const name = `ara_pos_${fmtDate(new Date())}_${new Date().toTimeString().slice(0, 8).replace(/:/g, '')}.sql`;
  const out = fs.createWriteStream(path.join(DIR, name));
  const w = s => new Promise(r => out.write(s, r));
  await w('-- ARA POS backup ' + new Date().toISOString() + '\nSET FOREIGN_KEY_CHECKS=0;\n');
  const tables = (await q('SHOW TABLES')).map(r => Object.values(r)[0]);
  for (const t of tables) {
    const c = (await q(`SHOW CREATE TABLE \`${t}\``))[0]['Create Table'];
    await w(`DROP TABLE IF EXISTS \`${t}\`;\n${c};\n`);
    const rows = await q(`SELECT * FROM \`${t}\``);
    for (let i = 0; i < rows.length; i += 200) {
      const chunk = rows.slice(i, i + 200), cols = Object.keys(chunk[0]).map(k => `\`${k}\``).join(',');
      await w(`INSERT INTO \`${t}\` (${cols}) VALUES\n` + chunk.map(r => '(' + Object.values(r).map(esc).join(',') + ')').join(',\n') + ';\n');
    }
  }
  await w('SET FOREIGN_KEY_CHECKS=1;\n'); await new Promise(r => out.end(r));
  const s = await settings(), keep = +s.backup_keep || 14;
  fs.readdirSync(DIR).filter(f => f.endsWith('.sql')).sort().reverse().slice(keep).forEach(f => fs.unlinkSync(path.join(DIR, f)));
  return name;
}
const listBackups = () => { fs.mkdirSync(DIR, { recursive: true }); return fs.readdirSync(DIR).filter(f => f.endsWith('.sql')).sort().reverse().map(f => ({ name: f, size: fs.statSync(path.join(DIR, f)).size })); };
module.exports = { createBackup, listBackups, DIR };
