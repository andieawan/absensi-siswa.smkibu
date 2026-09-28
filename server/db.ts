// ============================================================================
// Lapisan Persistensi SQLite (Node.js built-in `node:sqlite`)
// ============================================================================
// Menggantikan penyimpanan in-memory (array) pada server.ts dengan database
// SQL sungguhan yang persisten di disk, sehingga data tidak hilang saat
// server di-restart. Menggunakan modul bawaan Node.js `node:sqlite` (stabil
// sejak Node 22.5, tidak butuh dependency eksternal seperti better-sqlite3).
//
// Catatan deployment: SQLite menyimpan data sebagai satu file di disk lokal.
// Jika di-deploy ke platform dengan filesystem ephemeral (misalnya Cloud Run
// tanpa mounted volume), file ini akan hilang setiap kali instance di-recycle.
// Untuk deployment produksi multi-instance, arahkan DB_PATH ke sebuah
// persistent volume, atau migrasikan ke MySQL/Postgres terkelola.

import { DatabaseSync } from 'node:sqlite';
import path from 'path';
import { fileURLToPath } from 'url';
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
} from '../src/types';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const DB_PATH = process.env.DB_PATH || path.resolve(__dirname, '..', 'data', 'absensi.sqlite3');

// Pastikan folder data/ ada sebelum membuka file database.
import fs from 'fs';
import { createHash, randomBytes } from 'node:crypto';
const dataDir = path.dirname(DB_PATH);
if (!fs.existsSync(dataDir)) {
  fs.mkdirSync(dataDir, { recursive: true });
}

export const db = new DatabaseSync(DB_PATH);
db.exec('PRAGMA journal_mode = WAL;');
db.exec('PRAGMA foreign_keys = ON;');

// ============================================================================
// Skema Tabel
// ============================================================================
db.exec(`
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

-- Token sesi server-side (Bearer token), diterbitkan oleh POST /api/auth/login
-- setelah verifikasi password berhasil. Dipakai untuk melindungi endpoint yang
-- membaca/menulis seluruh data sekolah (mis. /api/sync/pull & /api/sync/push).
CREATE TABLE IF NOT EXISTS sessions (
  token TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  expires_at_millis INTEGER NOT NULL
);
`);

