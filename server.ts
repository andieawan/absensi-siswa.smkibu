import express, { Request, Response } from 'express';
import { createServer as createViteServer } from 'vite';
import path from 'path';
import { fileURLToPath } from 'url';
import { randomBytes } from 'node:crypto';
import { Repo, allocateSequence, getSequencesStatus, recordAudit, getDbCounts } from './server/db';
import { GradeActivity } from './src/types';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// ============================================================================
// Persistensi Data: SQLite (lihat server/db.ts) — Single Source of Truth Server
// ============================================================================
// Data sekarang disimpan di file SQLite (bukan lagi array in-memory), sehingga
// tetap ada setelah server di-restart. Semua query/mutasi dilakukan lewat
// modul `Repo` (server/db.ts).

// ============================================================================
// Engine Validasi Aturan Bisnis Server-Side
// ============================================================================

/**
 * Aturan Bisnis 1: Syarat Kehadiran Minimal 85% untuk Kelulusan & Kenaikan Kelas
 * Menghitung persentase kehadiran dan jumlah sesi defisit untuk mencapai ambang 85%.
 */
function calculateStudentAttendanceStats(studentId: number) {
  const records = Repo.attendance.forStudent(studentId);
  const total = records.length;
  const hadir = records.filter((a) => a.status === 'H').length;
  const izin = records.filter((a) => a.status === 'I').length;
  const sakit = records.filter((a) => a.status === 'S').length;
  const alpa = records.filter((a) => a.status === 'A').length;

  const rate = total > 0 ? (hadir / total) * 100 : 100;
  const rateRounded = Math.round(rate * 10) / 10;
  const meets85Percent = rateRounded >= 85.0;

  // Formula defisit kehadiran untuk mencapai 85%:
  // (hadir + x) / (total + x) >= 0.85 <=> 0.15*x >= 0.85*total - hadir
  const deficitSessions = meets85Percent
    ? 0
    : Math.max(1, Math.ceil((0.85 * total - hadir) / 0.15));

  return {
    studentId,
    total,
    hadir,
    izin,
    sakit,
    alpa,
    rate: rateRounded,
    threshold: 85.0,
    meets85Percent,
    deficitSessions,
  };
}

/**
 * Aturan Bisnis 2: Otorisasi Guru Mengajar (Teacher Pairing & Wali Kelas)
 */
function verifyTeacherAuthorization(
  userId: number,
  subjectId: number | null,
  classId: number,
  roles: string[]
): { allowed: boolean; error?: string } {
  const isAdmin = roles.includes('admin') || roles.includes('superadmin');
  if (isAdmin) {
    return { allowed: true };
  }

  const user = Repo.users.byId(userId);
  if (!user || !user.is_active) {
    return { allowed: false, error: 'Pengguna tidak ditemukan atau akun dalam status nonaktif.' };
  }

  // Absen Harian: Wajib Wali Kelas dari kelas terkait
  if (subjectId === null) {
    if (user.kelas_wali_id !== classId) {
      return {
        allowed: false,
        error: `Otorisasi Ditolak Server: Anda (${user.nama}) bukan Wali Kelas dari kelas ID #${classId}.`,
      };
    }
    return { allowed: true };
  }

  // Absen Mapel: Wajib terdaftar dalam pasangan teacher_subject_class_pairing
  const isPaired = Repo.pairings.isPaired(userId, subjectId, classId);
  if (!isPaired) {
    const hasClass = user.classes?.includes(classId);
    const hasSubject = user.subjects?.includes(subjectId);
    if (!hasClass || !hasSubject) {
      return {
        allowed: false,
        error: `Otorisasi Ditolak Server: Anda (${user.nama}) tidak memiliki penugasan untuk Mapel ID #${subjectId} di Kelas ID #${classId}.`,
      };
    }
  }

  return { allowed: true };
}

/**
 * Aturan Bisnis 4: Integritas entri absensi — status H/I/S/A dan siswa terdaftar
 * di kelas yang bersangkutan. Mengembalikan null kalau semua entri valid.
 */
function validateAttendanceEntries(entries: any[], classId: number): { status: number; error: string } | null {
  const validStatuses = ['H', 'I', 'S', 'A'];
  for (const item of entries) {
    if (!item || !item.student_id || !validStatuses.includes(item.status)) {
      return {
        status: 400,
        error: `Entri absensi tidak valid: ID siswa #${item?.student_id} dengan status '${item?.status}'. Status harus salah satu dari: H, I, S, A.`,
      };
    }
    const foundStudent = Repo.students.byId(Number(item.student_id));
    if (!foundStudent) {
      return { status: 404, error: `Siswa ID #${item.student_id} tidak terdaftar di database sekolah.` };
    }
    if (foundStudent.class_id !== classId) {
      return {
        status: 400,
        error: `Integritas Data Gagal: Siswa ${foundStudent.nama} bukan anggota kelas ID #${classId}.`,
      };
    }
  }
  return null;
}

