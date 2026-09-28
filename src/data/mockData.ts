import {
  User,
  ClassItem,
  Subject,
  Student,
  AttendanceRecord,
  GradeActivity,
  GradeValue,
  TeacherPairing,
  AuditLogItem,
  SchoolSettings,
  KetuaKelasToken,
} from '../types';

export const INITIAL_CLASSES: ClassItem[] = [
  { id: 1, name: 'XI DKV 1', jurusan: 'Desain Komunikasi Visual', angkatan: '2024', tahun_ajaran: '2024/2025', semester: 'Genap' },
  { id: 2, name: 'X RPL 1', jurusan: 'Rekayasa Perangkat Lunak', angkatan: '2025', tahun_ajaran: '2024/2025', semester: 'Genap' },
  { id: 3, name: 'XII TKJ 2', jurusan: 'Teknik Komputer Jaringan', angkatan: '2023', tahun_ajaran: '2024/2025', semester: 'Genap' },
  { id: 4, name: 'X AKL 1', jurusan: 'Akuntansi & Keuangan Lembaga', angkatan: '2025', tahun_ajaran: '2024/2025', semester: 'Genap' },
];

export const INITIAL_SUBJECTS: Subject[] = [
  { id: 1, name: 'Bahasa Indonesia' },
  { id: 2, name: 'Matematika' },
  { id: 3, name: 'Pemrograman Web' },
  { id: 4, name: 'Desain Grafis Percetakan' },
  { id: 5, name: 'Bimbingan Konseling' },
];

// Password demo (HANYA untuk pengembangan/testing lokal, WAJIB diganti sebelum deployment sungguhan):
// pak.budi -> admin123 | ibu.siti -> guru123 | pak.hendra -> guru123 | bu.ratna -> kepsek123 | bu.maya -> bk123
// Hash di bawah adalah SHA-256+salt sungguhan dari password di atas (format "sha256:<salt>:<hash>"),
// BUKAN string yang menyisipkan password polos seperti sebelumnya.
export const INITIAL_USERS: User[] = [
  {
    id: 1,
    username: 'pak.budi',
    password_hash: 'sha256:277220ab059ce99329a328260655b391:78b3dbaccce3f4b5abb48fed65a3996e4baabe541c3f276bec4bfdd100b24c1d',
    nama: 'Budi Santoso, S.Pd., M.Kom.',
    kelas_wali_id: null,
    foto_profil_url: null,
    is_active: true,
    created_at: '2024-01-10 08:00:00',
    roles: ['superadmin', 'admin', 'guru'],
    subjects: [3, 4],
    classes: [1, 2, 3],
  },
  {
    id: 2,
    username: 'ibu.siti',
    password_hash: 'sha256:d93d421bedd86ddbc2990b79d713f757:cac9976e0518e6d070c8300599b85eb96789efa87300303bc722228889e82dd3',
    nama: 'Siti Aminah, S.Pd.',
    kelas_wali_id: 1, // Wali Kelas XI DKV 1
    foto_profil_url: null,
    is_active: true,
    created_at: '2024-01-10 08:30:00',
    roles: ['guru'],
    subjects: [1],
    classes: [1, 2],
  },
  {
    id: 3,
    username: 'pak.hendra',
    password_hash: 'sha256:c7b15b7772a3e7697b4a2027b687afe2:3b22d9389b5ebe25824661557915b31a7c364f26069127c6d86e96a4f4fe4ffc',
    nama: 'Hendra Pratama, S.Si.',
    kelas_wali_id: 2, // Wali Kelas X RPL 1
    foto_profil_url: null,
    is_active: true,
    created_at: '2024-01-11 09:00:00',
    roles: ['guru'],
    subjects: [2],
    classes: [1, 2, 3],
  },
  {
    id: 4,
    username: 'bu.ratna',
    password_hash: 'sha256:c2a1f379070c84693e60359cf63a14ea:348f8e873a8d6b1da662a0ed60f7f17f974ddb1477e1460709d8c639c91455f1',
    nama: 'Dra. Ratna Kusuma, M.Pd.',
    kelas_wali_id: null,
    foto_profil_url: null,
    is_active: true,
    created_at: '2024-01-08 07:45:00',
    roles: ['kepsek'],
    subjects: [],
    classes: [],
  },
  {
    id: 5,
    username: 'bu.maya',
    password_hash: 'sha256:65e8416f9557660b35c0accf0d513723:7040fd3563c716c160976e8fc48f4af9e57e046bf647b07aecf70c28e792e66b',
    nama: 'Maya Rosita, S.Psi.',
    kelas_wali_id: null,
    foto_profil_url: null,
    is_active: true,
    created_at: '2024-01-12 10:00:00',
    roles: ['bk'],
    subjects: [5],
    classes: [1, 2, 3, 4],
  },
];

