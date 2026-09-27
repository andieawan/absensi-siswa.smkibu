import {
  User,
  ClassItem,
  Subject,
  Student,
  AttendanceRecord,
  GradeActivity,
  GradeValue,
  TeacherPairing,
  KetuaKelasToken,
  AuditLogItem,
  SchoolSettings,
  PeriodicPatternAlert,
  AttentionStudent,
  AttendanceStatus,
} from '../types';
import {
  INITIAL_CLASSES,
  INITIAL_SUBJECTS,
  INITIAL_USERS,
  INITIAL_STUDENTS,
  INITIAL_PAIRINGS,
  INITIAL_SCHOOL_SETTINGS,
  INITIAL_ATTENDANCE,
  INITIAL_GRADE_ACTIVITIES,
  INITIAL_GRADE_VALUES,
  INITIAL_DELEGATION_TOKENS,
  INITIAL_AUDIT_LOGS,
} from '../data/mockData';

const STORAGE_KEYS = {
  USERS: 'go_absen_users_v1',
  CLASSES: 'go_absen_classes_v1',
  SUBJECTS: 'go_absen_subjects_v1',
  STUDENTS: 'go_absen_students_v1',
  PAIRINGS: 'go_absen_pairings_v1',
  SETTINGS: 'go_absen_settings_v1',
  ATTENDANCE: 'go_absen_attendance_v1',
  GRADE_ACTIVITIES: 'go_absen_grade_act_v1',
  GRADE_VALUES: 'go_absen_grade_val_v1',
  TOKENS: 'go_absen_tokens_v1',
  LOGS: 'go_absen_logs_v1',
  CURRENT_USER_ID: 'go_absen_curr_user_v1',
};

class StorageManager {
  private getItem<T>(key: string, fallback: T): T {
    try {
      const data = localStorage.getItem(key);
      return data ? JSON.parse(data) : fallback;
    } catch {
      return fallback;
    }
  }

