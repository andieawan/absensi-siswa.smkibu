// ============================================================================
// Lapisan Persistensi — mendukung SQLite (default) ATAU MySQL, dipilih lewat
// environment variable DB_DRIVER.
// ============================================================================
// DB_DRIVER=sqlite (default): pakai modul bawaan Node.js `node:sqlite`
// (DatabaseSync), tidak butuh dependency eksternal, data tersimpan di satu
// file lokal (DB_PATH). Cocok untuk server fisik sekolah single-instance.
//
// DB_DRIVER=mysql: pakai server MySQL/MariaDB sungguhan lewat mysql2. Cocok
// kalau sekolah sudah punya server MySQL sendiri, atau butuh multi-instance /
// akses dari luar server aplikasi. Kredensial diatur lewat MYSQL_HOST,
// MYSQL_PORT, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE (lihat .env.example).
//
// CATATAN TEKNIS: mysql2 murni asinkron (tidak ada driver MySQL sinkron resmi
// untuk Node.js), sedangkan seluruh `server.ts` (80+ titik panggilan `Repo.*`)
// ditulis dengan asumsi API SINKRON seperti `node:sqlite`. Supaya server.ts
// TIDAK perlu ditulis ulang jadi async di semua titik itu (risiko regresi
// besar untuk aplikasi yang sudah dipakai), query MySQL dijalankan di worker
// thread terpisah (server/mysqlWorker.ts) dan dipanggil dari sini secara
// SINKRON lewat `synckit` (blocking via Atomics.wait, bukan spin-loop). Dari
// sudut pandang Repo & server.ts, kedua driver terasa identik: sama-sama
// panggilan sinkron biasa.
//
// Kalau di-deploy ke platform dengan filesystem ephemeral (mis. Cloud Run
// tanpa mounted volume) dan tetap memilih SQLite, file database akan hilang
// setiap kali instance di-recycle — pakai MySQL terkelola atau persistent
// volume untuk deployment produksi multi-instance.

import { DatabaseSync } from 'node:sqlite';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';
import { createHash, randomBytes } from 'node:crypto';
import { createSyncFn } from 'synckit';
import {
  INITIAL_CLASSES,
  INITIAL_STUDENTS,
  INITIAL_DELEGATION_TOKENS,
  INITIAL_ATTENDANCE,
  INITIAL_USERS,
  INITIAL_PAIRINGS,
  INITIAL_SUBJECTS,
  INITIAL_GRADE_ACTIVITIES,
  INITIAL_GRADE_VALUES,
  INITIAL_SCHOOL_SETTINGS,
} from '../src/data/mockData';
import {
  AttendanceRecord,
  Student,
  ClassItem,
  Subject,
  User,
  TeacherPairing,
  GradeActivity,
  GradeValue,
  KetuaKelasToken,
  SchoolSettings,
  ParentAccessToken,
} from '../src/types';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export type DbDriverKind = 'sqlite' | 'mysql';
export const DB_DRIVER: DbDriverKind =
  (process.env.DB_DRIVER || 'sqlite').trim().toLowerCase() === 'mysql' ? 'mysql' : 'sqlite';

// ============================================================================
// Driver: SQLite (node:sqlite) — dibuat hanya kalau memang dipilih, supaya
// tidak membuat file database SQLite yang tidak terpakai saat DB_DRIVER=mysql.
// ============================================================================
const DB_PATH = process.env.DB_PATH || path.resolve(__dirname, '..', 'data', 'absensi.sqlite3');

export let db: DatabaseSync | null = null;

if (DB_DRIVER === 'sqlite') {
  const dataDir = path.dirname(DB_PATH);
  if (!fs.existsSync(dataDir)) {
    fs.mkdirSync(dataDir, { recursive: true });
  }
  db = new DatabaseSync(DB_PATH);
  db.exec('PRAGMA journal_mode = WAL;');
  db.exec('PRAGMA foreign_keys = ON;');
}

// ============================================================================
// Driver: MySQL — query dijalankan di worker thread lewat synckit (lihat
// header komentar di atas & server/mysqlWorker.ts untuk alasannya).
// ============================================================================
type MysqlWorkerFn = (op: 'run' | 'get' | 'all' | 'exec', sql: string, params: any[]) => {
  rows?: any[];
  insertId?: number;
  affectedRows?: number;
};

let mysqlQuery: MysqlWorkerFn | null = null;
if (DB_DRIVER === 'mysql') {
  const workerPath = path.resolve(__dirname, 'mysqlWorker.ts');
  mysqlQuery = createSyncFn<MysqlWorkerFn>(workerPath, { timeout: 20_000 });
}

// ============================================================================
// Fasad query generik: satu set fungsi yang dipakai Repo di bawah, dispatch
// ke driver yang aktif. Ini satu-satunya tempat yang "tahu" perbedaan sqlite
// vs mysql di level eksekusi query — sisa file ini (Repo, mapper, dst) murni
// SQL portable & tidak peduli driver mana yang aktif.
// ============================================================================
function dbRun(sql: string, params: any[] = []): { lastInsertRowid: number; changes: number } {
  if (DB_DRIVER === 'mysql') {
    const r = mysqlQuery!('run', sql, params);
    return { lastInsertRowid: Number(r.insertId || 0), changes: Number(r.affectedRows || 0) };
  }
  const info = db!.prepare(sql).run(...params);
  return { lastInsertRowid: Number(info.lastInsertRowid || 0), changes: Number(info.changes || 0) };
}

function dbGet<T = any>(sql: string, params: any[] = []): T | undefined {
  if (DB_DRIVER === 'mysql') {
    const rows = mysqlQuery!('all', sql, params).rows || [];
    return (rows[0] as T) ?? undefined;
  }
  return db!.prepare(sql).get(...params) as T | undefined;
}