export const INITIAL_STUDENTS: Student[] = [
  // XI DKV 1 (Class ID 1) - 16 Siswa
  { id: 1, nis: '24.01/DKV/001', nama: 'Achmad Fauzi Nugraha', jk: 'L', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 2, nis: '24.01/DKV/002', nama: 'Adinda Putri Maharani', jk: 'P', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 3, nis: '24.01/DKV/003', nama: 'Bayu Arya Pratama', jk: 'L', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 4, nis: '24.01/DKV/004', nama: 'Cantika Dewi Lestari', jk: 'P', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 5, nis: '24.01/DKV/005', nama: 'Dimas Kurnia Saputra', jk: 'L', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 6, nis: '24.01/DKV/006', nama: 'Eka Nurul Hidayah', jk: 'P', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 7, nis: '24.01/DKV/007', nama: 'Fajar Maulana Malik', jk: 'L', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 8, nis: '24.01/DKV/008', nama: 'Gita Permata Sari', jk: 'P', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 9, nis: '24.01/DKV/009', nama: 'Hafiz Rizky Ramadan', jk: 'L', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 10, nis: '24.01/DKV/010', nama: 'Indah Cahyaningrum', jk: 'P', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 11, nis: '24.01/DKV/011', nama: 'Kevin Julian Siregar', jk: 'L', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 12, nis: '24.01/DKV/012', nama: 'Laras Tri Utami', jk: 'P', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 13, nis: '24.01/DKV/013', nama: 'Muhammad Rizki Ilham', jk: 'L', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 14, nis: '24.01/DKV/014', nama: 'Nadia Safitri', jk: 'P', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 15, nis: '24.01/DKV/015', nama: 'Rendra Bagus Prakoso', jk: 'L', class_id: 1, status: 'aktif', created_at: '2024-07-15' },
  { id: 16, nis: '24.01/DKV/016', nama: 'Zahra Amelia Putri', jk: 'P', class_id: 1, status: 'aktif', created_at: '2024-07-15' },

  // X RPL 1 (Class ID 2) - 8 Siswa
  { id: 17, nis: '25.02/RPL/001', nama: 'Alif Danu Wibawa', jk: 'L', class_id: 2, status: 'aktif', created_at: '2025-07-14' },
  { id: 18, nis: '25.02/RPL/002', nama: 'Bunga Citra Kirana', jk: 'P', class_id: 2, status: 'aktif', created_at: '2025-07-14' },
  { id: 19, nis: '25.02/RPL/003', nama: 'Daffa Pratama Yudha', jk: 'L', class_id: 2, status: 'aktif', created_at: '2025-07-14' },
  { id: 20, nis: '25.02/RPL/004', nama: 'Farhan Nur Rochman', jk: 'L', class_id: 2, status: 'aktif', created_at: '2025-07-14' },
  { id: 21, nis: '25.02/RPL/005', nama: 'Intan Ayu Puspita', jk: 'P', class_id: 2, status: 'aktif', created_at: '2025-07-14' },
  { id: 22, nis: '25.02/RPL/006', nama: 'Rifqi Alfiansyah', jk: 'L', class_id: 2, status: 'aktif', created_at: '2025-07-14' },
  { id: 23, nis: '25.02/RPL/007', nama: 'Tiara Anindya Putri', jk: 'P', class_id: 2, status: 'aktif', created_at: '2025-07-14' },
  { id: 24, nis: '25.02/RPL/008', nama: 'Yoga Surya Kencana', jk: 'L', class_id: 2, status: 'aktif', created_at: '2025-07-14' },
];

// Granular Pairing: teacher_subject_class_pairing
export const INITIAL_PAIRINGS: TeacherPairing[] = [
  // Pak Budi: Pemrograman Web (3) only at XI DKV 1 (1) and XII TKJ 2 (3)
  { user_id: 1, subject_id: 3, class_id: 1 },
  { user_id: 1, subject_id: 3, class_id: 3 },
  // Pak Budi: Desain Grafis (4) only at XI DKV 1 (1)
  { user_id: 1, subject_id: 4, class_id: 1 },
  // Ibu Siti: Bahasa Indonesia (1) at XI DKV 1 (1) and X RPL 1 (2)
  { user_id: 2, subject_id: 1, class_id: 1 },
  { user_id: 2, subject_id: 1, class_id: 2 },
  // Pak Hendra: Matematika (2) at XI DKV 1 (1) and X RPL 1 (2)
  { user_id: 3, subject_id: 2, class_id: 1 },
  { user_id: 3, subject_id: 2, class_id: 2 },
];

