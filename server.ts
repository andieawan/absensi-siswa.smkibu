import express, { Request, Response } from 'express';
import { createServer as createViteServer } from 'vite';
import path from 'path';
import { fileURLToPath } from 'url';
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

async function startServer() {
  const app = express();
  const PORT = Number(process.env.PORT) || 3000;

  app.use(express.json({ limit: '10mb' }));
  app.use(express.urlencoded({ extended: true }));

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
    if (!entity) {
      return res.status(400).json({ success: false, error: 'Parameter entity wajib diisi.' });
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
    const { class_id, subject_id, tanggal, user_id, role } = req.body;

    if (!class_id || !tanggal) {
      return res.status(400).json({
        success: false,
        error: 'Parameter class_id dan tanggal wajib disertakan.',
      });
    }

    const today = new Date();
    const entryDate = new Date(tanggal);
    if (isNaN(entryDate.getTime())) {
      return res.status(400).json({ success: false, error: 'Format tanggal tidak valid.' });
    }

    const diffDays = Math.floor((today.getTime() - entryDate.getTime()) / (1000 * 3600 * 24));
    const isAdmin = role === 'admin' || role === 'superadmin';

    // PENEGAKAN ATURAN: Maksimal 7 hari
    if (diffDays > 7 && !isAdmin) {
      return res.status(403).json({
        success: false,
        diffDays,
        error: `Otorisasi Ditolak Server: Data absensi tanggal ${tanggal} sudah berusia ${diffDays} hari (> 7 hari). Berdasarkan kebijakan integritas data sekolah, entri yang melebihi batas 7 hari dikunci secara permanen dan tidak dapat dihapus oleh non-admin.`,
      });
    }

    // PENEGAKAN OTORISASI GURU
    if (user_id && !isAdmin) {
      const authCheck = verifyTeacherAuthorization(
        Number(user_id),
        subject_id !== undefined ? (subject_id === null ? null : Number(subject_id)) : null,
        Number(class_id),
        [role || 'guru']
      );
      if (!authCheck.allowed) {
        return res.status(403).json({ success: false, error: authCheck.error });
      }
    }

    const normalizedSubjectId =
      subject_id === null || subject_id === undefined ? null : Number(subject_id);
    const deletedCount = Repo.attendance.deleteSession({
      classId: Number(class_id),
      subjectId: normalizedSubjectId,
      tanggal,
    });

    recordAudit(
      'Hapus Sesi Absensi',
      'Absensi',
      String(user_id || 'System'),
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
    const { tanggal, role } = req.body;
    if (!tanggal) {
      return res.status(400).json({ allowed: false, error: 'Tanggal sesi absensi wajib disertakan.' });
    }

    const today = new Date();
    const entryDate = new Date(tanggal);
    const diffDays = Math.floor((today.getTime() - entryDate.getTime()) / (1000 * 3600 * 24));
    const isAdmin = role === 'admin' || role === 'superadmin';

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
    const { class_id, subject_id, tanggal, recorded_by, recorded_via, entries } = req.body;

    if (!class_id || !tanggal || !entries || !Array.isArray(entries)) {
      return res.status(400).json({
        success: false,
        error: 'Parameter class_id, tanggal, dan entries[] wajib diisi.',
      });
    }

    // 1. Validasi Format & Integritas Tanggal
    const today = new Date();
    const entryDate = new Date(tanggal);
    if (isNaN(entryDate.getTime())) {
      return res.status(400).json({ success: false, error: 'Format tanggal harus YYYY-MM-DD yang valid.' });
    }

    // Tanggal tidak boleh di masa depan melebihi toleransi 1 hari (karena timezone)
    const futureDiffMs = entryDate.getTime() - today.getTime();
    if (futureDiffMs > 24 * 3600 * 1000) {
      return res.status(400).json({
        success: false,
        error: 'Validasi Server Gagal: Tanggal sesi absensi tidak boleh berada di masa depan.',
      });
    }

    // Cek batas toleransi backdate 7 hari untuk pengguna biasa (non-admin)
    const user = Repo.users.byId(Number(recorded_by));
    const roles = user?.roles || [];
    const isAdmin = roles.includes('admin') || roles.includes('superadmin');
    const pastDiffDays = Math.floor((today.getTime() - entryDate.getTime()) / (1000 * 3600 * 24));

    if (pastDiffDays > 7 && !isAdmin && recorded_via !== 'bk_manual') {
      return res.status(403).json({
        success: false,
        error: `Otorisasi Ditolak Server: Sesi tanggal ${tanggal} (${pastDiffDays} hari lalu) melebihi batas toleransi penginputan 7 hari. Data historis > 7 hari hanya dapat dimasukkan oleh Administrator.`,
      });
    }

    // 2. Validasi Otorisasi Guru Mengajar
    if (recorded_via === 'guru' || recorded_via === 'wali') {
      const authResult = verifyTeacherAuthorization(
        Number(recorded_by),
        subject_id !== null && subject_id !== undefined ? Number(subject_id) : null,
        Number(class_id),
        roles
      );
      if (!authResult.allowed) {
        return res.status(403).json({ success: false, error: authResult.error });
      }
    }

    // 3. Validasi Keabsahan Setiap Entry Absensi
    const validStatuses = ['H', 'I', 'S', 'A'];
    for (const item of entries) {
      if (!item.student_id || !validStatuses.includes(item.status)) {
        return res.status(400).json({
          success: false,
          error: `Entri absensi tidak valid: ID siswa #${item.student_id} dengan status '${item.status}'. Status harus salah satu dari: H, I, S, A.`,
        });
      }

      // Validasi siswa memang terdaftar pada kelas tersebut
      const foundStudent = Repo.students.byId(Number(item.student_id));
      if (!foundStudent) {
        return res.status(404).json({
          success: false,
          error: `Siswa ID #${item.student_id} tidak terdaftar di database sekolah.`,
        });
      }
      if (foundStudent.class_id !== Number(class_id)) {
        return res.status(400).json({
          success: false,
          error: `Integritas Data Gagal: Siswa ${foundStudent.nama} bukan anggota kelas ID #${class_id}.`,
        });
      }
    }

    // 4. Lakukan Idempotent UPSERT di SQLite
    const normalizedSubjectId = subject_id !== null && subject_id !== undefined ? Number(subject_id) : null;
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
        recorded_by: Number(recorded_by),
        recorded_via: recorded_via || 'guru',
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
      user?.nama || String(recorded_by),
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
    const { student_id, admin_override, override_reason, operator_id } = req.body;

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
      String(operator_id || 'System'),
      `Siswa NIS ${student.nis} (${student.nama}) disahkan dengan kehadiran ${stats.rate}% (Override: ${Boolean(admin_override)})`
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
    const { teacher_id, subject_id, class_id, nama_kegiatan, tanggal_kegiatan, tipe_skala, scores } = req.body;

    if (!teacher_id || !subject_id || !class_id || !nama_kegiatan || !scores || !Array.isArray(scores)) {
      return res.status(400).json({ success: false, error: 'Data penilaian tidak lengkap.' });
    }

    const teacher = Repo.users.byId(Number(teacher_id));
    const roles = teacher?.roles || [];
    const authCheck = verifyTeacherAuthorization(Number(teacher_id), Number(subject_id), Number(class_id), roles);
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
      teacher_id: Number(teacher_id),
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
  app.post('/api/tokens/register', (req: Request, res: Response) => {
    const tokenObj = req.body;
    if (!tokenObj || !tokenObj.token) {
      return res.status(400).json({ success: false, error: 'Data token tidak valid.' });
    }
    Repo.tokens.upsert(tokenObj);
    return res.json({ success: true, message: 'Token berhasil didaftarkan di server.' });
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

    // Submit ke attendance SQLite
    if (!entries || !Array.isArray(entries)) {
      return res.status(400).json({ success: false, error: 'Parameter entries[] wajib diisi.' });
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
  app.post('/api/sync/push', (req: Request, res: Response) => {
    const { classes, subjects, students, users, pairings, settings } = req.body || {};
    try {
      if (Array.isArray(classes)) for (const c of classes) Repo.classes.upsert(c);
      if (Array.isArray(subjects)) for (const s of subjects) Repo.subjects.upsert(s);
      if (Array.isArray(students)) for (const s of students) Repo.students.upsert(s);
      if (Array.isArray(users)) for (const u of users) Repo.users.upsert(u);
      if (Array.isArray(pairings)) Repo.pairings.replaceAll(pairings);
      if (settings) Repo.settings.update(settings);
      return res.json({ success: true, message: 'Sinkronisasi data master ke server berhasil.' });
    } catch (err: any) {
      return res.status(500).json({ success: false, error: err?.message || 'Gagal menyimpan data ke server.' });
    }
  });

  // Snapshot lengkap untuk hidrasi awal device baru (Pull dari Server -> Client)
  app.get('/api/sync/pull', (req: Request, res: Response) => {
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

  // REST API Endpoint for server-side credential verification
  // PENTING: TIDAK ADA master password / backdoor. Verifikasi murni terhadap
  // password_hash (sha256:<salt>:<hash>) milik akun yang bersangkutan.
  app.post('/api/auth/verify', async (req: Request, res: Response) => {
    const { username, password } = req.body;
    if (!username || !password) {
      return res.status(400).json({ success: false, error: 'Username dan password wajib diisi.' });
    }
    const user = Repo.users.all().find((u) => u.username === username);
    if (!user || !user.is_active) {
      return res.status(401).json({ success: false, message: 'Akun tidak ditemukan atau nonaktif.' });
    }
    const isValid = await verifyPasswordAgainstHash(String(password), user.password_hash);
    return res.json({
      success: isValid,
      message: isValid ? 'Kredensial valid' : 'Password atau PIN tidak cocok',
    });
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