function dbAll<T = any>(sql: string, params: any[] = []): T[] {
  if (DB_DRIVER === 'mysql') {
    return (mysqlQuery!('all', sql, params).rows || []) as T[];
  }
  return db!.prepare(sql).all(...params) as T[];
}

/** Statement DDL tunggal tanpa parameter (schema, PRAGMA-independent). */
function dbExec(sql: string): void {
  if (DB_DRIVER === 'mysql') {
    mysqlQuery!('exec', sql, []);
    return;
  }
  db!.exec(sql);
}

/** Pecah blok DDL multi-statement jadi statement individual untuk driver MySQL. */
function splitStatements(sql: string): string[] {
  return sql
    .split(';')
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
}

// ============================================================================
// Skema Tabel — dua varian: SQLite (asli) & MySQL (tipe kolom disesuaikan:
// TEXT yang jadi PRIMARY KEY/UNIQUE wajib VARCHAR bagi InnoDB, AUTOINCREMENT
// jadi AUTO_INCREMENT, dst). Keduanya secara LOGIS identik.
// ============================================================================
const SCHEMA_SQLITE = `
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY,
  username TEXT UNIQUE NOT NULL,
  password_hash TEXT,
  nama TEXT NOT NULL,
  kelas_wali_id INTEGER,
  foto_profil_url TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  roles TEXT NOT NULL DEFAULT '[]',
  subjects TEXT NOT NULL DEFAULT '[]',
  classes TEXT NOT NULL DEFAULT '[]'
);

CREATE TABLE IF NOT EXISTS classes (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  jurusan TEXT,
  angkatan TEXT,
  tahun_ajaran TEXT,
  semester TEXT
);

CREATE TABLE IF NOT EXISTS subjects (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS students (
  id INTEGER PRIMARY KEY,
  nis TEXT NOT NULL,
  nama TEXT NOT NULL,
  jk TEXT NOT NULL,
  class_id INTEGER NOT NULL,
  status TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS attendance (
  id INTEGER PRIMARY KEY,
  student_id INTEGER NOT NULL,
  class_id INTEGER NOT NULL,
  subject_id INTEGER,
  tanggal TEXT NOT NULL,
  status TEXT NOT NULL,
  recorded_by INTEGER,
  recorded_via TEXT,
  notes TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  created_at_millis INTEGER,
  UNIQUE(student_id, subject_id, tanggal)
);

CREATE TABLE IF NOT EXISTS grade_activities (
  id TEXT PRIMARY KEY,
  teacher_id INTEGER NOT NULL,
  subject_id INTEGER NOT NULL,
  class_id INTEGER NOT NULL,
  nama_kegiatan TEXT NOT NULL,
  tanggal_kegiatan TEXT,
  tipe_skala TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS grade_values (
  activity_id TEXT NOT NULL,
  student_id INTEGER NOT NULL,
  nilai TEXT NOT NULL,
  PRIMARY KEY (activity_id, student_id)
);

CREATE TABLE IF NOT EXISTS teacher_subject_class_pairing (
  user_id INTEGER NOT NULL,
  subject_id INTEGER NOT NULL,
  class_id INTEGER NOT NULL,
  PRIMARY KEY (user_id, subject_id, class_id)
);

CREATE TABLE IF NOT EXISTS ketua_kelas_tokens (
  token TEXT PRIMARY KEY,
  class_id INTEGER NOT NULL,
  status TEXT NOT NULL,
  created_at TEXT NOT NULL,
  created_by INTEGER,
  expires_at TEXT,
  expires_at_millis INTEGER
);

CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  timestamp TEXT NOT NULL,
  action TEXT NOT NULL,
  module TEXT NOT NULL,
  actor TEXT,
  details TEXT
);

CREATE TABLE IF NOT EXISTS sequences (
  entity TEXT PRIMARY KEY,
  value INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS school_settings (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  school_name TEXT,
  logo_url TEXT,
  tahun_ajaran TEXT,
  semester TEXT,
  kepsek_nama TEXT,
  bk_nama TEXT,
  backup_retention_weeks INTEGER,
  last_backup_date TEXT,
  last_backup_status TEXT
);

CREATE TABLE IF NOT EXISTS sessions (
  token TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  expires_at_millis INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS parent_access_tokens (
  token TEXT PRIMARY KEY,
  student_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'aktif',
  created_at TEXT NOT NULL,
  created_by INTEGER,
  revoked_at TEXT
);
`;

