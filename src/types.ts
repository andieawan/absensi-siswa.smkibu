export type Role = 'guru' | 'admin' | 'superadmin' | 'kepsek' | 'bk';

export interface User {
  id: number;
  username: string;
  password_hash?: string;
  nama: string;
  kelas_wali_id: number | null;
  foto_profil_url?: string | null;
  is_active: boolean;
  created_at: string;
  roles: Role[];
  subjects?: number[]; // subject IDs taught
  classes?: number[];  // class IDs taught
}

export interface ClassItem {
  id: number;
  name: string;
  jurusan: string;
  angkatan: string;
  tahun_ajaran: string;
  semester: string;
}

export interface Subject {
  id: number;
  name: string;
}

export interface Student {
  id: number;
  nis: string; // VARCHAR as per PRD
  nama: string;
  jk: 'L' | 'P';
  class_id: number;
  status: 'aktif' | 'pindah' | 'berhenti' | 'nonaktif' | 'keluar';
  created_at: string;
}

export type AttendanceStatus = 'H' | 'I' | 'S' | 'A';

export interface AttendanceRecord {
  id: number;
  student_id: number;
  class_id: number;
  subject_id: number | null; // null for Absen Harian (Wali Kelas)
  tanggal: string; // YYYY-MM-DD
  status: AttendanceStatus;
  recorded_by: number;
  recorded_via: 'guru' | 'wali' | 'ketua_kelas_delegasi' | 'bk_manual' | 'upload_hardcopy';
  notes?: string;
  created_at: string;
  updated_at: string;
  created_at_millis?: number;
}

export interface GradeActivity {
  id: string; // UUID
  teacher_id: number;
  subject_id: number;
  class_id: number;
  nama_kegiatan: string;
  tanggal_kegiatan: string; // YYYY-MM-DD
  tipe_skala: 'angka' | 'huruf';
  created_at: string;
}

export interface GradeValue {
  activity_id: string;
  student_id: number;
  nilai: string; // "85" or "A"
}

export interface TeacherPairing {
  user_id: number;
  subject_id: number;
  class_id: number;
}

export interface KetuaKelasToken {
  token: string;
  class_id: number;
  status: 'aktif' | 'nonaktif' | 'kadaluarsa';
  created_at: string;
  created_by: number;
  expires_at?: string; // ISO string batas waktu (misal 24 jam)
  expires_at_millis?: number; // Epoch timestamp ms untuk validasi Firestore Rules & waktu server
}

export interface ParentAccessToken {
  token: string;
  student_id: number;
  status: 'aktif' | 'nonaktif';
  created_at: string;
  created_by: number;
  revoked_at?: string;
}

export interface AuditLogItem {
  id: number;
  username: string;
  aksi: string;
  modul: 'Absensi' | 'Nilai' | 'Siswa' | 'Akun Guru' | 'Sistem' | 'BK' | 'Backup';
  target: string;
  detail: string;
  created_at: string;
}

export interface SchoolSettings {
  school_name: string;
  logo_url: string;
  tahun_ajaran: string;
  semester: string;
  kepsek_nama: string;
  bk_nama: string;
  backup_retention_weeks: number;
  last_backup_date?: string;
  last_backup_status?: 'success' | 'failed';
}

export interface PeriodicPatternAlert {
  student_id: number;
  student_name: string;
  nis: string;
  day_of_week: string; // e.g. "Selasa"
  day_index: number;
  count: number;
  dates: string[];
  status_type: 'Peringatan' | 'Pola'; // 2 = Peringatan, 3+ = Pola
}

export interface AttentionStudent {
  student_id: number;
  student_name: string;
  nis: string;
  alpa_count: number;
  izin_count: number;
  sakit_count: number;
  total_absen: number;
  category: 'alpa_tinggi' | 'izin_tinggi' | 'sakit_tinggi' | 'jarang_masuk_gabungan';
  severity: number;
  hasGradeDrop?: boolean;
}