export const INITIAL_SCHOOL_SETTINGS: SchoolSettings = {
  school_name: 'SMK Negeri 1 Prestasi Bangsa',
  logo_url: 'https://images.unsplash.com/photo-1546410531-bb4caa6b424d?w=120&auto=format&fit=crop&q=80',
  tahun_ajaran: '2024/2025',
  semester: 'Genap',
  kepsek_nama: 'Dra. Ratna Kusuma, M.Pd.',
  bk_nama: 'Maya Rosita, S.Psi.',
  backup_retention_weeks: 8,
  last_backup_date: '2026-09-21 02:00:00',
  last_backup_status: 'success',
};

// Seed Attendance Records with intentional real-world patterns:
// Student 5 (Dimas Kurnia Saputra): Absent on Tuesday 2026-09-08 and Tuesday 2026-09-22 (exactly 14 days interval!) -> Pola Berkala
// Student 11 (Kevin Julian Siregar): High Alpa (3+ Alpa)
// Student 7 (Fajar Maulana): High Sakit (3+ Sakit)
// Student 15 (Rendra Bagus): High Izin & Alpa, combined attendance drop & low grades!
export const INITIAL_ATTENDANCE: AttendanceRecord[] = [
  // Dates:
  // 2026-09-07 (Senin) - Absen Harian XI DKV 1
  ...Array.from({ length: 16 }).map((_, i) => ({
    id: 100 + i,
    student_id: i + 1,
    class_id: 1,
    subject_id: null, // Absen Harian
    tanggal: '2026-09-07',
    status: (i === 10 ? 'A' : i === 6 ? 'S' : 'H') as 'H' | 'I' | 'S' | 'A',
    recorded_by: 2, // Ibu Siti
    recorded_via: 'wali' as const,
    created_at: '2026-09-07 07:30:00',
    updated_at: '2026-09-07 07:30:00',
  })),

  // 2026-09-08 (Selasa) - Absen Harian XI DKV 1
  ...Array.from({ length: 16 }).map((_, i) => ({
    id: 120 + i,
    student_id: i + 1,
    class_id: 1,
    subject_id: null,
    tanggal: '2026-09-08',
    // Student 5 (Dimas) absent here!
    status: (i === 4 ? 'A' : i === 14 ? 'I' : 'H') as 'H' | 'I' | 'S' | 'A',
    recorded_by: 2,
    recorded_via: 'wali' as const,
    created_at: '2026-09-08 07:35:00',
    updated_at: '2026-09-08 07:35:00',
  })),

  // 2026-09-15 (Selasa) - Absen Harian XI DKV 1 (1 week later)
  ...Array.from({ length: 16 }).map((_, i) => ({
    id: 140 + i,
    student_id: i + 1,
    class_id: 1,
    subject_id: null,
    tanggal: '2026-09-15',
    status: (i === 10 ? 'A' : i === 6 ? 'S' : 'H') as 'H' | 'I' | 'S' | 'A',
    recorded_by: 2,
    recorded_via: 'wali' as const,
    created_at: '2026-09-15 07:25:00',
    updated_at: '2026-09-15 07:25:00',
  })),

  // 2026-09-22 (Selasa) - Absen Harian XI DKV 1 (exactly 14 days after 2026-09-08)
  ...Array.from({ length: 16 }).map((_, i) => ({
    id: 160 + i,
    student_id: i + 1,
    class_id: 1,
    subject_id: null,
    tanggal: '2026-09-22',
    // Student 5 (Dimas) absent again on Tuesday! -> triggers Pola Berkala (14 days apart)
    // Student 14 (Rendra) also absent
    status: (i === 4 ? 'A' : i === 14 ? 'A' : i === 6 ? 'S' : 'H') as 'H' | 'I' | 'S' | 'A',
    recorded_by: 2,
    recorded_via: 'wali' as const,
    created_at: '2026-09-22 07:40:00',
    updated_at: '2026-09-22 07:40:00',
  })),

  // 2026-09-25 (Jumat) - Absen Harian XI DKV 1
  ...Array.from({ length: 16 }).map((_, i) => ({
    id: 180 + i,
    student_id: i + 1,
    class_id: 1,
    subject_id: null,
    tanggal: '2026-09-25',
    status: (i === 10 ? 'A' : i === 14 ? 'I' : 'H') as 'H' | 'I' | 'S' | 'A',
    recorded_by: 2,
    recorded_via: 'wali' as const,
    created_at: '2026-09-25 07:30:00',
    updated_at: '2026-09-25 07:30:00',
  })),

  // Today/Yesterday: 2026-09-26 (Sabtu) - Mapel Pemrograman Web (Pak Budi) XI DKV 1
  ...Array.from({ length: 16 }).map((_, i) => ({
    id: 200 + i,
    student_id: i + 1,
    class_id: 1,
    subject_id: 3, // Pemrograman Web
    tanggal: '2026-09-26',
    status: (i === 10 ? 'A' : i === 14 ? 'A' : i === 2 ? 'I' : 'H') as 'H' | 'I' | 'S' | 'A',
    recorded_by: 1, // Pak Budi
    recorded_via: 'guru' as const,
    created_at: '2026-09-26 09:15:00',
    updated_at: '2026-09-26 09:15:00',
  })),

  // X RPL 1 - Absen Harian (Wali Kelas Pak Hendra) for 2026-09-25
  ...Array.from({ length: 8 }).map((_, i) => ({
    id: 300 + i,
    student_id: 17 + i,
    class_id: 2,
    subject_id: null,
    tanggal: '2026-09-25',
    status: (i === 2 ? 'S' : 'H') as 'H' | 'I' | 'S' | 'A',
    recorded_by: 3,
    recorded_via: 'wali' as const,
    created_at: '2026-09-25 07:45:00',
    updated_at: '2026-09-25 07:45:00',
  })),
];