const SCHEMA_MYSQL = `
CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY,
  username VARCHAR(191) UNIQUE NOT NULL,
  password_hash VARCHAR(255),
  nama VARCHAR(191) NOT NULL,
  kelas_wali_id INT,
  foto_profil_url VARCHAR(500),
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at VARCHAR(32) NOT NULL,
  roles TEXT NOT NULL,
  subjects TEXT NOT NULL,
  classes TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS classes (
  id INT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  jurusan VARCHAR(191),
  angkatan VARCHAR(32),
  tahun_ajaran VARCHAR(32),
  semester VARCHAR(32)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subjects (
  id INT PRIMARY KEY,
  name VARCHAR(191) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS students (
  id INT PRIMARY KEY,
  nis VARCHAR(64) NOT NULL,
  nama VARCHAR(191) NOT NULL,
  jk VARCHAR(4) NOT NULL,
  class_id INT NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
  id INT PRIMARY KEY,
  student_id INT NOT NULL,
  class_id INT NOT NULL,
  subject_id INT NULL,
  tanggal VARCHAR(10) NOT NULL,
  status VARCHAR(4) NOT NULL,
  recorded_by INT,
  recorded_via VARCHAR(64),
  notes TEXT,
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  created_at_millis BIGINT,
  UNIQUE KEY uniq_attendance (student_id, subject_id, tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grade_activities (
  id VARCHAR(64) PRIMARY KEY,
  teacher_id INT NOT NULL,
  subject_id INT NOT NULL,
  class_id INT NOT NULL,
  nama_kegiatan VARCHAR(191) NOT NULL,
  tanggal_kegiatan VARCHAR(10),
  tipe_skala VARCHAR(16) NOT NULL,
  created_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grade_values (
  activity_id VARCHAR(64) NOT NULL,
  student_id INT NOT NULL,
  nilai VARCHAR(16) NOT NULL,
  PRIMARY KEY (activity_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teacher_subject_class_pairing (
  user_id INT NOT NULL,
  subject_id INT NOT NULL,
  class_id INT NOT NULL,
  PRIMARY KEY (user_id, subject_id, class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ketua_kelas_tokens (
  token VARCHAR(128) PRIMARY KEY,
  class_id INT NOT NULL,
  status VARCHAR(16) NOT NULL,
  created_at VARCHAR(32) NOT NULL,
  created_by INT,
  expires_at VARCHAR(32),
  expires_at_millis BIGINT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  timestamp VARCHAR(32) NOT NULL,
  action VARCHAR(191) NOT NULL,
  module VARCHAR(64) NOT NULL,
  actor VARCHAR(191),
  details TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sequences (
  entity VARCHAR(64) PRIMARY KEY,
  value INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS school_settings (
  id INT PRIMARY KEY,
  school_name VARCHAR(191),
  logo_url VARCHAR(500),
  tahun_ajaran VARCHAR(32),
  semester VARCHAR(32),
  kepsek_nama VARCHAR(191),
  bk_nama VARCHAR(191),
  backup_retention_weeks INT,
  last_backup_date VARCHAR(32),
  last_backup_status VARCHAR(16)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
  token VARCHAR(128) PRIMARY KEY,
  user_id INT NOT NULL,
  created_at VARCHAR(32) NOT NULL,
  expires_at_millis BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS parent_access_tokens (
  token VARCHAR(128) PRIMARY KEY,
  student_id INT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'aktif',
  created_at VARCHAR(32) NOT NULL,
  created_by INT,
  revoked_at VARCHAR(32)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
`;

function initSchema() {
  if (DB_DRIVER === 'mysql') {
    for (const stmt of splitStatements(SCHEMA_MYSQL)) dbExec(stmt);
  } else {
    dbExec(SCHEMA_SQLITE);
  }
}
initSchema();

// ============================================================================
// Seeding: hanya dijalankan sekali, saat tabel users masih kosong
// ============================================================================
function seedIfEmpty() {
  const countRow = dbGet<{ c: number }>('SELECT COUNT(*) as c FROM users');
  if ((countRow?.c || 0) > 0) return;

  for (const u of INITIAL_USERS as User[]) {
    dbRun(
      `INSERT INTO users (id, username, password_hash, nama, kelas_wali_id, foto_profil_url, is_active, created_at, roles, subjects, classes)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        u.id,
        u.username,
        u.password_hash ?? null,
        u.nama,
        u.kelas_wali_id ?? null,
        u.foto_profil_url ?? null,
        u.is_active ? 1 : 0,
        u.created_at,
        JSON.stringify(u.roles || []),
        JSON.stringify(u.subjects || []),
        JSON.stringify(u.classes || []),
      ]
    );
  }

  for (const c of INITIAL_CLASSES as ClassItem[]) {
    dbRun(`INSERT INTO classes (id, name, jurusan, angkatan, tahun_ajaran, semester) VALUES (?, ?, ?, ?, ?, ?)`, [
      c.id,
      c.name,
      c.jurusan,
      c.angkatan,
      c.tahun_ajaran,
      c.semester,
    ]);
  }

  for (const s of INITIAL_SUBJECTS as Subject[]) {
    dbRun(`INSERT INTO subjects (id, name) VALUES (?, ?)`, [s.id, s.name]);
  }

  for (const s of INITIAL_STUDENTS as Student[]) {
    dbRun(`INSERT INTO students (id, nis, nama, jk, class_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)`, [
      s.id,
      s.nis,
      s.nama,
      s.jk,
      s.class_id,
      s.status,
      s.created_at,
    ]);
  }

  for (const a of INITIAL_ATTENDANCE as AttendanceRecord[]) {
    dbRun(
      `INSERT INTO attendance (id, student_id, class_id, subject_id, tanggal, status, recorded_by, recorded_via, notes, created_at, updated_at, created_at_millis)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        a.id,
        a.student_id,
        a.class_id,
        a.subject_id ?? null,
        a.tanggal,
        a.status,
        a.recorded_by ?? null,
        a.recorded_via ?? null,
        a.notes ?? null,
        a.created_at,
        a.updated_at,
        a.created_at_millis ?? null,
      ]
    );
  }

  for (const a of INITIAL_GRADE_ACTIVITIES as GradeActivity[]) {
    dbRun(
      `INSERT INTO grade_activities (id, teacher_id, subject_id, class_id, nama_kegiatan, tanggal_kegiatan, tipe_skala, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
      [a.id, a.teacher_id, a.subject_id, a.class_id, a.nama_kegiatan, a.tanggal_kegiatan, a.tipe_skala, a.created_at]
    );
  }

  for (const g of INITIAL_GRADE_VALUES as GradeValue[]) {
    dbRun(`INSERT INTO grade_values (activity_id, student_id, nilai) VALUES (?, ?, ?)`, [
      g.activity_id,
      g.student_id,
      g.nilai,
    ]);
  }

  for (const p of INITIAL_PAIRINGS as TeacherPairing[]) {
    dbRun(`INSERT INTO teacher_subject_class_pairing (user_id, subject_id, class_id) VALUES (?, ?, ?)`, [
      p.user_id,
      p.subject_id,
      p.class_id,
    ]);
  }

  for (const t of INITIAL_DELEGATION_TOKENS as KetuaKelasToken[]) {
    const expiresAt = t.expires_at || new Date(Date.now() + 7 * 24 * 3600 * 1000).toISOString();
    const expiresAtMillis = t.expires_at_millis || new Date(expiresAt).getTime();
    dbRun(
      `INSERT INTO ketua_kelas_tokens (token, class_id, status, created_at, created_by, expires_at, expires_at_millis)
       VALUES (?, ?, ?, ?, ?, ?, ?)`,
      [t.token, t.class_id, t.status, t.created_at, t.created_by, expiresAt, expiresAtMillis]
    );
  }

  const seqRows: Record<string, number> = {
    users: Math.max(10, ...(INITIAL_USERS as User[]).map((u) => u.id)),
    students: Math.max(100, ...(INITIAL_STUDENTS as Student[]).map((s) => s.id)),
    attendance: Math.max(1000, ...(INITIAL_ATTENDANCE as AttendanceRecord[]).map((a) => a.id)),
    classes: Math.max(10, ...(INITIAL_CLASSES as ClassItem[]).map((c) => c.id)),
    subjects: Math.max(10, ...(INITIAL_SUBJECTS as Subject[]).map((s) => s.id)),
    audit_logs: 100,
  };
  for (const [entity, value] of Object.entries(seqRows)) {
    dbRun(`INSERT INTO sequences (entity, value) VALUES (?, ?)`, [entity, value]);
  }

  const s = INITIAL_SCHOOL_SETTINGS as SchoolSettings;
  dbRun(
    `INSERT INTO school_settings (id, school_name, logo_url, tahun_ajaran, semester, kepsek_nama, bk_nama, backup_retention_weeks, last_backup_date, last_backup_status)
     VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      s.school_name,
      s.logo_url,
      s.tahun_ajaran,
      s.semester,
      s.kepsek_nama,
      s.bk_nama,
      s.backup_retention_weeks,
      s.last_backup_date ?? null,
      s.last_backup_status ?? null,
    ]
  );
}