// ============================================================================
// Seeding: hanya dijalankan sekali, saat tabel users masih kosong
// ============================================================================
function seedIfEmpty() {
  const countRow = db.prepare('SELECT COUNT(*) as c FROM users').get() as { c: number };
  if (countRow.c > 0) return;

  const insertUser = db.prepare(
    `INSERT INTO users (id, username, password_hash, nama, kelas_wali_id, foto_profil_url, is_active, created_at, roles, subjects, classes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`
  );
  for (const u of INITIAL_USERS as User[]) {
    insertUser.run(
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
      JSON.stringify(u.classes || [])
    );
  }

  const insertClass = db.prepare(
    `INSERT INTO classes (id, name, jurusan, angkatan, tahun_ajaran, semester) VALUES (?, ?, ?, ?, ?, ?)`
  );
  for (const c of INITIAL_CLASSES as ClassItem[]) {
    insertClass.run(c.id, c.name, c.jurusan, c.angkatan, c.tahun_ajaran, c.semester);
  }

  const insertSubject = db.prepare(`INSERT INTO subjects (id, name) VALUES (?, ?)`);
  for (const s of INITIAL_SUBJECTS as Subject[]) {
    insertSubject.run(s.id, s.name);
  }

  const insertStudent = db.prepare(
    `INSERT INTO students (id, nis, nama, jk, class_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)`
  );
  for (const s of INITIAL_STUDENTS as Student[]) {
    insertStudent.run(s.id, s.nis, s.nama, s.jk, s.class_id, s.status, s.created_at);
  }

  const insertAttendance = db.prepare(
    `INSERT INTO attendance (id, student_id, class_id, subject_id, tanggal, status, recorded_by, recorded_via, notes, created_at, updated_at, created_at_millis)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`
  );
  for (const a of INITIAL_ATTENDANCE as AttendanceRecord[]) {
    insertAttendance.run(
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
      a.created_at_millis ?? null
    );
  }

  const insertActivity = db.prepare(
    `INSERT INTO grade_activities (id, teacher_id, subject_id, class_id, nama_kegiatan, tanggal_kegiatan, tipe_skala, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)`
  );
  for (const a of INITIAL_GRADE_ACTIVITIES as GradeActivity[]) {
    insertActivity.run(a.id, a.teacher_id, a.subject_id, a.class_id, a.nama_kegiatan, a.tanggal_kegiatan, a.tipe_skala, a.created_at);
  }

  const insertGradeValue = db.prepare(
    `INSERT INTO grade_values (activity_id, student_id, nilai) VALUES (?, ?, ?)`
  );
  for (const g of INITIAL_GRADE_VALUES as GradeValue[]) {
    insertGradeValue.run(g.activity_id, g.student_id, g.nilai);
  }

  const insertPairing = db.prepare(
    `INSERT INTO teacher_subject_class_pairing (user_id, subject_id, class_id) VALUES (?, ?, ?)`
  );
  for (const p of INITIAL_PAIRINGS as TeacherPairing[]) {
    insertPairing.run(p.user_id, p.subject_id, p.class_id);
  }

  const insertToken = db.prepare(
    `INSERT INTO ketua_kelas_tokens (token, class_id, status, created_at, created_by, expires_at, expires_at_millis)
     VALUES (?, ?, ?, ?, ?, ?, ?)`
  );
  for (const t of INITIAL_DELEGATION_TOKENS as KetuaKelasToken[]) {
    const expiresAt = t.expires_at || new Date(Date.now() + 7 * 24 * 3600 * 1000).toISOString();
    const expiresAtMillis = t.expires_at_millis || new Date(expiresAt).getTime();
    insertToken.run(t.token, t.class_id, t.status, t.created_at, t.created_by, expiresAt, expiresAtMillis);
  }

  const seqRows: Record<string, number> = {
    users: Math.max(10, ...(INITIAL_USERS as User[]).map((u) => u.id)),
    students: Math.max(100, ...(INITIAL_STUDENTS as Student[]).map((s) => s.id)),
    attendance: Math.max(1000, ...(INITIAL_ATTENDANCE as AttendanceRecord[]).map((a) => a.id)),
    classes: Math.max(10, ...(INITIAL_CLASSES as ClassItem[]).map((c) => c.id)),
    subjects: Math.max(10, ...(INITIAL_SUBJECTS as Subject[]).map((s) => s.id)),
    audit_logs: 100,
  };
  const insertSeq = db.prepare(`INSERT OR REPLACE INTO sequences (entity, value) VALUES (?, ?)`);
  for (const [entity, value] of Object.entries(seqRows)) {
    insertSeq.run(entity, value);
  }

  const s = INITIAL_SCHOOL_SETTINGS as SchoolSettings;
  db.prepare(
    `INSERT OR REPLACE INTO school_settings (id, school_name, logo_url, tahun_ajaran, semester, kepsek_nama, bk_nama, backup_retention_weeks, last_backup_date, last_backup_status)
     VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)`
  ).run(
    s.school_name,
    s.logo_url,
    s.tahun_ajaran,
    s.semester,
    s.kepsek_nama,
    s.bk_nama,
    s.backup_retention_weeks,
    s.last_backup_date ?? null,
    s.last_backup_status ?? null
  );
}

/**
 * Produksi: TIDAK memuat data demo (akun demo punya password yang tertulis di
 * source code publik). Akun Administrator pertama dibuat dari variabel
 * environment ADMIN_USERNAME + ADMIN_PASSWORD saat tabel users masih kosong.
 */
function bootstrapProductionAdmin() {
  const countRow = db.prepare('SELECT COUNT(*) as c FROM users').get() as { c: number };
  if (countRow.c > 0) return;

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
  db.prepare(
    `INSERT INTO users (id, username, password_hash, nama, kelas_wali_id, foto_profil_url, is_active, created_at, roles, subjects, classes)
     VALUES (1, ?, ?, ?, NULL, NULL, 1, ?, ?, '[]', '[]')`
  ).run(
    username,
    `sha256:${salt}:${hash}`,
    process.env.ADMIN_NAMA || 'Administrator',
    new Date().toISOString().replace('T', ' ').substring(0, 19),
    JSON.stringify(['superadmin', 'admin'])
  );
  db.prepare(`INSERT OR REPLACE INTO sequences (entity, value) VALUES ('users', 10)`).run();
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
  const row = db.prepare('SELECT value FROM sequences WHERE entity = ?').get(entity) as
    | { value: number }
    | undefined;
  const current = row ? row.value : 1000;
  const startId = current + 1;
  const next = current + count;

  if (row) {
    db.prepare('UPDATE sequences SET value = ? WHERE entity = ?').run(next, entity);
  } else {
    db.prepare('INSERT INTO sequences (entity, value) VALUES (?, ?)').run(next, entity);
  }

  const allocated: number[] = [];
  for (let i = startId; i <= next; i++) allocated.push(i);
  return allocated;
}