// Grade activities
export const INITIAL_GRADE_ACTIVITIES: GradeActivity[] = [
  {
    id: 'act-101-logo',
    teacher_id: 1,
    subject_id: 3,
    class_id: 1,
    nama_kegiatan: 'Tugas 1: Desain Vektor Logo Interaktif',
    tanggal_kegiatan: '2026-09-18',
    tipe_skala: 'angka',
    created_at: '2026-09-18 10:00:00',
  },
  {
    id: 'act-102-uh1',
    teacher_id: 1,
    subject_id: 3,
    class_id: 1,
    nama_kegiatan: 'Ulangan Harian 1: Sintaks CSS Grid & Responsif',
    tanggal_kegiatan: '2026-09-24',
    tipe_skala: 'angka',
    created_at: '2026-09-24 11:30:00',
  },
  {
    id: 'act-103-praktik',
    teacher_id: 1,
    subject_id: 4,
    class_id: 1,
    nama_kegiatan: 'Portofolio Kemasan Produk (Praktik Cetak)',
    tanggal_kegiatan: '2026-09-20',
    tipe_skala: 'huruf',
    created_at: '2026-09-20 13:00:00',
  },
];

// Grade values
export const INITIAL_GRADE_VALUES: GradeValue[] = [
  // Activity 1: Tugas 1 Desain Vektor Logo
  { activity_id: 'act-101-logo', student_id: 1, nilai: '88' },
  { activity_id: 'act-101-logo', student_id: 2, nilai: '92' },
  { activity_id: 'act-101-logo', student_id: 3, nilai: '80' },
  { activity_id: 'act-101-logo', student_id: 4, nilai: '85' },
  { activity_id: 'act-101-logo', student_id: 5, nilai: '75' },
  { activity_id: 'act-101-logo', student_id: 6, nilai: '90' },
  { activity_id: 'act-101-logo', student_id: 7, nilai: '78' },
  { activity_id: 'act-101-logo', student_id: 8, nilai: '94' },
  { activity_id: 'act-101-logo', student_id: 9, nilai: '82' },
  { activity_id: 'act-101-logo', student_id: 10, nilai: '86' },
  { activity_id: 'act-101-logo', student_id: 11, nilai: '55' }, // Drop Kevin
  { activity_id: 'act-101-logo', student_id: 12, nilai: '88' },
  { activity_id: 'act-101-logo', student_id: 13, nilai: '84' },
  { activity_id: 'act-101-logo', student_id: 14, nilai: '89' },
  { activity_id: 'act-101-logo', student_id: 15, nilai: '50' }, // Drop Rendra (both attendance & grade drop!)
  { activity_id: 'act-101-logo', student_id: 16, nilai: '91' },

  // Activity 2: UH 1 CSS Grid
  { activity_id: 'act-102-uh1', student_id: 1, nilai: '85' },
  { activity_id: 'act-102-uh1', student_id: 2, nilai: '90' },
  { activity_id: 'act-102-uh1', student_id: 3, nilai: '78' },
  { activity_id: 'act-102-uh1', student_id: 4, nilai: '82' },
  { activity_id: 'act-102-uh1', student_id: 5, nilai: '70' },
  { activity_id: 'act-102-uh1', student_id: 6, nilai: '88' },
  { activity_id: 'act-102-uh1', student_id: 7, nilai: '74' },
  { activity_id: 'act-102-uh1', student_id: 8, nilai: '92' },
  { activity_id: 'act-102-uh1', student_id: 9, nilai: '80' },
  { activity_id: 'act-102-uh1', student_id: 10, nilai: '85' },
  { activity_id: 'act-102-uh1', student_id: 11, nilai: '52' }, // Low
  { activity_id: 'act-102-uh1', student_id: 12, nilai: '87' },
  { activity_id: 'act-102-uh1', student_id: 13, nilai: '81' },
  { activity_id: 'act-102-uh1', student_id: 14, nilai: '89' },
  { activity_id: 'act-102-uh1', student_id: 15, nilai: '48' }, // Drop Rendra
  { activity_id: 'act-102-uh1', student_id: 16, nilai: '90' },

  // Activity 3: Praktik Portofolio (Huruf A-E)
  { activity_id: 'act-103-praktik', student_id: 1, nilai: 'A' },
  { activity_id: 'act-103-praktik', student_id: 2, nilai: 'A' },
  { activity_id: 'act-103-praktik', student_id: 3, nilai: 'B' },
  { activity_id: 'act-103-praktik', student_id: 4, nilai: 'B' },
  { activity_id: 'act-103-praktik', student_id: 5, nilai: 'C' },
  { activity_id: 'act-103-praktik', student_id: 6, nilai: 'A' },
  { activity_id: 'act-103-praktik', student_id: 7, nilai: 'B' },
  { activity_id: 'act-103-praktik', student_id: 8, nilai: 'A' },
  { activity_id: 'act-103-praktik', student_id: 9, nilai: 'B' },
  { activity_id: 'act-103-praktik', student_id: 10, nilai: 'B' },
  { activity_id: 'act-103-praktik', student_id: 11, nilai: 'D' },
  { activity_id: 'act-103-praktik', student_id: 12, nilai: 'A' },
  { activity_id: 'act-103-praktik', student_id: 13, nilai: 'B' },
  { activity_id: 'act-103-praktik', student_id: 14, nilai: 'A' },
  { activity_id: 'act-103-praktik', student_id: 15, nilai: 'E' },
  { activity_id: 'act-103-praktik', student_id: 16, nilai: 'A' },
];