/**
 * Produksi: TIDAK memuat data demo (akun demo punya password yang tertulis di
 * source code publik). Akun Administrator pertama dibuat dari variabel
 * environment ADMIN_USERNAME + ADMIN_PASSWORD saat tabel users masih kosong.
 */
function bootstrapProductionAdmin() {
  const countRow = dbGet<{ c: number }>('SELECT COUNT(*) as c FROM users');
  if ((countRow?.c || 0) > 0) return;

  const username = (process.env.ADMIN_USERNAME || '').trim().toLowerCase();
  const password = process.env.ADMIN_PASSWORD || '';
  if (!username || password.length < 10) {
    console.warn(
      '[DB] Database kosong dan data demo dinonaktifkan (NODE_ENV=production). ' +
        'Set ADMIN_USERNAME dan ADMIN_PASSWORD (min. 10 karakter) lalu restart server untuk membuat akun Administrator pertama.'
    );
    return;
  }

  // Format hash sama dengan src/services/auth.ts: "sha256:<salt>:<sha256(salt:password)>"
  const salt = randomBytes(16).toString('hex');
  const hash = createHash('sha256').update(`${salt}:${password}`).digest('hex');
  dbRun(
    `INSERT INTO users (id, username, password_hash, nama, kelas_wali_id, foto_profil_url, is_active, created_at, roles, subjects, classes)
     VALUES (1, ?, ?, ?, NULL, NULL, 1, ?, ?, '[]', '[]')`,
    [
      username,
      `sha256:${salt}:${hash}`,
      process.env.ADMIN_NAMA || 'Administrator',
      new Date().toISOString().replace('T', ' ').substring(0, 19),
      JSON.stringify(['superadmin', 'admin']),
    ]
  );
  dbRun(`INSERT INTO sequences (entity, value) VALUES ('users', 10)`);
  console.log(`[DB] Akun Administrator pertama "${username}" dibuat dari environment. Hapus ADMIN_PASSWORD dari environment setelah login pertama.`);
}

// Data demo hanya dimuat di luar produksi, atau jika diminta eksplisit lewat SEED_DEMO_DATA=true.
const shouldSeedDemo = process.env.SEED_DEMO_DATA === 'true' || process.env.NODE_ENV !== 'production';
if (shouldSeedDemo) {
  seedIfEmpty();
} else {
  bootstrapProductionAdmin();
}

// ============================================================================
// Helper: alokasi ID atomik (Single Source of Truth) via tabel `sequences`
// ============================================================================
export function allocateSequence(entity: string, count = 1): number[] {
  const row = dbGet<{ value: number }>('SELECT value FROM sequences WHERE entity = ?', [entity]);
  const current = row ? row.value : 1000;
  const startId = current + 1;
  const next = current + count;

  if (row) {
    dbRun('UPDATE sequences SET value = ? WHERE entity = ?', [next, entity]);
  } else {
    dbRun('INSERT INTO sequences (entity, value) VALUES (?, ?)', [next, entity]);
  }

  const allocated: number[] = [];
  for (let i = startId; i <= next; i++) allocated.push(i);
  return allocated;
}

export function getSequencesStatus(): Record<string, number> {
  const rows = dbAll<{ entity: string; value: number }>('SELECT entity, value FROM sequences');
  const out: Record<string, number> = {};
  for (const r of rows) out[r.entity] = r.value;
  return out;
}

