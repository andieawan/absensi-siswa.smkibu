// ============================================================================
// Backup Otomatis Database SQLite
// ============================================================================
// Sejauh ini `school_settings.backup_retention_weeks` / `last_backup_date` /
// `last_backup_status` sudah ada di skema & tipe data, tapi tidak ada apapun
// yang benar-benar mengisinya — kolom itu hanya "janji" tanpa implementasi.
// Modul ini yang mengisinya: menyalin database ke file snapshot terpisah
// secara berkala, menghapus snapshot yang lebih tua dari masa retensi yang
// dikonfigurasi Administrator, dan mencatat hasilnya ke school_settings agar
// tampil di UI kapan pun nanti dibutuhkan.
//
// Pakai `VACUUM INTO` (bukan sekadar fs.copyFile file .sqlite3) karena aman
// dipanggil sambil database masih dipakai (WAL mode) dan hasilnya adalah
// satu file utuh yang konsisten, bukan potongan WAL yang belum di-checkpoint.

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { db, Repo } from './db';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export const BACKUP_DIR = process.env.BACKUP_DIR || path.resolve(__dirname, '..', 'data', 'backups');

function ensureBackupDir() {
  if (!fs.existsSync(BACKUP_DIR)) {
    fs.mkdirSync(BACKUP_DIR, { recursive: true });
  }
}

function pruneOldBackups(retentionWeeks: number) {
  const cutoff = Date.now() - Math.max(1, retentionWeeks) * 7 * 24 * 3600 * 1000;
  let files: string[] = [];
  try {
    files = fs.readdirSync(BACKUP_DIR).filter((f) => f.startsWith('absensi_') && f.endsWith('.sqlite3'));
  } catch {
    return;
  }
  for (const f of files) {
    const full = path.join(BACKUP_DIR, f);
    try {
      const stat = fs.statSync(full);
      if (stat.mtimeMs < cutoff) {
        fs.unlinkSync(full);
      }
    } catch {
      // Lewati file yang gagal dibaca/dihapus, jangan sampai menggagalkan backup lain
    }
  }
}

/**
 * Jalankan satu siklus backup: buat snapshot baru, hapus snapshot lama di
 * luar masa retensi, lalu catat hasilnya ke school_settings.
 */
export function runBackup(): { success: boolean; file?: string; error?: string } {
  try {
    ensureBackupDir();
    const stamp = new Date().toISOString().replace(/[:.]/g, '-').substring(0, 19);
    const file = path.join(BACKUP_DIR, `absensi_${stamp}.sqlite3`);
    // Escape tanda kutip tunggal untuk literal path di pernyataan SQL VACUUM INTO.
    const escapedFile = file.replace(/'/g, "''");
    db.exec(`VACUUM INTO '${escapedFile}'`);

    const settings = Repo.settings.get();
    pruneOldBackups(Number(settings.backup_retention_weeks) || 8);

    const nowIso = new Date().toISOString().replace('T', ' ').substring(0, 19);
    Repo.settings.update({ ...settings, last_backup_date: nowIso, last_backup_status: 'success' });

    console.log(`[Backup] Snapshot database berhasil dibuat: ${file}`);
    return { success: true, file };
  } catch (err: any) {
    console.error('[Backup] Gagal membuat snapshot database:', err);
    try {
      const settings = Repo.settings.get();
      Repo.settings.update({ ...settings, last_backup_status: 'failed' });
    } catch {
      // Kalau update status pun gagal, biarkan — error asli tetap dikembalikan di bawah
    }
    return { success: false, error: err?.message || 'Gagal membuat backup database.' };
  }
}

/**
 * Jadwalkan backup otomatis: sekali segera setelah server siap (delay singkat
 * agar tidak bersaing dengan proses startup), lalu berulang tiap N jam.
 */
export function scheduleAutomaticBackups(intervalHours = 24) {
  setTimeout(() => runBackup(), 15_000).unref();
  setInterval(() => runBackup(), Math.max(1, intervalHours) * 3600 * 1000).unref();
}