export const INITIAL_DELEGATION_TOKENS: KetuaKelasToken[] = [
  {
    token: 'token-dkv1-ketua-2026',
    class_id: 1,
    status: 'aktif',
    created_at: '2026-09-20 08:00:00',
    created_by: 2, // Wali Kelas Ibu Siti
  },
];

export const INITIAL_AUDIT_LOGS: AuditLogItem[] = [
  {
    id: 1,
    username: 'pak.budi',
    aksi: 'Inisialisasi Sistem',
    modul: 'Sistem',
    target: 'Database Migrasi MySQL',
    detail: 'Skema relasional berhasil dimigrasikan dari Google Apps Script ke MySQL fisik.',
    created_at: '2026-09-01 08:00:00',
  },
  {
    id: 2,
    username: 'ibu.siti',
    aksi: 'Submit Absensi',
    modul: 'Absensi',
    target: 'XI DKV 1 - Absen Harian',
    detail: '16 siswa diabsen untuk tanggal 2026-09-07. Hadir: 14, Sakit: 1, Alpa: 1.',
    created_at: '2026-09-07 07:30:15',
  },
  {
    id: 3,
    username: 'pak.budi',
    aksi: 'Simpan Kegiatan Nilai',
    modul: 'Nilai',
    target: 'Tugas 1: Desain Vektor Logo Interaktif',
    detail: 'Membuat penilaian baru skala angka untuk XI DKV 1 (16 entri tersimpan).',
    created_at: '2026-09-18 10:00:40',
  },
  {
    id: 4,
    username: 'ibu.siti',
    aksi: 'Buat Delegasi',
    modul: 'Absensi',
    target: 'Token Delegasi Ketua Kelas XI DKV 1',
    detail: 'Membuat tautan akses mandiri ketua kelas untuk input absen harian.',
    created_at: '2026-09-20 08:00:00',
  },
  {
    id: 5,
    username: 'bu.maya',
    aksi: 'Input Absensi Manual BK',
    modul: 'BK',
    target: 'Siswa: Kevin Julian Siregar',
    detail: 'Tindak lanjut ketidakhadiran berturut-turut, konseling kehadiran dilakukan.',
    created_at: '2026-09-22 11:20:00',
  },
];