export function getSequencesStatus(): Record<string, number> {
  const rows = db.prepare('SELECT entity, value FROM sequences').all() as { entity: string; value: number }[];
  const out: Record<string, number> = {};
  for (const r of rows) out[r.entity] = r.value;
  return out;
}

export function recordAudit(action: string, module: string, actor: string, details: string) {
  db.prepare(
    `INSERT INTO audit_log (timestamp, action, module, actor, details) VALUES (?, ?, ?, ?, ?)`
  ).run(new Date().toISOString(), action, module, actor, details);
  // Batasi retensi log agar file tidak tumbuh tanpa batas
  db.exec(`
    DELETE FROM audit_log WHERE id NOT IN (
      SELECT id FROM audit_log ORDER BY id DESC LIMIT 500
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
// Data access API digunakan oleh server.ts
// ============================================================================
export const Repo = {
  users: {
    all(): User[] {
      return (db.prepare('SELECT * FROM users').all() as any[]).map(rowToUser);
    },
    byId(id: number): User | undefined {
      const row = db.prepare('SELECT * FROM users WHERE id = ?').get(id);
      return row ? rowToUser(row) : undefined;
    },
    upsert(u: User) {
      const existing = db.prepare('SELECT id FROM users WHERE id = ?').get(u.id);
      if (existing) {
        db.prepare(
          `UPDATE users SET username = ?, password_hash = COALESCE(?, password_hash), nama = ?, kelas_wali_id = ?, foto_profil_url = ?, is_active = ?, roles = ?, subjects = ?, classes = ? WHERE id = ?`
        ).run(
          u.username,
          u.password_hash ?? null,
          u.nama,
          u.kelas_wali_id ?? null,
          u.foto_profil_url ?? null,
          u.is_active ? 1 : 0,
          JSON.stringify(u.roles || []),
          JSON.stringify(u.subjects || []),
          JSON.stringify(u.classes || []),
          u.id
        );
      } else {
        db.prepare(
          `INSERT INTO users (id, username, password_hash, nama, kelas_wali_id, foto_profil_url, is_active, created_at, roles, subjects, classes)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`
        ).run(
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
          JSON.stringify(u.classes || [])
        );
      }
    },
  },
  classes: {
    all(): ClassItem[] {
      return (db.prepare('SELECT * FROM classes').all() as any[]).map(rowToClass);
    },
    byId(id: number): ClassItem | undefined {
      const row = db.prepare('SELECT * FROM classes WHERE id = ?').get(id);
      return row ? rowToClass(row) : undefined;
    },
    upsert(c: ClassItem) {
      db.prepare(
        `INSERT INTO classes (id, name, jurusan, angkatan, tahun_ajaran, semester) VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET name = excluded.name, jurusan = excluded.jurusan, angkatan = excluded.angkatan, tahun_ajaran = excluded.tahun_ajaran, semester = excluded.semester`
      ).run(c.id, c.name, c.jurusan, c.angkatan, c.tahun_ajaran, c.semester);
    },
  },
  subjects: {
    all(): Subject[] {
      return (db.prepare('SELECT * FROM subjects').all() as any[]).map(rowToSubject);
    },
    upsert(s: Subject) {
      db.prepare(
        `INSERT INTO subjects (id, name) VALUES (?, ?) ON CONFLICT(id) DO UPDATE SET name = excluded.name`
      ).run(s.id, s.name);
    },
  },
  students: {
    all(classId?: number): Student[] {
      const rows = classId
        ? db.prepare('SELECT * FROM students WHERE class_id = ?').all(classId)
        : db.prepare('SELECT * FROM students').all();
      return (rows as any[]).map(rowToStudent);
    },
    byId(id: number): Student | undefined {
      const row = db.prepare('SELECT * FROM students WHERE id = ?').get(id);
      return row ? rowToStudent(row) : undefined;
    },
    upsert(s: Student) {
      db.prepare(
        `INSERT INTO students (id, nis, nama, jk, class_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET nis = excluded.nis, nama = excluded.nama, jk = excluded.jk, class_id = excluded.class_id, status = excluded.status`
      ).run(s.id, s.nis, s.nama, s.jk, s.class_id, s.status, s.created_at);
    },
  },
  pairings: {
    all(): TeacherPairing[] {
      return (db.prepare('SELECT * FROM teacher_subject_class_pairing').all() as any[]).map(rowToPairing);
    },
    isPaired(userId: number, subjectId: number, classId: number): boolean {
      const row = db
        .prepare(
          'SELECT 1 FROM teacher_subject_class_pairing WHERE user_id = ? AND subject_id = ? AND class_id = ?'
        )
        .get(userId, subjectId, classId);
      return !!row;
    },
    replaceAll(pairings: TeacherPairing[]): { applied: boolean; reason?: string } {
      // Guard: sebuah sync push yang mengirim array KOSONG padahal server sudah
      // punya data TIDAK BOLEH menghapus semua pairing yang ada — itu hampir
      // pasti berarti device pengirim belum ter-hidrasi penuh (localStorage-nya
      // sendiri masih kosong/basi), bukan permintaan sungguhan untuk menghapus
      // semua penugasan guru se-sekolah sekaligus.
      const currentCount = (db.prepare('SELECT COUNT(*) as c FROM teacher_subject_class_pairing').get() as {
        c: number;
      }).c;
      if (pairings.length === 0 && currentCount > 0) {
        return {
          applied: false,
          reason: `Ditolak: payload pairing kosong tapi server sudah punya ${currentCount} data. Kemungkinan device belum sinkron penuh — sync ditolak untuk mencegah penghapusan massal tidak sengaja.`,
        };
      }
      db.exec('DELETE FROM teacher_subject_class_pairing');
      const stmt = db.prepare(
        'INSERT OR IGNORE INTO teacher_subject_class_pairing (user_id, subject_id, class_id) VALUES (?, ?, ?)'
      );
      for (const p of pairings) stmt.run(p.user_id, p.subject_id, p.class_id);
      return { applied: true };
    },
  },
  settings: {
    get(): SchoolSettings {
      const row = db.prepare('SELECT * FROM school_settings WHERE id = 1').get();
      return row ? rowToSettings(row) : (INITIAL_SCHOOL_SETTINGS as SchoolSettings);
    },
    update(s: SchoolSettings) {
      db.prepare(
        `INSERT INTO school_settings (id, school_name, logo_url, tahun_ajaran, semester, kepsek_nama, bk_nama, backup_retention_weeks, last_backup_date, last_backup_status)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET school_name = excluded.school_name, logo_url = excluded.logo_url, tahun_ajaran = excluded.tahun_ajaran,
           semester = excluded.semester, kepsek_nama = excluded.kepsek_nama, bk_nama = excluded.bk_nama,
           backup_retention_weeks = excluded.backup_retention_weeks, last_backup_date = excluded.last_backup_date, last_backup_status = excluded.last_backup_status`
      ).run(
        s.school_name,
        s.logo_url,
        s.tahun_ajaran,
        s.semester,
        s.kepsek_nama,
        s.bk_nama,
        s.backup_retention_weeks,
        s.last_backup_date ?? null,
        s.last_backup_status ?? null
      );
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
      return (db.prepare(sql).all(...params) as any[]).map(rowToAttendance);
    },
    forStudent(studentId: number): AttendanceRecord[] {
      return (db.prepare('SELECT * FROM attendance WHERE student_id = ?').all(studentId) as any[]).map(
        rowToAttendance
      );
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
      const existing = db
        .prepare('SELECT id, notes FROM attendance WHERE student_id = ? AND subject_id IS ? AND tanggal = ?')
        .get(rec.student_id, rec.subject_id, rec.tanggal) as { id: number; notes: string | null } | undefined;

      const nowIso = new Date().toISOString().replace('T', ' ').substring(0, 19);

      if (existing) {
        db.prepare(
          `UPDATE attendance SET status = ?, notes = ?, recorded_by = ?, recorded_via = ?, updated_at = ? WHERE id = ?`
        ).run(rec.status, rec.notes ?? existing.notes, rec.recorded_by, rec.recorded_via, nowIso, existing.id);
        return { created: false };
      }

      const [nextId] = allocateSequence('attendance', 1);
      db.prepare(
        `INSERT INTO attendance (id, student_id, class_id, subject_id, tanggal, status, recorded_by, recorded_via, notes, created_at, updated_at, created_at_millis)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`
      ).run(
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
        new Date(rec.tanggal).getTime()
      );
      return { created: true };
    },
    deleteSession(filter: { classId: number; subjectId: number | null; tanggal: string }): number {
      let sql = 'DELETE FROM attendance WHERE class_id = ? AND tanggal = ? AND subject_id IS ?';
      const info = db.prepare(sql).run(filter.classId, filter.tanggal, filter.subjectId);
      return Number(info.changes || 0);
    },
  },
  gradeActivities: {
    insert(a: GradeActivity) {
      db.prepare(
        `INSERT INTO grade_activities (id, teacher_id, subject_id, class_id, nama_kegiatan, tanggal_kegiatan, tipe_skala, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)`
      ).run(a.id, a.teacher_id, a.subject_id, a.class_id, a.nama_kegiatan, a.tanggal_kegiatan, a.tipe_skala, a.created_at);
    },
    all(): GradeActivity[] {
      return (db.prepare('SELECT * FROM grade_activities').all() as any[]).map(rowToActivity);
    },
  },
  gradeValues: {
    insertMany(activityId: string, values: { student_id: number; nilai: string }[]) {
      const stmt = db.prepare('INSERT INTO grade_values (activity_id, student_id, nilai) VALUES (?, ?, ?)');
      for (const v of values) stmt.run(activityId, v.student_id, v.nilai);
    },
    all(): GradeValue[] {
      return (db.prepare('SELECT * FROM grade_values').all() as any[]).map(rowToGradeValue);
    },
  },
  tokens: {
    all(): KetuaKelasToken[] {
      return (db.prepare('SELECT * FROM ketua_kelas_tokens').all() as any[]).map(rowToToken);
    },
    byToken(token: string): KetuaKelasToken | undefined {
      const row = db.prepare('SELECT * FROM ketua_kelas_tokens WHERE token = ?').get(token);
      return row ? rowToToken(row) : undefined;
    },
    upsert(t: KetuaKelasToken) {
      const existing = db.prepare('SELECT token FROM ketua_kelas_tokens WHERE token = ?').get(t.token);
      if (existing) {
        db.prepare(
          `UPDATE ketua_kelas_tokens SET class_id = ?, status = ?, created_at = ?, created_by = ?, expires_at = ?, expires_at_millis = ? WHERE token = ?`
        ).run(t.class_id, t.status, t.created_at, t.created_by, t.expires_at ?? null, t.expires_at_millis ?? null, t.token);
      } else {
        db.prepare(
          `INSERT INTO ketua_kelas_tokens (token, class_id, status, created_at, created_by, expires_at, expires_at_millis)
           VALUES (?, ?, ?, ?, ?, ?, ?)`
        ).run(t.token, t.class_id, t.status, t.created_at, t.created_by, t.expires_at ?? null, t.expires_at_millis ?? null);
      }
    },
  },
  sessions: {
    create(userId: number, ttlMillis = 12 * 3600 * 1000): { token: string; expiresAtMillis: number } {
      const token =
        typeof crypto !== 'undefined' && crypto.randomUUID
          ? `${crypto.randomUUID()}${crypto.randomUUID()}`.replace(/-/g, '')
          : Array.from({ length: 48 }, () => Math.floor(Math.random() * 16).toString(16)).join('');
      const expiresAtMillis = Date.now() + ttlMillis;
      db.prepare('INSERT INTO sessions (token, user_id, created_at, expires_at_millis) VALUES (?, ?, ?, ?)').run(
        token,
        userId,
        new Date().toISOString(),
        expiresAtMillis
      );
      // Sapu baris sesi kedaluwarsa milik pengguna lain sambil kita sudah menulis ke
      // tabel ini — token yang tidak pernah dipakai ulang tidak akan menumpuk selamanya
      // menunggu findValid() dipanggil dengan token itu (yang tidak pernah terjadi).
      db.prepare('DELETE FROM sessions WHERE expires_at_millis < ?').run(Date.now());
      return { token, expiresAtMillis };
    },
    findValid(token: string): { userId: number } | null {
      if (!token) return null;
      const row = db.prepare('SELECT user_id, expires_at_millis FROM sessions WHERE token = ?').get(token) as
        | { user_id: number; expires_at_millis: number }
        | undefined;
      if (!row) return null;
      if (Date.now() > row.expires_at_millis) {
        db.prepare('DELETE FROM sessions WHERE token = ?').run(token);
        return null;
      }
      return { userId: row.user_id };
    },
    destroy(token: string) {
      db.prepare('DELETE FROM sessions WHERE token = ?').run(token);
    },
    pruneExpired(): number {
      const info = db.prepare('DELETE FROM sessions WHERE expires_at_millis < ?').run(Date.now());
      return Number(info.changes || 0);
    },
  },
  auditLog: {
    recent(limit = 200): any[] {
      return db.prepare('SELECT * FROM audit_log ORDER BY id DESC LIMIT ?').all(limit);
    },
  },
};

export function getDbCounts() {
  const count = (table: string) => (db.prepare(`SELECT COUNT(*) as c FROM ${table}`).get() as { c: number }).c;
  return {
    attendance: count('attendance'),
    students: count('students'),
    classes: count('classes'),
    pairings: count('teacher_subject_class_pairing'),
  };
}