// ============================================================================
// Verifikasi Password Server-Side (sha256:<salt>:<hash>, sinkron dengan
// src/services/auth.ts sisi klien). TIDAK ADA backdoor / master password.
// ============================================================================
async function sha256Hex(input: string): Promise<string> {
  const enc = new TextEncoder().encode(input);
  const digest = await crypto.subtle.digest('SHA-256', enc);
  return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function verifyPasswordAgainstHash(plain: string, stored: string | undefined | null): Promise<boolean> {
  if (!stored) return false;
  const parts = stored.split(':');
  if (parts.length !== 3 || parts[0] !== 'sha256') return false;
  const [, salt] = parts;
  const recomputed = `sha256:${salt}:${await sha256Hex(`${salt}:${plain}`)}`;
  return recomputed === stored;
}

// ============================================================================
// Sesi Server (Bearer Token) — melindungi endpoint yang membaca/menulis
// seluruh data sekolah sekaligus (mis. /api/sync/pull & /api/sync/push).
// Token diterbitkan lewat POST /api/auth/login setelah password terverifikasi.
// ============================================================================
function getBearerToken(req: Request): string | null {
  const header = req.headers['authorization'];
  if (typeof header !== 'string') return null;
  const match = header.match(/^Bearer\s+(.+)$/i);
  return match ? match[1].trim() : null;
}

function requireAuth(req: Request, res: Response): { id: number; nama: string; roles: string[] } | null {
  const token = getBearerToken(req);
  const session = token ? Repo.sessions.findValid(token) : null;
  if (!session) {
    res.status(401).json({ success: false, error: 'Sesi tidak sah atau sudah kedaluwarsa. Silakan login ulang.' });
    return null;
  }
  const user = Repo.users.byId(session.userId);
  if (!user || !user.is_active) {
    res.status(401).json({ success: false, error: 'Akun tidak ditemukan atau nonaktif.' });
    return null;
  }
  return { id: user.id, nama: user.nama, roles: user.roles || [] };
}

function requireAdmin(req: Request, res: Response): { id: number; nama: string; roles: string[] } | null {
  const user = requireAuth(req, res);
  if (!user) return null;
  const isAdmin = user.roles.includes('admin') || user.roles.includes('superadmin');
  if (!isAdmin) {
    res.status(403).json({ success: false, error: 'Otorisasi Ditolak: aksi ini khusus Administrator.' });
    return null;
  }
  return user;
}

type Actor = { id: number; nama: string; roles: string[] };

/** Pengguna yang sudah diverifikasi middleware auth global (lihat startServer). */
function getActor(res: Response): Actor {
  return res.locals.actor as Actor;
}

function hasAdminRole(roles: string[]): boolean {
  return roles.includes('admin') || roles.includes('superadmin');
}

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

/** Tanggal sesi wajib berformat YYYY-MM-DD yang valid. */
function parseSessionDate(tanggal: unknown): Date | null {
  if (typeof tanggal !== 'string' || !DATE_RE.test(tanggal)) return null;
  const d = new Date(tanggal);
  return isNaN(d.getTime()) ? null : d;
}

/** Selisih hari (bulat ke bawah) antara hari ini dan tanggal sesi. Negatif = masa depan. */
function daysSince(date: Date): number {
  return Math.floor((Date.now() - date.getTime()) / (1000 * 3600 * 24));
}

// ============================================================================
// Rate Limit Login (in-memory) — mencegah brute-force password/PIN.
// Dihitung per kombinasi IP + username. Di balik reverse proxy (mis. Cloud Run),
// set TRUST_PROXY=1 supaya req.ip berisi IP klien asli, bukan IP proxy.
// ============================================================================
const LOGIN_WINDOW_MS = 15 * 60 * 1000;
const LOGIN_MAX_FAILURES = 10;
const loginFailures = new Map<string, { count: number; firstAt: number }>();

function loginRateKey(req: Request, username: string): string {
  return `${req.ip}|${username.toLowerCase()}`;
}

/** Sisa detik penguncian, atau 0 kalau boleh mencoba login. */
function loginLockedFor(key: string): number {
  const entry = loginFailures.get(key);
  if (!entry) return 0;
  const elapsed = Date.now() - entry.firstAt;
  if (elapsed > LOGIN_WINDOW_MS) {
    loginFailures.delete(key);
    return 0;
  }
  return entry.count >= LOGIN_MAX_FAILURES ? Math.ceil((LOGIN_WINDOW_MS - elapsed) / 1000) : 0;
}

function recordLoginFailure(key: string): void {
  const now = Date.now();
  const entry = loginFailures.get(key);
  if (!entry || now - entry.firstAt > LOGIN_WINDOW_MS) {
    loginFailures.set(key, { count: 1, firstAt: now });
  } else {
    entry.count++;
  }
  // Cegah Map tumbuh tanpa batas
  if (loginFailures.size > 10000) {
    for (const [k, v] of loginFailures) {
      if (now - v.firstAt > LOGIN_WINDOW_MS) loginFailures.delete(k);
    }
  }
}

// Endpoint yang boleh diakses TANPA token sesi. Endpoint delegasi ketua kelas
// divalidasi dengan token delegasi masing-masing di handler-nya.
const PUBLIC_API_PATHS = new Set([
  '/health',
  '/auth/login',
  '/auth/logout',
  '/delegation/verify',
  '/delegation/session',
  '/delegation/submit',
]);

const ALLOWED_SEQUENCE_ENTITIES = new Set(['users', 'students', 'attendance', 'audit_logs', 'classes', 'subjects']);

async function startServer() {
  const app = express();
  const PORT = Number(process.env.PORT) || 3000;

  if (process.env.TRUST_PROXY) {
    app.set('trust proxy', Number(process.env.TRUST_PROXY) || process.env.TRUST_PROXY);
  }

  app.use(express.json({ limit: '10mb' }));
  app.use(express.urlencoded({ extended: true }));

  // ============================================================================
  // Auth Global: seluruh /api/* WAJIB token sesi yang sah, kecuali daftar publik.
  // Identitas & peran pengguna diambil dari sesi (res.locals.actor), TIDAK PERNAH
  // dari body/query request.
  // ============================================================================
  app.use('/api', (req: Request, res: Response, next) => {
    if (PUBLIC_API_PATHS.has(req.path)) return next();
    const actor = requireAuth(req, res);
    if (!actor) return; // requireAuth sudah mengirim 401
    res.locals.actor = actor;
    next();
  });

  // ============================================================================
  // Endpoints Informasi & Health Check
  // ============================================================================
  app.get('/api/health', (req: Request, res: Response) => {
    res.json({
      status: 'ok',
      service: 'go_absen_siswa_api',
      engine: 'Node.js Express + SQLite Relational Architecture + Server Business Rules',
      timestamp: new Date().toISOString(),
      uptime: process.uptime(),
      records: getDbCounts(),
    });
  });

  // Authoritative Sequence Allocation Endpoint (Single Source of Truth)
  app.post('/api/sequence/allocate', (req: Request, res: Response) => {
    const { entity, count = 1 } = req.body;
    if (!entity || !ALLOWED_SEQUENCE_ENTITIES.has(String(entity))) {
      return res.status(400).json({ success: false, error: 'Parameter entity tidak valid.' });
    }
    const safeCount = Math.min(Math.max(1, Number(count) || 1), 1000);
    const allocatedIds = allocateSequence(String(entity), safeCount);
    return res.json({
      success: true,
      entity,
      count: allocatedIds.length,
      allocatedIds,
      nextId: allocatedIds[0],
    });
  });

  app.get('/api/sequence/status', (req: Request, res: Response) => {
    return res.json({
      success: true,
      sequences: getSequencesStatus(),
      timestamp: new Date().toISOString(),
    });
  });

  // Dokumentasi Aturan Bisnis yang Ditegakkan Server
  app.get('/api/business-rules/summary', (req: Request, res: Response) => {
    res.json({
      service: 'Server-Side Business Rules Enforcement Engine',
      rules: [
        {
          id: 'RULE-01',
          name: 'Batas Hapus & Ubah Absensi 7 Hari',
          description:
            'Data absensi yang berusia lebih dari 7 hari dikunci secara permanen. Hanya Administrator yang dapat memodifikasi data melebihi 7 hari.',
          enforcement_points: ['POST /api/attendance/delete', 'POST /api/attendance/submit', 'Firestore Security Rules'],
        },
        {
          id: 'RULE-02',
          name: 'Syarat Kehadiran Minimal 85%',
          description:
            'Siswa wajib memiliki tingkat kehadiran ≥ 85% untuk berhak mengikuti ujian akhir, kenaikan kelas, dan pengesahan kelulusan.',
          enforcement_points: [
            'GET /api/students/:id/attendance-eligibility',
            'POST /api/students/evaluate-eligibility',
            'POST /api/students/academic-clearance',
            'POST /api/attendance/submit (Auto-alert)',
          ],
        },
        {
          id: 'RULE-03',
          name: 'Otorisasi Guru Mengajar (isTeacherAllowed)',
          description:
            'Guru hanya berhak menginput/mengubah absensi jika merupakan Wali Kelas (untuk absen harian) atau terdaftar di pairing mapel-kelas (untuk absen mapel).',
          enforcement_points: ['POST /api/attendance/submit', 'POST /api/attendance/delete', 'POST /api/grades/submit'],
        },
        {
          id: 'RULE-04',
          name: 'Integritas Input & Skala Penilaian',
          description:
            'Status absensi hanya boleh H, I, S, A. Skala angka harus berada di antara 0-100, skala huruf harus A-E.',
          enforcement_points: ['POST /api/attendance/submit', 'POST /api/grades/submit'],
        },
        {
          id: 'RULE-05',
          name: 'Token Delegasi Presensi Ketua Kelas (24 Jam)',
          description:
            'Token delegasi wajib berstatus aktif dan belum kedaluwarsa saat submit absensi berlangsung.',
          enforcement_points: ['POST /api/delegation/submit', 'GET /api/delegation/verify'],
        },
      ],
    });
  });

  // ============================================================================
  // ATURAN 1: PENGHAPUSAN ABSENSI DENGAN BATAS 7 HARI (Server-Side)
  // ============================================================================
  app.post('/api/attendance/delete', (req: Request, res: Response) => {
    const actor = getActor(res);
    const { class_id, subject_id, tanggal } = req.body;

    if (!class_id || !tanggal) {
      return res.status(400).json({
        success: false,
        error: 'Parameter class_id dan tanggal wajib disertakan.',
      });
    }

    const entryDate = parseSessionDate(tanggal);
    if (!entryDate) {
      return res.status(400).json({ success: false, error: 'Format tanggal harus YYYY-MM-DD yang valid.' });
    }

    const diffDays = daysSince(entryDate);
    const isAdmin = hasAdminRole(actor.roles);

    // PENEGAKAN ATURAN: Maksimal 7 hari
    if (diffDays > 7 && !isAdmin) {
      return res.status(403).json({
        success: false,
        diffDays,
        error: `Otorisasi Ditolak Server: Data absensi tanggal ${tanggal} sudah berusia ${diffDays} hari (> 7 hari). Berdasarkan kebijakan integritas data sekolah, entri yang melebihi batas 7 hari dikunci secara permanen dan tidak dapat dihapus oleh non-admin.`,
      });
    }

    const normalizedSubjectId =
      subject_id === null || subject_id === undefined ? null : Number(subject_id);

    // PENEGAKAN OTORISASI GURU (selalu dijalankan untuk non-admin)
    const authCheck = verifyTeacherAuthorization(actor.id, normalizedSubjectId, Number(class_id), actor.roles);
    if (!authCheck.allowed) {
      return res.status(403).json({ success: false, error: authCheck.error });
    }

    const deletedCount = Repo.attendance.deleteSession({
      classId: Number(class_id),
      subjectId: normalizedSubjectId,
      tanggal,
    });

    recordAudit(
      'Hapus Sesi Absensi',
      'Absensi',
      actor.nama,
      `Kelas #${class_id}, Mapel #${subject_id ?? 'Harian'}, Tanggal: ${tanggal}, Terhapus: ${deletedCount} baris`
    );

    return res.json({
      success: true,
      deleted: deletedCount,
      diffDays,
      message: `Sesi absensi tanggal ${tanggal} berhasil dihapus di sisi server (${deletedCount} data terhapus).`,
    });
  });

  // Validasi pra-penghapusan (probe)
  app.post('/api/attendance/validate-delete', (req: Request, res: Response) => {
    const { tanggal } = req.body;
    const entryDate = parseSessionDate(tanggal);
    if (!entryDate) {
      return res.status(400).json({ allowed: false, error: 'Tanggal sesi absensi (YYYY-MM-DD) wajib disertakan.' });
    }

    const diffDays = daysSince(entryDate);
    const isAdmin = hasAdminRole(getActor(res).roles);

    if (diffDays > 7 && !isAdmin) {
      return res.status(403).json({
        allowed: false,
        diffDays,
        error: `Otorisasi Ditolak Server: Data absensi tanggal ${tanggal} sudah berusia ${diffDays} hari (> 7 hari). Sesuai kebijakan integritas data sekolah, entri yang melebihi batas 7 hari dikunci dan tidak boleh dihapus.`,
      });
    }

    return res.json({
      allowed: true,
      diffDays,
      message: 'Validasi penghapusan sesi absensi berhasil (dalam rentang aman 7 hari).',
    });
  });

  // ============================================================================
  // ATURAN 2: INPUT ABSENSI DENGAN VALIDASI OTORISASI & ATURAN 85% AUTO-ALERT
  // ============================================================================
  app.post('/api/attendance/submit', (req: Request, res: Response) => {
    const actor = getActor(res);
    const { class_id, subject_id, tanggal, entries } = req.body;
    const recorded_via: string = req.body.recorded_via || 'guru';
    const recorded_by = actor.id;

    if (!class_id || !tanggal || !entries || !Array.isArray(entries)) {
      return res.status(400).json({
        success: false,
        error: 'Parameter class_id, tanggal, dan entries[] wajib diisi.',
      });
    }

    // 1. Validasi Format & Integritas Tanggal
    const entryDate = parseSessionDate(tanggal);
    if (!entryDate) {
      return res.status(400).json({ success: false, error: 'Format tanggal harus YYYY-MM-DD yang valid.' });
    }

    // Tanggal tidak boleh di masa depan melebihi toleransi 1 hari (karena timezone)
    if (entryDate.getTime() - Date.now() > 24 * 3600 * 1000) {
      return res.status(400).json({
        success: false,
        error: 'Validasi Server Gagal: Tanggal sesi absensi tidak boleh berada di masa depan.',
      });
    }

    const roles = actor.roles;
    const isAdmin = hasAdminRole(roles);

    // 2. Jalur pencatatan (recorded_via) hanya dari daftar resmi, dan masing-masing
    //    punya syarat peran sendiri. Delegasi ketua kelas WAJIB lewat /api/delegation/submit.
    const normalizedSubjectId = subject_id !== null && subject_id !== undefined ? Number(subject_id) : null;
    if (recorded_via === 'guru' || recorded_via === 'wali') {
      const authResult = verifyTeacherAuthorization(recorded_by, normalizedSubjectId, Number(class_id), roles);
      if (!authResult.allowed) {
        return res.status(403).json({ success: false, error: authResult.error });
      }
    } else if (recorded_via === 'bk_manual') {
      if (!isAdmin && !roles.includes('bk')) {
        return res.status(403).json({ success: false, error: 'Otorisasi Ditolak Server: input manual BK khusus Guru BK / Administrator.' });
      }
    } else if (recorded_via === 'upload_hardcopy') {
      if (!isAdmin) {
        return res.status(403).json({ success: false, error: 'Otorisasi Ditolak Server: unggah hardcopy khusus Administrator.' });
      }
    } else {
      return res.status(400).json({ success: false, error: `Jalur pencatatan '${recorded_via}' tidak dikenal.` });
    }

    // 3. Batas toleransi backdate 7 hari untuk non-admin (BK manual dikecualikan)
    const pastDiffDays = daysSince(entryDate);
    if (pastDiffDays > 7 && !isAdmin && recorded_via !== 'bk_manual') {
      return res.status(403).json({
        success: false,
        error: `Otorisasi Ditolak Server: Sesi tanggal ${tanggal} (${pastDiffDays} hari lalu) melebihi batas toleransi penginputan 7 hari. Data historis > 7 hari hanya dapat dimasukkan oleh Administrator.`,
      });
    }

    // Validasi Keabsahan Setiap Entry Absensi
    const entriesError = validateAttendanceEntries(entries, Number(class_id));
    if (entriesError) {
      return res.status(entriesError.status).json({ success: false, error: entriesError.error });
    }

    // 4. Lakukan Idempotent UPSERT di SQLite
    let created = 0;
    let updated = 0;

    for (const item of entries) {
      const result = Repo.attendance.upsert({
        student_id: Number(item.student_id),
        class_id: Number(class_id),
        subject_id: normalizedSubjectId,
        tanggal,
        status: item.status,
        notes: item.notes,
        recorded_by,
        recorded_via,
      });
      if (result.created) created++;
      else updated++;
    }

    // 5. ATURAN 85%: Evaluasi otomatis dan berikan alert jika ada siswa yang kehadirannya turun di bawah 85%
    const attendanceAlerts: any[] = [];
    for (const item of entries) {
      const stats = calculateStudentAttendanceStats(Number(item.student_id));
      if (!stats.meets85Percent) {
        const st = Repo.students.byId(Number(item.student_id));
        attendanceAlerts.push({
          student_id: item.student_id,
          nama: st?.nama,
          nis: st?.nis,
          rate: stats.rate,
          deficit: stats.deficitSessions,
          warning: `Kehadiran siswa ${st?.nama} berada pada ${stats.rate}% (di bawah batas kelulusan 85%). Defisit ${stats.deficitSessions} kehadiran.`,
        });
      }
    }

    recordAudit(
      'Submit Absensi',
      'Absensi',
      actor.nama,
      `Kelas #${class_id}, Tanggal: ${tanggal}, Total: ${entries.length} (${created} baru, ${updated} update)`
    );

    return res.json({
      success: true,
      count: entries.length,
      created,
      updated,
      attendanceAlerts,
      message: `Presensi berhasil diverifikasi & disimpan oleh server (${created} baru, ${updated} update).`,
    });
  });

  // ============================================================================
  // ATURAN 3: EVALUASI SYARAT KEHADIRAN MINIMAL 85% & PENGESAHAN AKADEMIK
  // ============================================================================

  // Evaluasi kelayakan kehadiran siswa tunggal
  app.get('/api/students/:id/attendance-eligibility', (req: Request, res: Response) => {
    const studentId = Number(req.params.id);
    const student = Repo.students.byId(studentId);
    if (!student) {
      return res.status(404).json({ success: false, error: 'Siswa tidak ditemukan.' });
    }

    const stats = calculateStudentAttendanceStats(studentId);
    const studentClass = Repo.classes.byId(student.class_id);

    return res.json({
      success: true,
      student: {
        id: student.id,
        nis: student.nis,
        nama: student.nama,
        kelas: studentClass?.name,
      },
      evaluation: {
        total_sessions: stats.total,
        hadir: stats.hadir,
        izin: stats.izin,
        sakit: stats.sakit,
        alpa: stats.alpa,
        rate: stats.rate,
        minimum_threshold: stats.threshold,
        meets_minimum_85: stats.meets85Percent,
        status: stats.meets85Percent ? 'MEMENUHI_SYARAT_85' : 'TIDAK_MEMENUHI_SYARAT_MINIMAL_85',
        badge: stats.meets85Percent ? 'KOMPETEN_MEMENUHI_SYARAT' : 'PERINGATAN_DEFISIT_BK',
        deficit_sessions: stats.deficitSessions,
        sanctions: stats.meets85Percent
          ? []
          : [
              'Penangguhan pengesahan nilai akhir semester / cetak rapor resmi',
              'Wajib mengikuti program kompensasi kehadiran akademik BK',
              'Penerbitan Surat Peringatan / Panggilan Orang Tua ke sekolah',
            ],
        academic_clearance_allowed: stats.meets85Percent,
      },
    });
  });

  // Evaluasi kelayakan kehadiran batch untuk satu kelas
  app.post('/api/students/evaluate-eligibility', (req: Request, res: Response) => {
    const { class_id } = req.body;
    let studentsToEval = Repo.students.all();
    if (class_id) {
      studentsToEval = studentsToEval.filter((s) => s.class_id === Number(class_id));
    }

    const evaluations = studentsToEval.map((s) => {
      const stats = calculateStudentAttendanceStats(s.id);
      return {
        student_id: s.id,
        nis: s.nis,
        nama: s.nama,
        class_id: s.class_id,
        rate: stats.rate,
        total: stats.total,
        hadir: stats.hadir,
        alpa: stats.alpa,
        meets_85: stats.meets85Percent,
        deficit: stats.deficitSessions,
      };
    });

    const atRiskCount = evaluations.filter((e) => !e.meets_85).length;

    return res.json({
      success: true,
      total_students: evaluations.length,
      compliant_count: evaluations.length - atRiskCount,
      at_risk_count: atRiskCount,
      minimum_threshold: 85.0,
      evaluations,
    });
  });

  // Pengesahan Akademik Server (Academic Clearance Gate):
  // Menolak pengesahan kelulusan / kenaikan kelas jika kehadiran < 85% tanpa dispensasi resmi
  app.post('/api/students/academic-clearance', (req: Request, res: Response) => {
    const actor = getActor(res);
    const { student_id, override_reason } = req.body;
    const admin_override = Boolean(req.body.admin_override);

    // Dispensasi (override) hanya sah dari Administrator / Kepala Sekolah yang login.
    if (admin_override && !hasAdminRole(actor.roles) && !actor.roles.includes('kepsek')) {
      return res.status(403).json({
        success: false,
        clearance_granted: false,
        error: 'Otorisasi Ditolak Server: dispensasi pengesahan akademik khusus Administrator / Kepala Sekolah.',
      });
    }
    if (admin_override && !String(override_reason || '').trim()) {
      return res.status(400).json({
        success: false,
        clearance_granted: false,
        error: 'Alasan dispensasi (override_reason) wajib diisi.',
      });
    }

    const student = Repo.students.byId(Number(student_id));
    if (!student) {
      return res.status(404).json({ success: false, error: 'Siswa tidak ditemukan.' });
    }

    const stats = calculateStudentAttendanceStats(student.id);

    // PENEGAKAN ATURAN 85%: Jika kehadiran < 85% dan tidak ada override admin resmi
    if (!stats.meets85Percent && !admin_override) {
      return res.status(422).json({
        success: false,
        clearance_granted: false,
        error: `Pengesahan Akademik Ditolak Server: Siswa NIS ${student.nis} (${student.nama}) hanya memiliki persentase kehadiran ${stats.rate}% (di bawah standar kelulusan minimal 85.0%). Siswa wajib menuntaskan program pembinaan BK atau memperoleh dispensasi khusus Kepala Sekolah.`,
        details: {
          current_rate: stats.rate,
          required_threshold: 85.0,
          deficit_sessions: stats.deficitSessions,
          required_actions: [
            'Hubungi Guru Bimbingan Konseling (BK)',
            'Selesaikan penugasan kompensasi jam kehadiran',
            'Surat dispensasi tertulis dari Kepala Sekolah (jika ada alasan medis/kedaruratan khusus)',
          ],
        },
      });
    }

    // Jika memenuhi syarat atau ada override resmi
    const clearanceCode = `CLR-${new Date().getFullYear()}-${student.nis}-${Math.random().toString(36).substring(2, 7).toUpperCase()}`;

    recordAudit(
      'Pengesahan Akademik',
      'Akademik',
      actor.nama,
      `Siswa NIS ${student.nis} (${student.nama}) disahkan dengan kehadiran ${stats.rate}% (Override: ${admin_override})`
    );

    return res.json({
      success: true,
      clearance_granted: true,
      clearance_code: clearanceCode,
      student_id: student.id,
      nama: student.nama,
      attendance_rate: stats.rate,
      is_override: Boolean(admin_override),
      override_reason: override_reason || null,
      certified_at: new Date().toISOString(),
      message: admin_override
        ? `Pengesahan akademik disetujui melalui dispensasi khusus administrator (Alasan: ${override_reason}).`
        : `Pengesahan akademik disetujui server: Siswa memenuhi standar kehadiran sekolah (≥ 85%).`,
    });
  });

  // ============================================================================
  // ATURAN 4: VALIDASI PENILAIAN SISWA & OTORISASI GURU
  // ============================================================================
  app.post('/api/grades/submit', (req: Request, res: Response) => {
    // Guru penilai = pengguna yang login (bukan teacher_id dari body).
    const actor = getActor(res);
    const teacher_id = actor.id;
    const { subject_id, class_id, nama_kegiatan, tanggal_kegiatan, tipe_skala, scores } = req.body;

    if (!subject_id || !class_id || !nama_kegiatan || !scores || !Array.isArray(scores)) {
      return res.status(400).json({ success: false, error: 'Data penilaian tidak lengkap.' });
    }

    const authCheck = verifyTeacherAuthorization(teacher_id, Number(subject_id), Number(class_id), actor.roles);
    if (!authCheck.allowed) {
      return res.status(403).json({ success: false, error: authCheck.error });
    }

    // Validasi tipe skala
    if (tipe_skala !== 'angka' && tipe_skala !== 'huruf') {
      return res.status(400).json({ success: false, error: "Tipe skala harus 'angka' atau 'huruf'." });
    }

    // Validasi nilai setiap siswa
    const validatedScores: { student_id: number; nilai: string }[] = [];
    const validLetters = ['A', 'B', 'C', 'D', 'E'];

    for (const sc of scores) {
      const valStr = String(sc.nilai).trim();
      if (tipe_skala === 'angka') {
        const num = Number(valStr);
        if (isNaN(num) || num < 0 || num > 100) {
          return res.status(400).json({
            success: false,
            error: `Nilai angka untuk siswa #${sc.student_id} tidak valid (${valStr}). Nilai harus berada dalam rentang 0 sampai 100.`,
          });
        }
      } else {
        if (!validLetters.includes(valStr.toUpperCase())) {
          return res.status(400).json({
            success: false,
            error: `Nilai huruf untuk siswa #${sc.student_id} tidak valid (${valStr}). Nilai harus salah satu dari A, B, C, D, E.`,
          });
        }
      }
      validatedScores.push({ student_id: Number(sc.student_id), nilai: valStr });
    }

    // Simpan activity dan values di SQLite (UUID global)
    const activityId = typeof crypto !== 'undefined' && crypto.randomUUID
      ? `act-${crypto.randomUUID()}`
      : `act-${Date.now()}-${Math.random().toString(36).substring(2, 8)}`;
    const nowIso = new Date().toISOString().replace('T', ' ').substring(0, 19);

    const newActivity: GradeActivity = {
      id: activityId,
      teacher_id,
      subject_id: Number(subject_id),
      class_id: Number(class_id),
      nama_kegiatan,
      tanggal_kegiatan: tanggal_kegiatan || nowIso.substring(0, 10),
      tipe_skala,
      created_at: nowIso,
    };
    Repo.gradeActivities.insert(newActivity);
    Repo.gradeValues.insertMany(activityId, validatedScores);

    return res.json({
      success: true,
      activity_id: activityId,
      records_saved: validatedScores.length,
      message: 'Penilaian siswa berhasil divalidasi dan disimpan di server.',
    });
  });

  // ============================================================================
  // ATURAN 5: DELEGASI KETUA KELAS LINTAS-DEVICE & EXPIRY 24 JAM
  // ============================================================================
  // Penerbitan token delegasi: HANYA server yang membuat string token (acak
  // kriptografis 192-bit) dan menentukan masa berlaku (maks. 24 jam). Penerbit
  // wajib Wali Kelas dari kelas tsb atau Administrator.
  app.post('/api/delegation/create', (req: Request, res: Response) => {
    const actor = getActor(res);
    const classId = Number(req.body?.class_id);
    if (!classId || !Repo.classes.byId(classId)) {
      return res.status(400).json({ success: false, error: 'Kelas tujuan delegasi tidak valid.' });
    }
    const authCheck = verifyTeacherAuthorization(actor.id, null, classId, actor.roles);
    if (!authCheck.allowed) {
      return res.status(403).json({ success: false, error: authCheck.error });
    }

    const expiryHours = Math.min(Math.max(1, Number(req.body?.expiry_hours) || 24), 24);
    const now = Date.now();
    const expiresAtMillis = now + expiryHours * 3600 * 1000;
    const newToken = {
      token: `kk_${randomBytes(24).toString('base64url')}`,
      class_id: classId,
      status: 'aktif' as const,
      created_at: new Date(now).toISOString(),
      created_by: actor.id,
      expires_at: new Date(expiresAtMillis).toISOString(),
      expires_at_millis: expiresAtMillis,
    };
    Repo.tokens.upsert(newToken);
    recordAudit('Buat Delegasi', 'Absensi', actor.nama, `Kelas #${classId}, berlaku ${expiryHours} jam`);

    return res.json({ success: true, token: newToken });
  });

  app.get('/api/delegation/verify', (req: Request, res: Response) => {
    const tokenStr = String(req.query.token || '');
    if (!tokenStr) {
      return res.status(400).json({ valid: false, error: 'Parameter token wajib diisi.' });
    }
    const found = Repo.tokens.byToken(tokenStr);
    if (!found) {
      return res.status(404).json({ valid: false, error: 'Token delegasi tidak ditemukan di database sekolah.' });
    }
    if (found.status !== 'aktif') {
      return res.status(403).json({ valid: false, error: 'Token presensi ini sudah tidak aktif atau telah dicabut.' });
    }
    const now = Date.now();
    const expiry = found.expires_at_millis || (found.expires_at ? new Date(found.expires_at).getTime() : null);
    if (expiry && now > expiry) {
      return res.status(410).json({
        valid: false,
        error: `Tautan presensi telah kedaluwarsa (berakhir pada ${new Date(expiry).toLocaleString('id-ID')}). Silakan minta Wali Kelas untuk membuatkan tautan baru.`,
      });
    }
    return res.json({ valid: true, token: found });
  });

  app.get('/api/delegation/session', (req: Request, res: Response) => {
    const tokenStr = String(req.query.token || '');
    if (!tokenStr) {
      return res.status(400).json({ valid: false, error: 'Parameter token diperlukan.' });
    }
    const found = Repo.tokens.byToken(tokenStr);
    if (!found) {
      return res.status(404).json({ valid: false, error: 'Token delegasi tidak ditemukan di sistem sekolah.' });
    }
    if (found.status !== 'aktif') {
      return res.status(403).json({ valid: false, error: 'Token delegasi ini sudah tidak aktif.' });
    }
    const now = Date.now();
    const expiry = found.expires_at_millis || (found.expires_at ? new Date(found.expires_at).getTime() : null);
    if (expiry && now > expiry) {
      return res.status(410).json({
        valid: false,
        error: `Tautan presensi telah kedaluwarsa pada ${new Date(expiry).toLocaleString('id-ID')}.`,
      });
    }

    const targetClass = Repo.classes.byId(found.class_id);
    const students = Repo.students.all(found.class_id).filter((s) => s.status === 'aktif');

    return res.json({
      valid: true,
      token: found,
      targetClass,
      students,
    });
  });

  app.post('/api/delegation/submit', (req: Request, res: Response) => {
    const { token, class_id, tanggal, entries } = req.body;
    if (typeof token !== 'string' || !token) {
      return res.status(400).json({ success: false, error: 'Parameter token wajib diisi.' });
    }
    const found = Repo.tokens.byToken(token);
    if (!found) {
      return res.status(404).json({ success: false, error: 'Token delegasi tidak sah.' });
    }
    if (found.status !== 'aktif') {
      return res.status(403).json({ success: false, error: 'Token delegasi sudah tidak aktif.' });
    }
    const now = Date.now();
    const expiry = found.expires_at_millis || (found.expires_at ? new Date(found.expires_at).getTime() : null);
    if (expiry && now > expiry) {
      return res.status(410).json({ success: false, error: 'Token delegasi telah kedaluwarsa.' });
    }
    if (found.class_id !== Number(class_id)) {
      return res.status(403).json({ success: false, error: 'Token ini tidak berlaku untuk kelas yang diajukan.' });
    }

    // Validasi tanggal: tidak di masa depan dan tidak lebih dari 7 hari ke belakang
    const entryDate = parseSessionDate(tanggal);
    if (!entryDate) {
      return res.status(400).json({ success: false, error: 'Format tanggal harus YYYY-MM-DD yang valid.' });
    }
    const pastDiffDays = daysSince(entryDate);
    if (entryDate.getTime() - Date.now() > 24 * 3600 * 1000 || pastDiffDays > 7) {
      return res.status(400).json({
        success: false,
        error: 'Tanggal presensi delegasi harus hari ini atau maksimal 7 hari ke belakang.',
      });
    }

    // Submit ke attendance SQLite
    if (!entries || !Array.isArray(entries)) {
      return res.status(400).json({ success: false, error: 'Parameter entries[] wajib diisi.' });
    }
    const entriesError = validateAttendanceEntries(entries, found.class_id);
    if (entriesError) {
      return res.status(entriesError.status).json({ success: false, error: entriesError.error });
    }
    for (const item of entries) {
      Repo.attendance.upsert({
        student_id: Number(item.student_id),
        class_id: Number(class_id),
        subject_id: null,
        tanggal,
        status: item.status,
        notes: item.notes,
        recorded_by: found.created_by,
        recorded_via: 'ketua_kelas_delegasi',
      });
    }

    recordAudit(
      'Submit Absensi Delegasi',
      'Absensi',
      `Ketua Kelas (token kelas #${found.class_id})`,
      `Tanggal: ${tanggal}, Total: ${entries.length} siswa`
    );

    return res.json({
      success: true,
      message: `Presensi kelas #${class_id} tanggal ${tanggal} (${entries?.length || 0} siswa) berhasil diverifikasi dan disimpan via delegasi Ketua Kelas.`,
    });
  });

  // ============================================================================
  // REST API Endpoints Pembacaan Data
  // ============================================================================
  app.get('/api/attendance', (req: Request, res: Response) => {
    const { class_id, subject_id, tanggal, student_id } = req.query;

    const result = Repo.attendance.query({
      classId: class_id ? Number(class_id) : undefined,
      subjectId:
        subject_id === undefined
          ? undefined
          : subject_id === 'null'
          ? null
          : Number(subject_id),
      tanggal: tanggal ? String(tanggal) : undefined,
      studentId: student_id ? Number(student_id) : undefined,
    });

    return res.json({ success: true, count: result.length, data: result });
  });

  app.get('/api/students', (req: Request, res: Response) => {
    const { class_id } = req.query;
    const result = Repo.students.all(class_id ? Number(class_id) : undefined);
    return res.json({ success: true, count: result.length, data: result });
  });

  app.get('/api/classes', (req: Request, res: Response) => {
    return res.json({ success: true, data: Repo.classes.all() });
  });

  app.get('/api/subjects', (req: Request, res: Response) => {
    return res.json({ success: true, data: Repo.subjects.all() });
  });

  // Pengguna (guru/admin/bk/kepsek) — TIDAK PERNAH mengekspos password_hash lewat GET.
  app.get('/api/users', (req: Request, res: Response) => {
    const safeUsers = Repo.users.all().map(({ password_hash, ...rest }) => rest);
    return res.json({ success: true, data: safeUsers });
  });

  app.get('/api/pairings', (req: Request, res: Response) => {
    return res.json({ success: true, data: Repo.pairings.all() });
  });

  app.get('/api/settings', (req: Request, res: Response) => {
    return res.json({ success: true, data: Repo.settings.get() });
  });

  app.post('/api/settings', (req: Request, res: Response) => {
    if (!hasAdminRole(getActor(res).roles)) {
      return res.status(403).json({ success: false, error: 'Otorisasi Ditolak: aksi ini khusus Administrator.' });
    }
    const settings = req.body;
    if (!settings || typeof settings !== 'object') {
      return res.status(400).json({ success: false, error: 'Data pengaturan sekolah tidak valid.' });
    }
    Repo.settings.update(settings);
    return res.json({ success: true, message: 'Pengaturan sekolah berhasil disimpan di server.' });
  });

  // ============================================================================
  // Sinkronisasi Massal Data Master (Push dari Client -> Server SQLite)
  // Dipakai oleh src/services/sqlSync.ts untuk sinkronisasi dua arah.
  // ============================================================================
  // Endpoint ini mengubah data master SELURUH sekolah (termasuk daftar akun &
  // peran/roles pengguna) — WAJIB Administrator yang sudah login dengan token
  // sesi yang sah. Tanpa ini, siapapun yang tahu URL server bisa mendaftarkan
  // dirinya sebagai superadmin hanya dengan satu request POST.
  app.post('/api/sync/push', (req: Request, res: Response) => {
    const actor = requireAdmin(req, res);
    if (!actor) return; // requireAdmin sudah mengirim response 401/403

    const { classes, subjects, students, users, pairings, settings } = req.body || {};
    try {
      if (Array.isArray(classes)) for (const c of classes) Repo.classes.upsert(c);
      if (Array.isArray(subjects)) for (const s of subjects) Repo.subjects.upsert(s);
      if (Array.isArray(students)) for (const s of students) Repo.students.upsert(s);
      if (Array.isArray(users)) for (const u of users) Repo.users.upsert(u);

      let pairingWarning: string | undefined;
      if (Array.isArray(pairings)) {
        const result = Repo.pairings.replaceAll(pairings);
        if (!result.applied) pairingWarning = result.reason;
      }

      if (settings) Repo.settings.update(settings);

      recordAudit('Sinkronisasi Data Master', 'Sistem', actor.nama, 'Push data master dari device ke server SQLite');

      return res.json({
        success: true,
        message: 'Sinkronisasi data master ke server berhasil.',
        warnings: pairingWarning ? [pairingWarning] : undefined,
      });
    } catch (err: any) {
      return res.status(500).json({ success: false, error: err?.message || 'Gagal menyimpan data ke server.' });
    }
  });

  // Snapshot lengkap untuk hidrasi awal device baru (Pull dari Server -> Client)
  // Berisi seluruh data sekolah (siswa, absensi, nilai, roster guru), jadi
  // WAJIB login (token sesi valid) — bukan endpoint publik.
  app.get('/api/sync/pull', (req: Request, res: Response) => {
    const actor = requireAuth(req, res);
    if (!actor) return;

    const safeUsers = Repo.users.all().map(({ password_hash, ...rest }) => rest);
    return res.json({
      success: true,
      data: {
        classes: Repo.classes.all(),
        subjects: Repo.subjects.all(),
        students: Repo.students.all(),
        users: safeUsers,
        pairings: Repo.pairings.all(),
        settings: Repo.settings.get(),
        attendance: Repo.attendance.query({}),
        tokens: Repo.tokens.all(),
        gradeActivities: Repo.gradeActivities.all(),
        gradeValues: Repo.gradeValues.all(),
      },
    });
  });

  app.get('/api/grades', (req: Request, res: Response) => {
    const { class_id, subject_id } = req.query;
    let activities = Repo.gradeActivities.all();
    if (class_id) activities = activities.filter((a) => a.class_id === Number(class_id));
    if (subject_id) activities = activities.filter((a) => a.subject_id === Number(subject_id));
    const activityIds = new Set(activities.map((a) => a.id));
    const values = Repo.gradeValues.all().filter((v) => activityIds.has(v.activity_id));
    return res.json({ success: true, activities, values });
  });

  // Login yang menerbitkan token sesi server (Bearer token), dipakai untuk
  // mengakses seluruh endpoint /api/* yang butuh otorisasi.
  // PENTING: TIDAK ADA master password / backdoor. Verifikasi murni terhadap
  // password_hash (sha256:<salt>:<hash>) milik akun yang bersangkutan.
  // (Endpoint lama /api/auth/verify dihapus: ia hanya menjadi "oracle" tebak
  // password tanpa menerbitkan sesi.)
  app.post('/api/auth/login', async (req: Request, res: Response) => {
    const { username, password } = req.body;
    if (typeof username !== 'string' || !username.trim() || !password) {
      return res.status(400).json({ success: false, error: 'Username dan password wajib diisi.' });
    }
    const cleanUsername = username.trim().toLowerCase();
    const rateKey = loginRateKey(req, cleanUsername);
    const lockedFor = loginLockedFor(rateKey);
    if (lockedFor > 0) {
      res.setHeader('Retry-After', String(lockedFor));
      return res.status(429).json({
        success: false,
        error: `Terlalu banyak percobaan login gagal. Coba lagi dalam ${Math.ceil(lockedFor / 60)} menit.`,
      });
    }

    // Pesan gagal sengaja seragam agar tidak bisa dipakai menebak username yang terdaftar.
    const user = Repo.users.all().find((u) => u.username.toLowerCase() === cleanUsername);
    const isValid = !!user && user.is_active && (await verifyPasswordAgainstHash(String(password), user.password_hash));
    if (!user || !isValid) {
      recordLoginFailure(rateKey);
      return res.status(401).json({ success: false, error: 'Username atau password/PIN salah, atau akun nonaktif.' });
    }
    loginFailures.delete(rateKey);
    const { token, expiresAtMillis } = Repo.sessions.create(user.id);
    const { password_hash, ...safeUser } = user;
    return res.json({
      success: true,
      token,
      expires_at_millis: expiresAtMillis,
      user: safeUser,
    });
  });

  app.post('/api/auth/logout', (req: Request, res: Response) => {
    const token = getBearerToken(req);
    if (token) Repo.sessions.destroy(token);
    return res.json({ success: true });
  });

  // Database Schema & Model Definition endpoint (Documentation / Verification)
  app.get('/api/schema', (req: Request, res: Response) => {
    res.json({
      message: 'Skema Relasional SQLite Terverifikasi dengan Server Business Rules',
      engine: 'node:sqlite (built-in, file-backed, WAL mode)',
      tables: [
        'users',
        'classes',
        'subjects',
        'students',
        'attendance',
        'grade_activities',
        'grade_values',
        'teacher_subject_class_pairing',
        'ketua_kelas_tokens',
        'audit_log',
        'sequences',
      ],
      constraints: {
        attendance_unique: '(student_id, subject_id, tanggal)',
        grade_values_primary: '(activity_id, student_id)',
        pairing_primary: '(user_id, subject_id, class_id)',
        business_rules: {
          retention_7_days: 'Enforced via /api/attendance/delete & Firestore Security Rules',
          minimum_attendance_85_percent: 'Enforced via /api/students/academic-clearance & /api/students/evaluate-eligibility',
          teacher_pairing_authorization: 'Enforced via /api/attendance/submit & /api/grades/submit',
        },
      },
    });
  });

  // Mount Vite middleware in development
  const isProd = process.env.NODE_ENV === 'production';
  if (!isProd) {
    const vite = await createViteServer({
      server: { middlewareMode: true },
      appType: 'spa',
    });
    app.use(vite.middlewares);
  } else {
    app.use(express.static(path.resolve(__dirname, 'dist')));
    app.get('*', (req: Request, res: Response) => {
      res.sendFile(path.resolve(__dirname, 'dist', 'index.html'));
    });
  }

  app.listen(PORT, '0.0.0.0', () => {
    console.log(`[go_absen_siswa] Server running on http://0.0.0.0:${PORT} with SQLite + Authoritative Business Rules`);
  });
}

startServer().catch((err) => {
  console.error('Failed to start server:', err);
  process.exit(1);
});