export function recordAudit(action: string, module: string, actor: string, details: string) {
  dbRun(`INSERT INTO audit_log (timestamp, action, module, actor, details) VALUES (?, ?, ?, ?, ?)`, [
    new Date().toISOString(),
    action,
    module,
    actor,
    details,
  ]);
  // Batasi retensi log agar tabel tidak tumbuh tanpa batas. Ditulis lewat
  // derived table (bukan langsung "... LIMIT ... " di dalam subquery IN/NOT
  // IN) karena MySQL tidak mendukung LIMIT langsung di situ — bentuk ini
  // portable untuk SQLite maupun MySQL.
  dbRun(`
    DELETE FROM audit_log WHERE id NOT IN (
      SELECT id FROM (SELECT id FROM audit_log ORDER BY id DESC LIMIT 500) AS keep_ids
    )
  `);
}

// ============================================================================
// Row <-> Domain object mappers
// ============================================================================
function rowToUser(row: any): User {
  return {
    id: row.id,
    username: row.username,
    password_hash: row.password_hash ?? undefined,
    nama: row.nama,
    kelas_wali_id: row.kelas_wali_id ?? null,
    foto_profil_url: row.foto_profil_url ?? null,
    is_active: !!row.is_active,
    created_at: row.created_at,
    roles: JSON.parse(row.roles || '[]'),
    subjects: JSON.parse(row.subjects || '[]'),
    classes: JSON.parse(row.classes || '[]'),
  };
}

function rowToStudent(row: any): Student {
  return {
    id: row.id,
    nis: row.nis,
    nama: row.nama,
    jk: row.jk,
    class_id: row.class_id,
    status: row.status,
    created_at: row.created_at,
  };
}

function rowToClass(row: any): ClassItem {
  return {
    id: row.id,
    name: row.name,
    jurusan: row.jurusan,
    angkatan: row.angkatan,
    tahun_ajaran: row.tahun_ajaran,
    semester: row.semester,
  };
}

function rowToSubject(row: any): Subject {
  return { id: row.id, name: row.name };
}

function rowToAttendance(row: any): AttendanceRecord {
  return {
    id: row.id,
    student_id: row.student_id,
    class_id: row.class_id,
    subject_id: row.subject_id ?? null,
    tanggal: row.tanggal,
    status: row.status,
    recorded_by: row.recorded_by,
    recorded_via: row.recorded_via,
    notes: row.notes ?? undefined,
    created_at: row.created_at,
    updated_at: row.updated_at,
    created_at_millis: row.created_at_millis ?? undefined,
  };
}

function rowToToken(row: any): KetuaKelasToken {
  return {
    token: row.token,
    class_id: row.class_id,
    status: row.status,
    created_at: row.created_at,
    created_by: row.created_by,
    expires_at: row.expires_at ?? undefined,
    expires_at_millis: row.expires_at_millis ?? undefined,
  };
}

function rowToParentToken(row: any): ParentAccessToken {
  return {
    token: row.token,
    student_id: row.student_id,
    status: row.status,
    created_at: row.created_at,
    created_by: row.created_by,
    revoked_at: row.revoked_at ?? undefined,
  };
}

function rowToPairing(row: any): TeacherPairing {
  return { user_id: row.user_id, subject_id: row.subject_id, class_id: row.class_id };
}

function rowToActivity(row: any): GradeActivity {
  return {
    id: row.id,
    teacher_id: row.teacher_id,
    subject_id: row.subject_id,
    class_id: row.class_id,
    nama_kegiatan: row.nama_kegiatan,
    tanggal_kegiatan: row.tanggal_kegiatan,
    tipe_skala: row.tipe_skala,
    created_at: row.created_at,
  };
}

function rowToGradeValue(row: any): GradeValue {
  return { activity_id: row.activity_id, student_id: row.student_id, nilai: row.nilai };
}

function rowToSettings(row: any): SchoolSettings {
  return {
    school_name: row.school_name,
    logo_url: row.logo_url,
    tahun_ajaran: row.tahun_ajaran,
    semester: row.semester,
    kepsek_nama: row.kepsek_nama,
    bk_nama: row.bk_nama,
    backup_retention_weeks: row.backup_retention_weeks,
    last_backup_date: row.last_backup_date ?? undefined,
    last_backup_status: row.last_backup_status ?? undefined,
  };
}