  private setItem<T>(key: string, value: T): void {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
      console.error('Storage write error', e);
    }
  }

  // Current logged in user
  getCurrentUser(): User {
    const users = this.getUsers();
    const storedId = this.getItem<number | null>(STORAGE_KEYS.CURRENT_USER_ID, null);
    if (storedId) {
      const found = users.find((u) => u.id === storedId);
      if (found) return found;
    }
    // Default to Pak Budi (superadmin & guru)
    return users[0];
  }

  setCurrentUser(userId: number): User {
    const users = this.getUsers();
    const found = users.find((u) => u.id === userId) || users[0];
    this.setItem(STORAGE_KEYS.CURRENT_USER_ID, found.id);
    return found;
  }

  // Users
  getUsers(): User[] {
    return this.getItem<User[]>(STORAGE_KEYS.USERS, INITIAL_USERS);
  }

  saveUsers(users: User[]): void {
    this.setItem(STORAGE_KEYS.USERS, users);
  }

  addUser(user: Omit<User, 'id' | 'created_at'>): User {
    const users = this.getUsers();
    const newUser: User = {
      ...user,
      id: Math.max(0, ...users.map((u) => u.id)) + 1,
      created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
    };
    users.push(newUser);
    this.saveUsers(users);
    this.addAuditLog('Tambah Akun Guru', 'Akun Guru', newUser.nama, `Menambahkan akun baru ${newUser.username}`);
    return newUser;
  }

  updateUser(id: number, updates: Partial<User>): User {
    const users = this.getUsers();
    const index = users.findIndex((u) => u.id === id);
    if (index === -1) throw new Error('Pengguna tidak ditemukan');
    users[index] = { ...users[index], ...updates };
    this.saveUsers(users);
    this.addAuditLog('Update Akun Guru', 'Akun Guru', users[index].nama, `Memperbarui data akun guru #${id}`);
    return users[index];
  }

  // Classes & Subjects
  getClasses(): ClassItem[] {
    return this.getItem<ClassItem[]>(STORAGE_KEYS.CLASSES, INITIAL_CLASSES);
  }

  getSubjects(): Subject[] {
    return this.getItem<Subject[]>(STORAGE_KEYS.SUBJECTS, INITIAL_SUBJECTS);
  }

  // Students
  getStudents(): Student[] {
    return this.getItem<Student[]>(STORAGE_KEYS.STUDENTS, INITIAL_STUDENTS);
  }

  saveStudents(students: Student[]): void {
    this.setItem(STORAGE_KEYS.STUDENTS, students);
  }

  addStudent(student: Omit<Student, 'id' | 'created_at'>): Student {
    const students = this.getStudents();
    const existing = students.find((s) => s.nis.trim() === student.nis.trim());
    if (existing) throw new Error(`NIS ${student.nis} sudah terdaftar`);

    const newStudent: Student = {
      ...student,
      id: Math.max(0, ...students.map((s) => s.id)) + 1,
      created_at: new Date().toISOString().substring(0, 10),
    };
    students.push(newStudent);
    this.saveStudents(students);
    this.addAuditLog('Tambah Siswa', 'Siswa', newStudent.nama, `NIS: ${newStudent.nis}, Kelas: ${newStudent.class_id}`);
    return newStudent;
  }

  updateStudent(id: number, updates: { nama?: string; jk?: 'L' | 'P'; status?: Student['status']; class_id?: number }): Student {
    const students = this.getStudents();
    const index = students.findIndex((s) => s.id === id);
    if (index === -1) throw new Error('Siswa tidak ditemukan');
    // Note: NIS is deliberately NOT editable to maintain historical integrity (PRD 6.7)
    students[index] = {
      ...students[index],
      ...updates,
    };
    this.saveStudents(students);
    this.addAuditLog('Update Siswa', 'Siswa', students[index].nama, `Perubahan status/data siswa #${id}`);
    return students[index];
  }

  // Granular Pairings
  getPairings(): TeacherPairing[] {
    return this.getItem<TeacherPairing[]>(STORAGE_KEYS.PAIRINGS, INITIAL_PAIRINGS);
  }

  savePairings(pairings: TeacherPairing[]): void {
    this.setItem(STORAGE_KEYS.PAIRINGS, pairings);
    this.addAuditLog('Update Pasangan Mapel-Kelas', 'Sistem', 'Otorisasi Guru', 'Memperbarui tabel teacher_subject_class_pairing');
  }

  isTeacherAllowed(userId: number, subjectId: number, classId: number): boolean {
    const pairings = this.getPairings();
    const teacherPairings = pairings.filter((p) => p.user_id === userId && p.subject_id === subjectId);
    // If no pairing configured for this user+subject, fallback to allowing all classes in user.classes (PRD 6.6)
    if (teacherPairings.length === 0) {
      const user = this.getUsers().find((u) => u.id === userId);
      return Boolean(user?.classes?.includes(classId));
    }
    return teacherPairings.some((p) => p.class_id === classId);
  }

  // School Settings
  getSettings(): SchoolSettings {
    return this.getItem<SchoolSettings>(STORAGE_KEYS.SETTINGS, INITIAL_SCHOOL_SETTINGS);
  }

  updateSettings(settings: Partial<SchoolSettings>): SchoolSettings {
    const current = this.getSettings();
    const updated = { ...current, ...settings };
    this.setItem(STORAGE_KEYS.SETTINGS, updated);
    this.addAuditLog('Ubah Pengaturan Sekolah', 'Sistem', 'School Settings', 'Memperbarui logo/identitas sekolah');
    return updated;
  }

  // Attendance
  getAttendance(): AttendanceRecord[] {
    return this.getItem<AttendanceRecord[]>(STORAGE_KEYS.ATTENDANCE, INITIAL_ATTENDANCE);
  }

  saveAttendance(records: AttendanceRecord[]): void {
    this.setItem(STORAGE_KEYS.ATTENDANCE, records);
  }

  // Submit attendance with Idempotent UPSERT per combination (student_id, subject_id, tanggal) (PRD 6.2 & 7)
  submitAttendanceBatch(params: {
    class_id: number;
    subject_id: number | null; // null for Absen Harian
    tanggal: string; // YYYY-MM-DD
    recorded_by: number;
    recorded_via: AttendanceRecord['recorded_via'];
    entries: { student_id: number; status: AttendanceStatus; notes?: string }[];
  }): { count: number; updated: number; created: number } {
    // Granular validation for subject attendance
    if (params.subject_id !== null && params.recorded_via === 'guru') {
      const allowed = this.isTeacherAllowed(params.recorded_by, params.subject_id, params.class_id);
      if (!allowed) {
        throw new Error('Otorisasi Ditolak: Anda tidak memiliki akses untuk mengajar kombinasi Mata Pelajaran dan Kelas ini.');
      }
    }

    const currentRecords = this.getAttendance();
    let created = 0;
    let updated = 0;
    const now = new Date().toISOString().replace('T', ' ').substring(0, 19);

    for (const entry of params.entries) {
      const existingIdx = currentRecords.findIndex(
        (r) =>
          r.student_id === entry.student_id &&
          r.subject_id === params.subject_id &&
          r.tanggal === params.tanggal
      );

      if (existingIdx >= 0) {
        currentRecords[existingIdx] = {
          ...currentRecords[existingIdx],
          status: entry.status,
          notes: entry.notes ?? currentRecords[existingIdx].notes,
          recorded_by: params.recorded_by,
          recorded_via: params.recorded_via,
          updated_at: now,
        };
        updated++;
      } else {
        const newId = Math.max(0, ...currentRecords.map((r) => r.id)) + 1;
        currentRecords.push({
          id: newId,
          student_id: entry.student_id,
          class_id: params.class_id,
          subject_id: params.subject_id,
          tanggal: params.tanggal,
          status: entry.status,
          notes: entry.notes,
          recorded_by: params.recorded_by,
          recorded_via: params.recorded_via,
          created_at: now,
          updated_at: now,
        });
        created++;
      }
    }

    this.saveAttendance(currentRecords);

    const targetDesc = params.subject_id === null ? 'Absen Harian' : `Mapel ID ${params.subject_id}`;
    this.addAuditLog(
      'Submit Absensi',
      'Absensi',
      `Kelas ID ${params.class_id} - ${targetDesc}`,
      `Tanggal: ${params.tanggal}, Total: ${params.entries.length} (${created} baru, ${updated} update) via ${params.recorded_via}`
    );

    return { count: params.entries.length, updated, created };
  }

  // Delete attendance check: 7-day rule (PRD 6.2)
  deleteAttendanceSession(classId: number, subjectId: number | null, tanggal: string): { deleted: number } {
    const today = new Date();
    const entryDate = new Date(tanggal);
    const diffDays = Math.floor((today.getTime() - entryDate.getTime()) / (1000 * 3600 * 24));

    if (diffDays > 7) {
      throw new Error(`Data absensi tanggal ${tanggal} lebih dari 7 hari lalu (${diffDays} hari). Sesuai kebijakan keamanan data, hanya entri 7 hari terakhir yang boleh dihapus.`);
    }

    const currentRecords = this.getAttendance();
    const remaining = currentRecords.filter(
      (r) => !(r.class_id === classId && r.subject_id === subjectId && r.tanggal === tanggal)
    );
    const deletedCount = currentRecords.length - remaining.length;
    this.saveAttendance(remaining);

    this.addAuditLog(
      'Hapus Absensi',
      'Absensi',
      `Kelas ID ${classId} - Tanggal ${tanggal}`,
      `Menghapus ${deletedCount} entri absensi dalam batas 7 hari.`
    );

    return { deleted: deletedCount };
  }

  // Grades Management
  getGradeActivities(): GradeActivity[] {
    return this.getItem<GradeActivity[]>(STORAGE_KEYS.GRADE_ACTIVITIES, INITIAL_GRADE_ACTIVITIES);
  }

  getGradeValues(): GradeValue[] {
    return this.getItem<GradeValue[]>(STORAGE_KEYS.GRADE_VALUES, INITIAL_GRADE_VALUES);
  }

  saveGradeActivities(acts: GradeActivity[]): void {
    this.setItem(STORAGE_KEYS.GRADE_ACTIVITIES, acts);
  }

  saveGradeValues(vals: GradeValue[]): void {
    this.setItem(STORAGE_KEYS.GRADE_VALUES, vals);
  }

  saveGradeActivityWithValues(activity: GradeActivity, values: { student_id: number; nilai: string }[]): void {
    const activities = this.getGradeActivities();
    const existingIdx = activities.findIndex((a) => a.id === activity.id);

    if (existingIdx >= 0) {
      activities[existingIdx] = activity;
    } else {
      activities.push(activity);
    }
    this.saveGradeActivities(activities);

    // Replace grade values for this activity
    let allValues = this.getGradeValues().filter((v) => v.activity_id !== activity.id);
    for (const item of values) {
      allValues.push({
        activity_id: activity.id,
        student_id: item.student_id,
        nilai: item.nilai,
      });
    }
    this.saveGradeValues(allValues);

    this.addAuditLog(
      'Simpan Kegiatan Nilai',
      'Nilai',
      activity.nama_kegiatan,
      `Tipe: ${activity.tipe_skala}, Kelas: ${activity.class_id}, ${values.length} siswa dinilai`
    );
  }

  deleteGradeActivity(activityId: string): void {
    const activities = this.getGradeActivities();
    const act = activities.find((a) => a.id === activityId);
    if (!act) throw new Error('Kegiatan nilai tidak ditemukan');

    const today = new Date();
    const actDate = new Date(act.tanggal_kegiatan);
    const diffDays = Math.floor((today.getTime() - actDate.getTime()) / (1000 * 3600 * 24));
    if (diffDays > 7) {
      throw new Error(`Kegiatan penilaian tanggal ${act.tanggal_kegiatan} melebihi batas penghapusan 7 hari.`);
    }

    const remainingActs = activities.filter((a) => a.id !== activityId);
    const remainingVals = this.getGradeValues().filter((v) => v.activity_id !== activityId);
    this.saveGradeActivities(remainingActs);
    this.saveGradeValues(remainingVals);

    this.addAuditLog('Hapus Kegiatan Nilai', 'Nilai', act.nama_kegiatan, `ID: ${activityId}`);
  }

  // Delegation Tokens (Ketua Kelas)
  getDelegationTokens(): KetuaKelasToken[] {
    return this.getItem<KetuaKelasToken[]>(STORAGE_KEYS.TOKENS, INITIAL_DELEGATION_TOKENS);
  }

  createDelegationToken(classId: number, createdBy: number): KetuaKelasToken {
    const tokens = this.getDelegationTokens();
    const tokenStr = `token-kk-${classId}-${Date.now().toString(36)}`;
    const newToken: KetuaKelasToken = {
      token: tokenStr,
      class_id: classId,
      status: 'aktif',
      created_at: new Date().toISOString(),
      created_by: createdBy,
    };
    tokens.push(newToken);
    this.setItem(STORAGE_KEYS.TOKENS, tokens);
    this.addAuditLog('Buat Delegasi', 'Absensi', `Token Kelas #${classId}`, `Token: ${tokenStr}`);
    return newToken;
  }

  // Audit Logs
  getAuditLogs(): AuditLogItem[] {
    return this.getItem<AuditLogItem[]>(STORAGE_KEYS.LOGS, INITIAL_AUDIT_LOGS);
  }

  addAuditLog(aksi: string, modul: AuditLogItem['modul'], target: string, detail: string): void {
    const logs = this.getAuditLogs();
    const currentUser = this.getCurrentUser();
    // Security check: Never log sensitive passwords (PRD 9.2)
    const sanitizedDetail = detail.replace(/password[:=]\s*[^\s,]+/gi, 'password=***');

    const newLog: AuditLogItem = {
      id: Math.max(0, ...logs.map((l) => l.id)) + 1,
      username: currentUser ? currentUser.username : 'system',
      aksi,
      modul,
      target,
      detail: sanitizedDetail,
      created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
    };
    logs.unshift(newLog); // latest first
    if (logs.length > 500) logs.pop(); // keep last 500
    this.setItem(STORAGE_KEYS.LOGS, logs);
  }

  // --- ANALYTICS ENGINE (PRD 6.4) ---

  // 1. Pola Absen Berkala (Detecting students absent repeatedly on the same weekday with ~14 day intervals, 10-18 tolerance)
  detectPeriodicPatterns(classId?: number, subjectId?: number | null): PeriodicPatternAlert[] {
    const attendances = this.getAttendance();
    const students = this.getStudents();
    const studentMap = new Map(students.map((s) => [s.id, s]));

    // Filter to relevant attendance records (absence only: I, S, A)
    const filtered = attendances.filter((a) => {
      if (a.status === 'H') return false;
      if (classId && a.class_id !== classId) return false;
      if (subjectId !== undefined && a.subject_id !== subjectId) return false;
      return true;
    });

    const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    // Group by student_id and weekday index (0-6)
    const studentDayAbsences = new Map<string, { student_id: number; dayIndex: number; dates: string[] }>();

    for (const record of filtered) {
      const dateObj = new Date(record.tanggal + 'T00:00:00');
      const dayIdx = dateObj.getDay();
      const key = `${record.student_id}-${dayIdx}`;

      if (!studentDayAbsences.has(key)) {
        studentDayAbsences.set(key, {
          student_id: record.student_id,
          dayIndex: dayIdx,
          dates: [],
        });
      }
      studentDayAbsences.get(key)!.dates.push(record.tanggal);
    }

    const alerts: PeriodicPatternAlert[] = [];

    studentDayAbsences.forEach((val) => {
      // Sort dates chronologically
      val.dates.sort();
      if (val.dates.length < 2) return;

      // Check intervals between consecutive absences on this weekday
      let periodicMatches = 0;
      const matchedDates: string[] = [];

      for (let i = 0; i < val.dates.length - 1; i++) {
        const d1 = new Date(val.dates[i] + 'T00:00:00').getTime();
        const d2 = new Date(val.dates[i + 1] + 'T00:00:00').getTime();
        const daysDiff = Math.round((d2 - d1) / (1000 * 3600 * 24));

        // PRD 6.4: ~14 days interval, tolerance 10-18 days
        if (daysDiff >= 10 && daysDiff <= 18) {
          periodicMatches++;
          if (!matchedDates.includes(val.dates[i])) matchedDates.push(val.dates[i]);
          if (!matchedDates.includes(val.dates[i + 1])) matchedDates.push(val.dates[i + 1]);
        }
      }

      if (periodicMatches >= 1) {
        const st = studentMap.get(val.student_id);
        if (st) {
          const count = matchedDates.length;
          // Status bertingkat: 2 kejadian = "Peringatan", 3+ = "Pola"
          const statusType: 'Peringatan' | 'Pola' = count >= 3 ? 'Pola' : 'Peringatan';
          alerts.push({
            student_id: val.student_id,
            student_name: st.nama,
            nis: st.nis,
            day_of_week: dayNames[val.dayIndex],
            day_index: val.dayIndex,
            count,
            dates: matchedDates,
            status_type: statusType,
          });
        }
      }
    });

    return alerts;
  }

  // 2. Daftar "Perlu Perhatian" (4 Kategori: Alpa tinggi, Izin tinggi, Sakit tinggi, Jarang masuk gabungan)
  getAttentionStudents(classId?: number, subjectId?: number | null): AttentionStudent[] {
    const attendances = this.getAttendance();
    const students = this.getStudents();
    const gradeValues = this.getGradeValues();

    const filtered = attendances.filter((a) => {
      if (classId && a.class_id !== classId) return false;
      if (subjectId !== undefined && a.subject_id !== subjectId) return false;
      return true;
    });

    // Count per student
    const counts = new Map<number, { alpa: number; izin: number; sakit: number; hadir: number }>();
    for (const a of filtered) {
      if (!counts.has(a.student_id)) {
        counts.set(a.student_id, { alpa: 0, izin: 0, sakit: 0, hadir: 0 });
      }
      const c = counts.get(a.student_id)!;
      if (a.status === 'A') c.alpa++;
      else if (a.status === 'I') c.izin++;
      else if (a.status === 'S') c.sakit++;
      else if (a.status === 'H') c.hadir++;
    }

    const result: AttentionStudent[] = [];

    students.forEach((s) => {
      if (classId && s.class_id !== classId) return;
      const c = counts.get(s.id) || { alpa: 0, izin: 0, sakit: 0, hadir: 0 };
      const totalAbsen = c.alpa + c.izin + c.sakit;

      // Check if student has low grades (<70 or D/E)
      const studentGrades = gradeValues.filter((g) => g.student_id === s.id);
      const hasGradeDrop = studentGrades.some((g) => {
        const num = parseFloat(g.nilai);
        if (!isNaN(num)) return num < 70;
        return g.nilai === 'D' || g.nilai === 'E';
      });

      // Thresholds:
      // Alpa tinggi: alpa >= 2
      // Izin tinggi: izin >= 2
      // Sakit tinggi: sakit >= 2
      // Jarang masuk gabungan: totalAbsen >= 3
      if (c.alpa >= 2) {
        result.push({
          student_id: s.id,
          student_name: s.nama,
          nis: s.nis,
          alpa_count: c.alpa,
          izin_count: c.izin,
          sakit_count: c.sakit,
          total_absen: totalAbsen,
          category: 'alpa_tinggi',
          severity: c.alpa * 3 + totalAbsen,
          hasGradeDrop,
        });
      } else if (c.sakit >= 2) {
        result.push({
          student_id: s.id,
          student_name: s.nama,
          nis: s.nis,
          alpa_count: c.alpa,
          izin_count: c.izin,
          sakit_count: c.sakit,
          total_absen: totalAbsen,
          category: 'sakit_tinggi',
          severity: c.sakit * 2 + totalAbsen,
          hasGradeDrop,
        });
      } else if (c.izin >= 2) {
        result.push({
          student_id: s.id,
          student_name: s.nama,
          nis: s.nis,
          alpa_count: c.alpa,
          izin_count: c.izin,
          sakit_count: c.sakit,
          total_absen: totalAbsen,
          category: 'izin_tinggi',
          severity: c.izin * 1.5 + totalAbsen,
          hasGradeDrop,
        });
      } else if (totalAbsen >= 3) {
        result.push({
          student_id: s.id,
          student_name: s.nama,
          nis: s.nis,
          alpa_count: c.alpa,
          izin_count: c.izin,
          sakit_count: c.sakit,
          total_absen: totalAbsen,
          category: 'jarang_masuk_gabungan',
          severity: totalAbsen * 2,
          hasGradeDrop,
        });
      }
    });

    // Sort by severity descending
    return result.sort((a, b) => b.severity - a.severity);
  }

  // 3. Automated Narrative & Recommendations (Indonesian, pure threshold-based rule engine, strictly NOT generative AI as per PRD 6.4)
  generateAutomatedNarrative(params: {
    rate: number;
    hadirCount: number;
    alpaCount: number;
    izinCount: number;
    sakitCount: number;
    attentionCount: number;
    patternCount: number;
    scopeName: string;
    hasGradeDropCount?: number;
  }): { summary: string; recommendation: string } {
    const { rate, alpaCount, sakitCount, izinCount, attentionCount, patternCount, scopeName, hasGradeDropCount } = params;

    let summary = '';
    let recommendation = '';

    // Summary conditions
    if (rate >= 92) {
      summary = `Tingkat kehadiran siswa pada ${scopeName} berada pada level sangat prima (${rate.toFixed(1)}%). Mayoritas peserta didik hadir konsisten mengikuti kegiatan pembelajaran.`;
    } else if (rate >= 80) {
      summary = `Kehadiran siswa pada ${scopeName} berada dalam rentang wajar (${rate.toFixed(1)}%), namun terdapat akumulasi ${alpaCount} Alpa, ${izinCount} Izin, dan ${sakitCount} Sakit yang perlu dimonitor secara berkala.`;
    } else {
      summary = `PERINGATAN: Tingkat kehadiran siswa pada ${scopeName} mengalami penurunan kritis (${rate.toFixed(1)}%), di bawah standar minimum sekolah (80%). Tercatat ${alpaCount} ketidakhadiran tanpa keterangan (Alpa).`;
    }

    if (patternCount > 0) {
      summary += ` Sistem juga mendeteksi adanya keteraturan pola absensi berkala pada ${patternCount} siswa dengan interval ~14 hari pada hari yang sama.`;
    }

    if (hasGradeDropCount && hasGradeDropCount > 0) {
      summary += ` Terdapat sinyal prioritas: ${hasGradeDropCount} siswa mengalami penurunan di kedua aspek secara serempak (kehadiran dan nilai akademik).`;
    }

    // Recommendation conditions
    if (alpaCount > 3 || (hasGradeDropCount && hasGradeDropCount > 0)) {
      recommendation = `Rekomendasi Tindakan: Lakukan pemanggilan orang tua dan koordinasi dengan Guru BK untuk ${attentionCount} siswa berstatus perhatian tinggi. Prioritaskan siswa dengan penurunan ganda (absen dan nilai) agar tidak tertinggal materi uji semester.`;
    } else if (patternCount > 0) {
      recommendation = `Rekomendasi Tindakan: Verifikasi alasan ketidakhadiran berulang pada hari tertentu kepada siswa terkait atau wali kelas, guna memastikan tidak ada faktor kebiasaan membolos berulang.`;
    } else if (sakitCount >= 4) {
      recommendation = `Rekomendasi Tindakan: Koordinasikan dengan tim UKS sekolah untuk memantau kondisi kesehatan siswa yang sering izin sakit berturut-turut.`;
    } else {
      recommendation = `Rekomendasi Tindakan: Pertahankan ritme pembelajaran aktif dan berikan apresiasi kepada siswa dengan rekor kehadiran 100%.`;
    }

    return { summary, recommendation };
  }

  // Backup & SQL Export Engine (PRD 6.10 & 7)
  generateSqlDump(): string {
    const users = this.getUsers();
    const classes = this.getClasses();
    const subjects = this.getSubjects();
    const students = this.getStudents();
    const attendances = this.getAttendance();
    const activities = this.getGradeActivities();
    const grades = this.getGradeValues();
    const pairings = this.getPairings();
    const settings = this.getSettings();

    const timestamp = new Date().toISOString();

    let sql = `-- ========================================================\n`;
    sql += `-- DUMP DATABASE go_absen_siswa (Node.js + MySQL)\n`;
    sql += `-- Generated: ${timestamp}\n`;
    sql += `-- Source Server: Nginx Physical Box / Production Node.js\n`;
    sql += `-- ========================================================\n\n`;

    sql += `SET FOREIGN_KEY_CHECKS = 0;\n\n`;

    // Dump classes
    sql += `-- Tabel: classes\n`;
    sql += `TRUNCATE TABLE classes;\n`;
    for (const c of classes) {
      sql += `INSERT INTO classes (id, name, jurusan, angkatan, tahun_ajaran, semester) VALUES (${c.id}, '${c.name}', '${c.jurusan}', '${c.angkatan}', '${c.tahun_ajaran}', '${c.semester}');\n`;
    }
    sql += `\n`;

    // Dump subjects
    sql += `-- Tabel: subjects\n`;
    sql += `TRUNCATE TABLE subjects;\n`;
    for (const s of subjects) {
      sql += `INSERT INTO subjects (id, name) VALUES (${s.id}, '${s.name}');\n`;
    }
    sql += `\n`;

    // Dump users
    sql += `-- Tabel: users\n`;
    sql += `TRUNCATE TABLE users;\n`;
    for (const u of users) {
      const waliVal = u.kelas_wali_id !== null ? u.kelas_wali_id : 'NULL';
      sql += `INSERT INTO users (id, username, password_hash, nama, kelas_wali_id, is_active, created_at) VALUES (${u.id}, '${u.username}', '$2b$12$eX4mpleBcRyptHashMigratedKey...', '${u.nama}', ${waliVal}, ${u.is_active ? 1 : 0}, '${u.created_at}');\n`;
    }
    sql += `\n`;

    // Dump students
    sql += `-- Tabel: students\n`;
    sql += `TRUNCATE TABLE students;\n`;
    for (const s of students) {
      sql += `INSERT INTO students (id, nis, nama, jk, class_id, status, created_at) VALUES (${s.id}, '${s.nis}', '${s.nama.replace(/'/g, "\\'")}', '${s.jk}', ${s.class_id}, '${s.status}', '${s.created_at}');\n`;
    }
    sql += `\n`;

    // Dump attendance
    sql += `-- Tabel: attendance (Normalized)\n`;
    sql += `TRUNCATE TABLE attendance;\n`;
    for (const a of attendances) {
      const subVal = a.subject_id !== null ? a.subject_id : 'NULL';
      sql += `INSERT INTO attendance (id, student_id, class_id, subject_id, tanggal, status, recorded_by, recorded_via, created_at, updated_at) VALUES (${a.id}, ${a.student_id}, ${a.class_id}, ${subVal}, '${a.tanggal}', '${a.status}', ${a.recorded_by}, '${a.recorded_via}', '${a.created_at}', '${a.updated_at}');\n`;
    }
    sql += `\n`;

    // Dump grade_activities & grade_values
    sql += `-- Tabel: grade_activities & grade_values\n`;
    sql += `TRUNCATE TABLE grade_activities;\n`;
    for (const act of activities) {
      sql += `INSERT INTO grade_activities (id, teacher_id, subject_id, class_id, nama_kegiatan, tanggal_kegiatan, tipe_skala, created_at) VALUES ('${act.id}', ${act.teacher_id}, ${act.subject_id}, ${act.class_id}, '${act.nama_kegiatan.replace(/'/g, "\\'")}', '${act.tanggal_kegiatan}', '${act.tipe_skala}', '${act.created_at}');\n`;
    }
    sql += `TRUNCATE TABLE grade_values;\n`;
    for (const g of grades) {
      sql += `INSERT INTO grade_values (activity_id, student_id, nilai) VALUES ('${g.activity_id}', ${g.student_id}, '${g.nilai}');\n`;
    }
    sql += `\n`;

    // Dump pairings
    sql += `-- Tabel: teacher_subject_class_pairing\n`;
    sql += `TRUNCATE TABLE teacher_subject_class_pairing;\n`;
    for (const p of pairings) {
      sql += `INSERT INTO teacher_subject_class_pairing (user_id, subject_id, class_id) VALUES (${p.user_id}, ${p.subject_id}, ${p.class_id});\n`;
    }
    sql += `\n`;

    // Dump settings
    sql += `-- Tabel: school_settings\n`;
    sql += `TRUNCATE TABLE school_settings;\n`;
    sql += `INSERT INTO school_settings (setting_key, setting_value) VALUES ('school_name', '${settings.school_name}'), ('tahun_ajaran', '${settings.tahun_ajaran}'), ('semester', '${settings.semester}');\n`;
    sql += `\nSET FOREIGN_KEY_CHECKS = 1;\n`;
    sql += `-- DUMP COMPLETED SUCCESSFULLY --\n`;

    return sql;
  }

  // Reset to initial demo seed
  resetToDefault(): void {
    localStorage.clear();
    this.setItem(STORAGE_KEYS.USERS, INITIAL_USERS);
    this.setItem(STORAGE_KEYS.CLASSES, INITIAL_CLASSES);
    this.setItem(STORAGE_KEYS.SUBJECTS, INITIAL_SUBJECTS);
    this.setItem(STORAGE_KEYS.STUDENTS, INITIAL_STUDENTS);
    this.setItem(STORAGE_KEYS.PAIRINGS, INITIAL_PAIRINGS);
    this.setItem(STORAGE_KEYS.SETTINGS, INITIAL_SCHOOL_SETTINGS);
    this.setItem(STORAGE_KEYS.ATTENDANCE, INITIAL_ATTENDANCE);
    this.setItem(STORAGE_KEYS.GRADE_ACTIVITIES, INITIAL_GRADE_ACTIVITIES);
    this.setItem(STORAGE_KEYS.GRADE_VALUES, INITIAL_GRADE_VALUES);
    this.setItem(STORAGE_KEYS.TOKENS, INITIAL_DELEGATION_TOKENS);
    this.setItem(STORAGE_KEYS.LOGS, INITIAL_AUDIT_LOGS);
    this.setItem(STORAGE_KEYS.CURRENT_USER_ID, 1);
  }
}

export const storage = new StorageManager();