// ============================================================================
// Data access API digunakan oleh server.ts — SQL di bawah ini portable untuk
// SQLite maupun MySQL (tidak ada sintaks dialek-spesifik seperti
// "ON CONFLICT" atau "INSERT OR IGNORE"; upsert dilakukan dengan pola
// cek-lalu-update-atau-insert di level aplikasi).
// ============================================================================
export const Repo = {
  users: {
    all(): User[] {
      return dbAll('SELECT * FROM users').map(rowToUser);
    },
    byId(id: number): User | undefined {
      const row = dbGet('SELECT * FROM users WHERE id = ?', [id]);
      return row ? rowToUser(row) : undefined;
    },
    upsert(u: User) {
      const existing = dbGet('SELECT id FROM users WHERE id = ?', [u.id]);
      if (existing) {
        dbRun(
          `UPDATE users SET username = ?, password_hash = COALESCE(?, password_hash), nama = ?, kelas_wali_id = ?, foto_profil_url = ?, is_active = ?, roles = ?, subjects = ?, classes = ? WHERE id = ?`,
          [
            u.username,
            u.password_hash ?? null,
            u.nama,
            u.kelas_wali_id ?? null,
            u.foto_profil_url ?? null,
            u.is_active ? 1 : 0,
            JSON.stringify(u.roles || []),
            JSON.stringify(u.subjects || []),
            JSON.stringify(u.classes || []),
            u.id,
          ]
        );
      } else {
        dbRun(
          `INSERT INTO users (id, username, password_hash, nama, kelas_wali_id, foto_profil_url, is_active, created_at, roles, subjects, classes)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
          [
            u.id,
            u.username,
            u.password_hash ?? null,
            u.nama,
            u.kelas_wali_id ?? null,
            u.foto_profil_url ?? null,
            u.is_active ? 1 : 0,
            u.created_at,
            JSON.stringify(u.roles || []),
            JSON.stringify(u.subjects || []),
            JSON.stringify(u.classes || []),
          ]
        );
      }
    },
  },
  classes: {
    all(): ClassItem[] {
      return dbAll('SELECT * FROM classes').map(rowToClass);
    },
    byId(id: number): ClassItem | undefined {
      const row = dbGet('SELECT * FROM classes WHERE id = ?', [id]);
      return row ? rowToClass(row) : undefined;
    },
    upsert(c: ClassItem) {
      const existing = dbGet('SELECT id FROM classes WHERE id = ?', [c.id]);
      if (existing) {
        dbRun('UPDATE classes SET name = ?, jurusan = ?, angkatan = ?, tahun_ajaran = ?, semester = ? WHERE id = ?', [
          c.name,
          c.jurusan,
          c.angkatan,
          c.tahun_ajaran,
          c.semester,
          c.id,
        ]);
      } else {
        dbRun('INSERT INTO classes (id, name, jurusan, angkatan, tahun_ajaran, semester) VALUES (?, ?, ?, ?, ?, ?)', [
          c.id,
          c.name,
          c.jurusan,
          c.angkatan,
          c.tahun_ajaran,
          c.semester,
        ]);
      }
    },
  },
  subjects: {
    all(): Subject[] {
      return dbAll('SELECT * FROM subjects').map(rowToSubject);
    },
    upsert(s: Subject) {
      const existing = dbGet('SELECT id FROM subjects WHERE id = ?', [s.id]);
      if (existing) {
        dbRun('UPDATE subjects SET name = ? WHERE id = ?', [s.name, s.id]);
      } else {
        dbRun('INSERT INTO subjects (id, name) VALUES (?, ?)', [s.id, s.name]);
      }
    },
  },
  students: {
    all(classId?: number): Student[] {
      const rows = classId
        ? dbAll('SELECT * FROM students WHERE class_id = ?', [classId])
        : dbAll('SELECT * FROM students');
      return rows.map(rowToStudent);
    },
    byId(id: number): Student | undefined {
      const row = dbGet('SELECT * FROM students WHERE id = ?', [id]);
      return row ? rowToStudent(row) : undefined;
    },
    upsert(s: Student) {
      const existing = dbGet('SELECT id FROM students WHERE id = ?', [s.id]);
      if (existing) {
        dbRun('UPDATE students SET nis = ?, nama = ?, jk = ?, class_id = ?, status = ? WHERE id = ?', [
          s.nis,
          s.nama,
          s.jk,
          s.class_id,
          s.status,
          s.id,
        ]);
      } else {
        dbRun(
          'INSERT INTO students (id, nis, nama, jk, class_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
          [s.id, s.nis, s.nama, s.jk, s.class_id, s.status, s.created_at]
        );
      }
    },
  },
  pairings: {
    all(): TeacherPairing[] {
      return dbAll('SELECT * FROM teacher_subject_class_pairing').map(rowToPairing);
    },
    isPaired(userId: number, subjectId: number, classId: number): boolean {
      const row = dbGet(
        'SELECT 1 as ok FROM teacher_subject_class_pairing WHERE user_id = ? AND subject_id = ? AND class_id = ?',
        [userId, subjectId, classId]
      );
      return !!row;
    },
    replaceAll(pairings: TeacherPairing[]): { applied: boolean; reason?: string } {
      // Guard: sebuah sync push yang mengirim array KOSONG padahal server sudah
      // punya data TIDAK BOLEH menghapus semua pairing yang ada — itu hampir
      // pasti berarti device pengirim belum ter-hidrasi penuh (localStorage-nya
      // sendiri masih kosong/basi), bukan permintaan sungguhan untuk menghapus
      // semua penugasan guru se-sekolah sekaligus.
      const currentCount =
        dbGet<{ c: number }>('SELECT COUNT(*) as c FROM teacher_subject_class_pairing')?.c || 0;
      if (pairings.length === 0 && currentCount > 0) {
        return {
          applied: false,
          reason: `Ditolak: payload pairing kosong tapi server sudah punya ${currentCount} data. Kemungkinan device belum sinkron penuh — sync ditolak untuk mencegah penghapusan massal tidak sengaja.`,
        };
      }
      dbRun('DELETE FROM teacher_subject_class_pairing');
      const ignoreSql =
        DB_DRIVER === 'mysql'
          ? 'INSERT IGNORE INTO teacher_subject_class_pairing (user_id, subject_id, class_id) VALUES (?, ?, ?)'
          : 'INSERT OR IGNORE INTO teacher_subject_class_pairing (user_id, subject_id, class_id) VALUES (?, ?, ?)';
      for (const p of pairings) dbRun(ignoreSql, [p.user_id, p.subject_id, p.class_id]);
      return { applied: true };
    },
  },
  settings: {
    get(): SchoolSettings {
      const row = dbGet('SELECT * FROM school_settings WHERE id = 1');
      return row ? rowToSettings(row) : (INITIAL_SCHOOL_SETTINGS as SchoolSettings);
    },
    update(s: SchoolSettings) {
      const existing = dbGet('SELECT id FROM school_settings WHERE id = 1');
      const params = [
        s.school_name,
        s.logo_url,
        s.tahun_ajaran,
        s.semester,
        s.kepsek_nama,
        s.bk_nama,
        s.backup_retention_weeks,
        s.last_backup_date ?? null,
        s.last_backup_status ?? null,
      ];
      if (existing) {
        dbRun(
          `UPDATE school_settings SET school_name = ?, logo_url = ?, tahun_ajaran = ?, semester = ?, kepsek_nama = ?, bk_nama = ?, backup_retention_weeks = ?, last_backup_date = ?, last_backup_status = ? WHERE id = 1`,
          params
        );
      } else {
        dbRun(
          `INSERT INTO school_settings (id, school_name, logo_url, tahun_ajaran, semester, kepsek_nama, bk_nama, backup_retention_weeks, last_backup_date, last_backup_status)
           VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
          params
        );
      }
    },
  },
  attendance: {
    query(filter: { classId?: number; subjectId?: number | null; tanggal?: string; studentId?: number }): AttendanceRecord[] {
      let sql = 'SELECT * FROM attendance WHERE 1=1';
      const params: any[] = [];
      if (filter.classId !== undefined) {
        sql += ' AND class_id = ?';
        params.push(filter.classId);
      }
      if (filter.subjectId !== undefined) {
        if (filter.subjectId === null) {
          sql += ' AND subject_id IS NULL';
        } else {
          sql += ' AND subject_id = ?';
          params.push(filter.subjectId);
        }
      }
      if (filter.tanggal !== undefined) {
        sql += ' AND tanggal = ?';
        params.push(filter.tanggal);
      }
      if (filter.studentId !== undefined) {
        sql += ' AND student_id = ?';
        params.push(filter.studentId);
      }
      return dbAll(sql, params).map(rowToAttendance);
    },
    forStudent(studentId: number): AttendanceRecord[] {
      return dbAll('SELECT * FROM attendance WHERE student_id = ?', [studentId]).map(rowToAttendance);
    },
    upsert(rec: {
      student_id: number;
      class_id: number;
      subject_id: number | null;
      tanggal: string;
      status: string;
      notes?: string;
      recorded_by: number;
      recorded_via: string;
    }): { created: boolean } {
      // NB: dibedakan lewat "IS NULL" vs "= ?" (bukan "subject_id IS ?" dengan
      // parameter) supaya query yang sama berlaku identik di SQLite & MySQL —
      // MySQL tidak mendukung binding NULL lewat operator IS seperti SQLite.
      const existing =
        rec.subject_id === null
          ? dbGet<{ id: number; notes: string | null }>(
              'SELECT id, notes FROM attendance WHERE student_id = ? AND subject_id IS NULL AND tanggal = ?',
              [rec.student_id, rec.tanggal]
            )
          : dbGet<{ id: number; notes: string | null }>(
              'SELECT id, notes FROM attendance WHERE student_id = ? AND subject_id = ? AND tanggal = ?',
              [rec.student_id, rec.subject_id, rec.tanggal]
            );

      const nowIso = new Date().toISOString().replace('T', ' ').substring(0, 19);

      if (existing) {
        dbRun(`UPDATE attendance SET status = ?, notes = ?, recorded_by = ?, recorded_via = ?, updated_at = ? WHERE id = ?`, [
          rec.status,
          rec.notes ?? existing.notes,
          rec.recorded_by,
          rec.recorded_via,
          nowIso,
          existing.id,
        ]);
        return { created: false };
      }

      const [nextId] = allocateSequence('attendance', 1);
      dbRun(
        `INSERT INTO attendance (id, student_id, class_id, subject_id, tanggal, status, recorded_by, recorded_via, notes, created_at, updated_at, created_at_millis)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          nextId,
          rec.student_id,
          rec.class_id,
          rec.subject_id,
          rec.tanggal,
          rec.status,
          rec.recorded_by,
          rec.recorded_via,
          rec.notes ?? null,
          nowIso,
          nowIso,
          new Date(rec.tanggal).getTime(),
        ]
      );
      return { created: true };
    },
    deleteSession(filter: { classId: number; subjectId: number | null; tanggal: string }): number {
      const info =
        filter.subjectId === null
          ? dbRun('DELETE FROM attendance WHERE class_id = ? AND tanggal = ? AND subject_id IS NULL', [
              filter.classId,
              filter.tanggal,
            ])
          : dbRun('DELETE FROM attendance WHERE class_id = ? AND tanggal = ? AND subject_id = ?', [
              filter.classId,
              filter.tanggal,
              filter.subjectId,
            ]);
      return info.changes;
    },
  },
  gradeActivities: {
    insert(a: GradeActivity) {
      dbRun(
        `INSERT INTO grade_activities (id, teacher_id, subject_id, class_id, nama_kegiatan, tanggal_kegiatan, tipe_skala, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
        [a.id, a.teacher_id, a.subject_id, a.class_id, a.nama_kegiatan, a.tanggal_kegiatan, a.tipe_skala, a.created_at]
      );
    },
    all(): GradeActivity[] {
      return dbAll('SELECT * FROM grade_activities').map(rowToActivity);
    },
  },
  gradeValues: {
    insertMany(activityId: string, values: { student_id: number; nilai: string }[]) {
      for (const v of values) {
        dbRun('INSERT INTO grade_values (activity_id, student_id, nilai) VALUES (?, ?, ?)', [
          activityId,
          v.student_id,
          v.nilai,
        ]);
      }
    },
    all(): GradeValue[] {
      return dbAll('SELECT * FROM grade_values').map(rowToGradeValue);
    },
  },
  tokens: {
    all(): KetuaKelasToken[] {
      return dbAll('SELECT * FROM ketua_kelas_tokens').map(rowToToken);
    },
    byToken(token: string): KetuaKelasToken | undefined {
      const row = dbGet('SELECT * FROM ketua_kelas_tokens WHERE token = ?', [token]);
      return row ? rowToToken(row) : undefined;
    },
    upsert(t: KetuaKelasToken) {
      const existing = dbGet('SELECT token FROM ketua_kelas_tokens WHERE token = ?', [t.token]);
      if (existing) {
        dbRun(
          `UPDATE ketua_kelas_tokens SET class_id = ?, status = ?, created_at = ?, created_by = ?, expires_at = ?, expires_at_millis = ? WHERE token = ?`,
          [t.class_id, t.status, t.created_at, t.created_by, t.expires_at ?? null, t.expires_at_millis ?? null, t.token]
        );
      } else {
        dbRun(
          `INSERT INTO ketua_kelas_tokens (token, class_id, status, created_at, created_by, expires_at, expires_at_millis)
           VALUES (?, ?, ?, ?, ?, ?, ?)`,
          [t.token, t.class_id, t.status, t.created_at, t.created_by, t.expires_at ?? null, t.expires_at_millis ?? null]
        );
      }
    },
  },
  parentTokens: {
    byToken(token: string): ParentAccessToken | undefined {
      const row = dbGet('SELECT * FROM parent_access_tokens WHERE token = ?', [token]);
      return row ? rowToParentToken(row) : undefined;
    },
    byStudent(studentId: number): ParentAccessToken[] {
      return dbAll(
        'SELECT * FROM parent_access_tokens WHERE student_id = ? ORDER BY created_at DESC',
        [studentId]
      ).map(rowToParentToken);
    },
    create(studentId: number, createdBy: number): ParentAccessToken {
      const token = `wm_${randomBytes(24).toString('base64url')}`;
      const nowIso = new Date().toISOString();
      dbRun(`INSERT INTO parent_access_tokens (token, student_id, status, created_at, created_by) VALUES (?, ?, 'aktif', ?, ?)`, [
        token,
        studentId,
        nowIso,
        createdBy,
      ]);
      return { token, student_id: studentId, status: 'aktif', created_at: nowIso, created_by: createdBy };
    },
    revoke(token: string): boolean {
      const info = dbRun(`UPDATE parent_access_tokens SET status = 'nonaktif', revoked_at = ? WHERE token = ? AND status = 'aktif'`, [
        new Date().toISOString(),
        token,
      ]);
      return info.changes > 0;
    },
  },
  sessions: {
    create(userId: number, ttlMillis = 12 * 3600 * 1000): { token: string; expiresAtMillis: number } {
      const token =
        typeof crypto !== 'undefined' && crypto.randomUUID
          ? `${crypto.randomUUID()}${crypto.randomUUID()}`.replace(/-/g, '')
          : Array.from({ length: 48 }, () => Math.floor(Math.random() * 16).toString(16)).join('');
      const expiresAtMillis = Date.now() + ttlMillis;
      dbRun('INSERT INTO sessions (token, user_id, created_at, expires_at_millis) VALUES (?, ?, ?, ?)', [
        token,
        userId,
        new Date().toISOString(),
        expiresAtMillis,
      ]);
      // Sapu baris sesi kedaluwarsa milik pengguna lain sambil kita sudah menulis ke
      // tabel ini — token yang tidak pernah dipakai ulang tidak akan menumpuk selamanya
      // menunggu findValid() dipanggil dengan token itu (yang tidak pernah terjadi).
      dbRun('DELETE FROM sessions WHERE expires_at_millis < ?', [Date.now()]);
      return { token, expiresAtMillis };
    },
    findValid(token: string): { userId: number } | null {
      if (!token) return null;
      const row = dbGet<{ user_id: number; expires_at_millis: number }>(
        'SELECT user_id, expires_at_millis FROM sessions WHERE token = ?',
        [token]
      );
      if (!row) return null;
      if (Date.now() > row.expires_at_millis) {
        dbRun('DELETE FROM sessions WHERE token = ?', [token]);
        return null;
      }
      return { userId: row.user_id };
    },
    destroy(token: string) {
      dbRun('DELETE FROM sessions WHERE token = ?', [token]);
    },
    /**
     * Hapus semua sesi lain milik user ini (dipakai setelah ganti password
     * mandiri, supaya perangkat/token lama yang mungkin sudah bocor langsung
     * tidak berlaku lagi) — token sesi yang sedang dipakai untuk melakukan
     * penggantian password itu sendiri dikecualikan lewat exceptToken.
     */
    destroyAllForUser(userId: number, exceptToken?: string): number {
      const info = exceptToken
        ? dbRun('DELETE FROM sessions WHERE user_id = ? AND token != ?', [userId, exceptToken])
        : dbRun('DELETE FROM sessions WHERE user_id = ?', [userId]);
      return info.changes;
    },
    pruneExpired(): number {
      const info = dbRun('DELETE FROM sessions WHERE expires_at_millis < ?', [Date.now()]);
      return info.changes;
    },
  },
  auditLog: {
    recent(limit = 200): any[] {
      return dbAll('SELECT * FROM audit_log ORDER BY id DESC LIMIT ?', [limit]);
    },
  },
};

export function getDbCounts() {
  const count = (table: string) => dbGet<{ c: number }>(`SELECT COUNT(*) as c FROM ${table}`)?.c || 0;
  return {
    attendance: count('attendance'),
    students: count('students'),
    classes: count('classes'),
    pairings: count('teacher_subject_class_pairing'),
  };
}
