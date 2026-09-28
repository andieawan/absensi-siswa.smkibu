import React, { useState, useMemo, useEffect } from 'react';
import { User, Student, ClassItem, Subject, TeacherPairing, AuditLogItem, Role, SchoolSettings } from '../types';
import { storage } from '../services/storage';
import { authService } from '../services/auth';
import { apiFetch } from '../services/authToken';
import { generateHardcopyTemplate, parseHardcopyUpload, HardcopyPreviewResult } from '../utils/excel';
import { GoogleSheetsModal } from './GoogleSheetsModal';
import {
  Users,
  GraduationCap,
  ShieldCheck,
  FileSpreadsheet,
  History,
  Settings,
  Database,
  Plus,
  KeyRound,
  Download,
  Upload,
  AlertTriangle,
  CheckCircle2,
  Lock,
  Loader2,
  ServerCog,
} from 'lucide-react';

interface AdminPanelViewProps {
  currentUser: User;
  classes: ClassItem[];
  subjects: Subject[];
  onRefreshData?: () => void;
}

export const AdminPanelView: React.FC<AdminPanelViewProps> = ({
  currentUser,
  classes,
  subjects,
  onRefreshData,
}) => {
  const isSuperadmin = currentUser.roles.includes('superadmin');

  // Sub-tabs: 'teachers' | 'students' | 'pairings' | 'hardcopy' | 'audit' | 'settings'
  const [activeSubTab, setActiveSubTab] = useState<
    'teachers' | 'students' | 'pairings' | 'hardcopy' | 'audit' | 'settings'
  >('teachers');

  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [showGoogleSheetsBackupModal, setShowGoogleSheetsBackupModal] = useState<boolean>(false);

  // --- SUB-MODULE 1: KELOLA AKUN GURU ---
  const allUsers = storage.getUsers();
  const [showAddTeacherModal, setShowAddTeacherModal] = useState<boolean>(false);
  const [newTeacherName, setNewTeacherName] = useState<string>('');
  const [newTeacherUsername, setNewTeacherUsername] = useState<string>('');
  const [newTeacherPassword, setNewTeacherPassword] = useState<string>('Guru12345!');
  const [newTeacherRoles, setNewTeacherRoles] = useState<Role[]>(['guru']);
  const [newTeacherWaliClass, setNewTeacherWaliClass] = useState<number | null>(null);
  const [newTeacherSubjects, setNewTeacherSubjects] = useState<number[]>([1]);
  const [newTeacherClasses, setNewTeacherClasses] = useState<number[]>([1, 2]);

  // Reset password modal
  const [resettingUser, setResettingUser] = useState<User | null>(null);
  const [newPasswordVal, setNewPasswordVal] = useState<string>('');

  const handleCreateTeacher = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const rawPassword = newTeacherPassword.trim();
      if (!rawPassword) {
        setFeedback({ type: 'error', text: 'Password awal untuk akun guru wajib diisi.' });
        return;
      }
      const pHash = await authService.hashPassword(rawPassword);
      storage.addUser({
        nama: newTeacherName.trim(),
        username: newTeacherUsername.trim().toLowerCase(),
        password_hash: pHash,
        is_active: true,
        roles: newTeacherRoles,
        kelas_wali_id: newTeacherWaliClass,
        subjects: newTeacherSubjects,
        classes: newTeacherClasses,
      });
      setShowAddTeacherModal(false);
      setFeedback({ type: 'success', text: `Akun guru ${newTeacherName} berhasil ditambahkan.` });
      onRefreshData?.();
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message });
    }
  };

  const handleResetPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!resettingUser || !newPasswordVal.trim()) return;
    const pHash = await authService.hashPassword(newPasswordVal.trim());
    storage.updateUserPassword(resettingUser.id, pHash);
    // Password reset is deliberately sanitized in audit log (PRD 9.2)
    storage.addAuditLog('Reset Password Guru', 'Akun Guru', resettingUser.nama, `Reset password dilakukan oleh ${currentUser.username}`);
    setResettingUser(null);
    setNewPasswordVal('');
    setFeedback({ type: 'success', text: `Password untuk ${resettingUser.nama} berhasil direset (disimpan dengan hash aman).` });
    onRefreshData?.();
  };

  const handleToggleUserActive = (user: User) => {
    const nextStatus = !user.is_active;
    storage.updateUser(user.id, { is_active: nextStatus });
    setFeedback({
      type: 'success',
      text: `Status akun ${user.nama} diubah menjadi ${nextStatus ? 'Aktif' : 'Nonaktif'}.`,
    });
    onRefreshData?.();
  };

  // --- SUB-MODULE 2: KELOLA DATA SISWA ---
  const allStudents = storage.getStudents();
  const [studentClassFilter, setStudentClassFilter] = useState<number>(0);
  const [studentSearch, setStudentSearch] = useState<string>('');
  const [showAddStudentModal, setShowAddStudentModal] = useState<boolean>(false);
  const [newStudentNis, setNewStudentNis] = useState<string>('');
  const [newStudentNama, setNewStudentNama] = useState<string>('');
  const [newStudentJk, setNewStudentJk] = useState<'L' | 'P'>('L');
  const [newStudentClassId, setNewStudentClassId] = useState<number>(classes[0]?.id || 1);

  // Edit student modal
  const [editingStudent, setEditingStudent] = useState<Student | null>(null);
  const [editStudentNama, setEditStudentNama] = useState<string>('');
  const [editStudentJk, setEditStudentJk] = useState<'L' | 'P'>('L');
  const [editStudentStatus, setEditStudentStatus] = useState<Student['status']>('aktif');
  const [editStudentClassId, setEditStudentClassId] = useState<number>(1);

  const filteredStudents = useMemo(() => {
    return allStudents.filter((s) => {
      if (studentClassFilter !== 0 && s.class_id !== studentClassFilter) return false;
      if (studentSearch.trim()) {
        const q = studentSearch.toLowerCase();
        return s.nama.toLowerCase().includes(q) || s.nis.toLowerCase().includes(q);
      }
      return true;
    });
  }, [allStudents, studentClassFilter, studentSearch]);

  const handleCreateStudent = (e: React.FormEvent) => {
    e.preventDefault();
    try {
      storage.addStudent({
        nis: newStudentNis.trim(),
        nama: newStudentNama.trim(),
        jk: newStudentJk,
        class_id: newStudentClassId,
        status: 'aktif',
      });
      setShowAddStudentModal(false);
      setNewStudentNis('');
      setNewStudentNama('');
      setFeedback({ type: 'success', text: `Siswa baru ${newStudentNama} berhasil didaftarkan.` });
      onRefreshData?.();
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message });
    }
  };

  const handleSaveStudentEdit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingStudent) return;
    try {
      storage.updateStudent(editingStudent.id, {
        nama: editStudentNama.trim(),
        jk: editStudentJk,
        status: editStudentStatus,
        class_id: editStudentClassId,
      });
      setEditingStudent(null);
      setFeedback({ type: 'success', text: `Data siswa ${editStudentNama} berhasil diperbarui.` });
      onRefreshData?.();
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message });
    }
  };

  // --- SUB-MODULE 3: PASANGAN MAPEL-KELAS (OTORISASI GRANULAR PRD 6.6) ---
  const allPairings = storage.getPairings();
  const [selectedPairingTeacherId, setSelectedPairingTeacherId] = useState<number>(1);
  const [pairingSubjectId, setPairingSubjectId] = useState<number>(3);
  const [pairingClassId, setPairingClassId] = useState<number>(1);

  const teacherPairings = useMemo(() => {
    return allPairings.filter((p) => p.user_id === selectedPairingTeacherId);
  }, [allPairings, selectedPairingTeacherId]);

  const handleAddPairing = () => {
    const exists = allPairings.some(
      (p) =>
        p.user_id === selectedPairingTeacherId &&
        p.subject_id === pairingSubjectId &&
        p.class_id === pairingClassId
    );
    if (exists) {
      alert('Kombinasi pasangan ini sudah terdaftar.');
      return;
    }
    const updated = [...allPairings, { user_id: selectedPairingTeacherId, subject_id: pairingSubjectId, class_id: pairingClassId }];
    storage.savePairings(updated);
    setFeedback({ type: 'success', text: 'Pasangan otorisasi berhasil ditambahkan.' });
  };

  const handleRemovePairing = (p: TeacherPairing) => {
    const updated = allPairings.filter(
      (item) =>
        !(item.user_id === p.user_id && item.subject_id === p.subject_id && item.class_id === p.class_id)
    );
    storage.savePairings(updated);
    setFeedback({ type: 'success', text: 'Pasangan otorisasi berhasil dihapus.' });
  };

  // --- SUB-MODULE 4: UPLOAD ABSENSI HARDCOPY (PRD 6.7) ---
  const [hardcopyClassId, setHardcopyClassId] = useState<number>(1);
  const [hardcopyDate, setHardcopyDate] = useState<string>(() => new Date().toISOString().substring(0, 10));
  const [hardcopyPreview, setHardcopyPreview] = useState<HardcopyPreviewResult | null>(null);

  const handleDownloadHardcopyTemplate = () => {
    const classObj = classes.find((c) => c.id === hardcopyClassId);
    const studentsInClass = storage.getStudents().filter((s) => s.class_id === hardcopyClassId && s.status === 'aktif');
    generateHardcopyTemplate(classObj?.name || 'Kelas', studentsInClass, hardcopyDate);
  };

  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const studentsInClass = storage.getStudents().filter((s) => s.class_id === hardcopyClassId && s.status === 'aktif');
    try {
      const preview = await parseHardcopyUpload(file, studentsInClass, hardcopyDate);
      setHardcopyPreview(preview);
    } catch (err: any) {
      alert('Gagal membaca file template: ' + err.message);
    }
  };

  const handleCommitHardcopyImport = () => {
    if (!hardcopyPreview) return;
    const studentsInClass = storage.getStudents().filter((s) => s.class_id === hardcopyClassId);
    const studentMap = new Map(studentsInClass.map((s) => [s.nis.trim().toLowerCase(), s]));

    const validEntries = hardcopyPreview.entries
      .filter((r) => r.isValidStudent && r.status !== 'INVALID')
      .map((r) => {
        const st = studentMap.get(r.nis.toLowerCase())!;
        return {
          student_id: st.id,
          status: r.status as 'H' | 'I' | 'S' | 'A',
          notes: r.notes || 'Impor dari formulir fisik hardcopy',
        };
      });

    if (validEntries.length === 0) {
      alert('Tidak ada baris valid untuk diimpor.');
      return;
    }

    try {
      const res = storage.submitAttendanceBatch({
        class_id: hardcopyClassId,
        subject_id: null, // Absen harian
        tanggal: hardcopyDate,
        recorded_by: currentUser.id,
        recorded_via: 'upload_hardcopy',
        entries: validEntries,
      });

      setFeedback({
        type: 'success',
        text: `Berhasil mengimpor ${res.count} presensi dari hardcopy (${res.created} baru, ${res.updated} terbarui)!`,
      });
      setHardcopyPreview(null);
    } catch (err: any) {
      alert(err.message);
    }
  };

  // --- SUB-MODULE 5: AUDIT LOG ---
  const allLogs = storage.getAuditLogs();
  const [logModuleFilter, setLogModuleFilter] = useState<string>('all');
  const [logSearch, setLogSearch] = useState<string>('');

  const filteredLogs = useMemo(() => {
    return allLogs.filter((log) => {
      if (logModuleFilter !== 'all' && log.modul !== logModuleFilter) return false;
      if (logSearch.trim()) {
        const q = logSearch.toLowerCase();
        return (
          log.username.toLowerCase().includes(q) ||
          log.aksi.toLowerCase().includes(q) ||
          log.target.toLowerCase().includes(q) ||
          log.detail.toLowerCase().includes(q)
        );
      }
      return true;
    });
  }, [allLogs, logModuleFilter, logSearch]);

  // --- SUB-MODULE 6: PENGATURAN SEKOLAH & BACKUP DATABASE ---
  const currentSettings = storage.getSettings();
  const [schoolName, setSchoolName] = useState<string>(currentSettings.school_name);
  const [schoolYear, setSchoolYear] = useState<string>(currentSettings.tahun_ajaran);
  const [schoolSemester, setSchoolSemester] = useState<string>(currentSettings.semester);

  const handleSaveSettings = (e: React.FormEvent) => {
    e.preventDefault();
    storage.updateSettings({
      school_name: schoolName,
      tahun_ajaran: schoolYear,
      semester: schoolSemester,
    });
    setFeedback({ type: 'success', text: 'Pengaturan sekolah berhasil diperbarui.' });
  };

  const handleDownloadSqlDump = () => {
    const dump = storage.generateSqlDump();
    const blob = new Blob([dump], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `backup_go_absen_siswa_${new Date().toISOString().substring(0, 10)}.sql`;
    a.click();
    URL.revokeObjectURL(url);
    storage.addAuditLog('Backup Database SQL', 'Backup', 'MySQL Dump', 'Pengunduhan file dump relasional database');
  };

  const handleDownloadJsonBackup = () => {
    const data = storage.getBackupSnapshot();
    storage.triggerAutomaticJsonBackupDownload(data, 'backup_full_json');
    storage.addAuditLog('Backup JSON', 'Backup', 'Full Export', 'Pengunduhan arsip terstruktur JSON');
  };

  // Status backup SERVER (SQLite snapshot terjadwal, lihat server/backup.ts) —
  // ini terpisah dari tombol unduh SQL/JSON di atas yang cuma menyalin data ke
  // perangkat guru yang sedang login. Ambil langsung dari server (bukan hanya
  // dari storage lokal yang mungkin belum sinkron) supaya statusnya akurat.
  const [serverBackupSettings, setServerBackupSettings] = useState<SchoolSettings | null>(null);
  const [isServerBackupLoading, setIsServerBackupLoading] = useState<boolean>(true);
  const [isRunningServerBackup, setIsRunningServerBackup] = useState<boolean>(false);

  const refreshServerBackupStatus = async () => {
    setIsServerBackupLoading(true);
    try {
      const res = await apiFetch('/api/settings');
      const data = await res.json().catch(() => null);
      if (res.ok && data?.success && data.data) {
        setServerBackupSettings(data.data as SchoolSettings);
      }
    } catch (err) {
      console.warn('[AdminPanel] Gagal memuat status backup server:', err);
    } finally {
      setIsServerBackupLoading(false);
    }
  };

  useEffect(() => {
    refreshServerBackupStatus();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleServerBackupNow = async () => {
    setIsRunningServerBackup(true);
    try {
      const res = await apiFetch('/api/admin/backup-now', { method: 'POST' });
      const data = await res.json().catch(() => null);
      if (res.ok && data?.success) {
        setServerBackupSettings(data.settings as SchoolSettings);
        setFeedback({ type: 'success', text: `Backup database server berhasil dibuat: ${data.file}` });
      } else {
        setFeedback({ type: 'error', text: data?.error || 'Gagal membuat backup database server.' });
      }
    } catch (err: any) {
      setFeedback({ type: 'error', text: err?.message || 'Server tidak dapat dihubungi untuk memulai backup.' });
    } finally {
      setIsRunningServerBackup(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Admin Panel Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200">
        <div>
          <div className="flex items-center gap-2">
            <ShieldCheck className="w-5 h-5 text-indigo-700" />
            <h1 className="text-xl font-bold tracking-tight text-slate-900">
              Panel Administrasi Sekolah
            </h1>
          </div>
          <p className="text-xs text-slate-500 mt-1">
            Pengelolaan akun guru, master siswa, otorisasi pasangan mapel, impor hardcopy, audit log, dan backup server.
          </p>
        </div>

        {/* Sub-tab navigation */}
        <div className="inline-flex p-1 bg-slate-100 rounded-lg text-xs font-medium overflow-x-auto max-w-full">
          <button
            onClick={() => setActiveSubTab('teachers')}
            className={`px-3 py-1.5 rounded-md whitespace-nowrap transition-colors ${
              activeSubTab === 'teachers' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            Akun Guru
          </button>
          <button
            onClick={() => setActiveSubTab('students')}
            className={`px-3 py-1.5 rounded-md whitespace-nowrap transition-colors ${
              activeSubTab === 'students' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            Data Siswa
          </button>
          <button
            onClick={() => setActiveSubTab('pairings')}
            className={`px-3 py-1.5 rounded-md whitespace-nowrap transition-colors ${
              activeSubTab === 'pairings' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            Pasangan Mapel
          </button>
          <button
            onClick={() => setActiveSubTab('hardcopy')}
            className={`px-3 py-1.5 rounded-md whitespace-nowrap transition-colors ${
              activeSubTab === 'hardcopy' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            Upload Hardcopy
          </button>
          <button
            onClick={() => setActiveSubTab('audit')}
            className={`px-3 py-1.5 rounded-md whitespace-nowrap transition-colors ${
              activeSubTab === 'audit' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            Log Aktivitas
          </button>
          <button
            onClick={() => setActiveSubTab('settings')}
            className={`px-3 py-1.5 rounded-md whitespace-nowrap transition-colors ${
              activeSubTab === 'settings' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            Pengaturan & Backup
          </button>
        </div>
      </div>

      {/* Feedback banner */}
      {feedback && (
        <div
          className={`flex items-center gap-2 p-3 text-xs rounded-lg ${
            feedback.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
              : 'bg-rose-50 text-rose-800 border border-rose-200'
          }`}
        >
          {feedback.type === 'success' ? (
            <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
          ) : (
            <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
          )}
          <span>{feedback.text}</span>
        </div>
      )}

      {/* 1. KELOLA AKUN GURU */}
      {activeSubTab === 'teachers' && (
        <div className="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-sm font-bold text-slate-900">Daftar Akun Guru & Staf</h2>
              <p className="text-xs text-slate-500">
                Total {allUsers.length} akun terdaftar di sistem sekolah.
              </p>
            </div>
            <button
              onClick={() => setShowAddTeacherModal(true)}
              className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors"
            >
              <Plus className="w-3.5 h-3.5" />
              + Tambah Akun Guru
            </button>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-xs text-left border-collapse">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-slate-500 font-medium">
                  <th className="py-2.5 px-3">Nama Lengkap</th>
                  <th className="py-2.5 px-3">Username</th>
                  <th className="py-2.5 px-3">Peran (Roles)</th>
                  <th className="py-2.5 px-3">Wali Kelas</th>
                  <th className="py-2.5 px-3 text-center">Status</th>
                  <th className="py-2.5 px-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {allUsers.map((u) => {
                  const waliClass = classes.find((c) => c.id === u.kelas_wali_id);
                  return (
                    <tr key={u.id} className="hover:bg-slate-50/80 transition-colors">
                      <td className="py-2.5 px-3 font-medium text-slate-900">{u.nama}</td>
                      <td className="py-2.5 px-3 font-mono text-slate-600">{u.username}</td>
                      <td className="py-2.5 px-3">
                        <div className="flex flex-wrap gap-1">
                          {u.roles.map((r) => (
                            <span
                              key={r}
                              className="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 font-mono font-medium"
                            >
                              {r}
                            </span>
                          ))}
                        </div>
                      </td>
                      <td className="py-2.5 px-3 text-slate-700">
                        {waliClass ? (
                          <span className="font-medium text-indigo-700">{waliClass.name}</span>
                        ) : (
                          <span className="text-slate-400">-</span>
                        )}
                      </td>
                      <td className="py-2.5 px-3 text-center">
                        <span
                          className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${
                            u.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
                          }`}
                        >
                          {u.is_active ? 'Aktif' : 'Nonaktif'}
                        </span>
                      </td>
                      <td className="py-2.5 px-3 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            onClick={() => {
                              setResettingUser(u);
                              setNewPasswordVal('GuruBaru2026!');
                            }}
                            className="p-1 text-slate-500 hover:text-slate-900 rounded hover:bg-slate-100"
                            title="Reset Password"
                          >
                            <KeyRound className="w-3.5 h-3.5" />
                          </button>
                          <button
                            onClick={() => handleToggleUserActive(u)}
                            className="text-[11px] text-slate-600 hover:text-slate-900 underline"
                          >
                            {u.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* 2. KELOLA DATA SISWA */}
      {activeSubTab === 'students' && (
        <div className="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 className="text-sm font-bold text-slate-900">Kelola Data Siswa</h2>
              <p className="text-xs text-slate-500">
                NIS bersifat permanen sebagai kunci referensi riwayat. Penghapusan baris dinonaktifkan demi integritas data historis.
              </p>
            </div>
            <button
              onClick={() => setShowAddStudentModal(true)}
              className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors"
            >
              <Plus className="w-3.5 h-3.5" />
              + Tambah Siswa Baru
            </button>
          </div>

          {/* Filters */}
          <div className="flex flex-wrap items-center gap-3">
            <select
              value={studentClassFilter}
              onChange={(e) => setStudentClassFilter(Number(e.target.value))}
              aria-label="Filter Berdasarkan Kelas"
              className="text-xs bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-slate-800"
            >
              <option value={0}>Semua Kelas</option>
              {classes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>

            <input
              type="text"
              placeholder="Cari NIS / Nama siswa..."
              value={studentSearch}
              onChange={(e) => setStudentSearch(e.target.value)}
              className="text-xs bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-slate-800 w-52"
            />
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-xs text-left border-collapse">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-slate-500 font-medium">
                  <th className="py-2.5 px-3 w-10 text-center">No</th>
                  <th className="py-2.5 px-3 w-36">NIS (Permanen)</th>
                  <th className="py-2.5 px-3">Nama Siswa</th>
                  <th className="py-2.5 px-3 w-12 text-center">JK</th>
                  <th className="py-2.5 px-3">Kelas</th>
                  <th className="py-2.5 px-3 text-center">Status</th>
                  <th className="py-2.5 px-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredStudents.map((s, idx) => {
                  const sClass = classes.find((c) => c.id === s.class_id);
                  return (
                    <tr key={s.id} className="hover:bg-slate-50/80 transition-colors">
                      <td className="py-2.5 px-3 text-center font-mono text-slate-400">
                        {idx + 1}
                      </td>
                      <td className="py-2.5 px-3 font-mono font-medium text-slate-800">{s.nis}</td>
                      <td className="py-2.5 px-3 font-semibold text-slate-900">{s.nama}</td>
                      <td className="py-2.5 px-3 text-center font-mono text-slate-500">{s.jk}</td>
                      <td className="py-2.5 px-3 text-slate-700">{sClass?.name}</td>
                      <td className="py-2.5 px-3 text-center">
                        <span
                          className={`text-[10px] font-bold px-2 py-0.5 rounded-full capitalize ${
                            s.status === 'aktif'
                              ? 'bg-emerald-50 text-emerald-700'
                              : 'bg-slate-100 text-slate-600'
                          }`}
                        >
                          {s.status}
                        </span>
                      </td>
                      <td className="py-2.5 px-3 text-right">
                        <button
                          onClick={() => {
                            setEditingStudent(s);
                            setEditStudentNama(s.nama);
                            setEditStudentJk(s.jk);
                            setEditStudentStatus(s.status);
                            setEditStudentClassId(s.class_id);
                          }}
                          className="text-xs text-indigo-600 hover:text-indigo-900 font-semibold"
                        >
                          Ubah Data
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* 3. PASANGAN MAPEL-KELAS (OTORISASI GRANULAR) */}
      {activeSubTab === 'pairings' && (
        <div className="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
          <div>
            <h2 className="text-sm font-bold text-slate-900">
              Otorisasi Pasangan Mata Pelajaran & Kelas (Granular Pairing)
            </h2>
            <p className="text-xs text-slate-500 mt-1">
              Membatasi 1 guru agar hanya boleh mengajar mapel tertentu di kelas tertentu. Jika belum dikonfigurasi untuk suatu mapel, sistem otomatis menggunakan izin fallback ke seluruh kelas yang diampu guru tersebut.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-4 gap-3 p-4 bg-slate-50 border border-slate-200 rounded-lg items-end">
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Pilih Guru
              </label>
              <select
                value={selectedPairingTeacherId}
                onChange={(e) => setSelectedPairingTeacherId(Number(e.target.value))}
                className="w-full text-xs bg-white border border-slate-200 rounded-lg px-3 py-2 text-slate-900"
              >
                {allUsers
                  .filter((u) => u.roles.includes('guru'))
                  .map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.nama}
                    </option>
                  ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Mata Pelajaran
              </label>
              <select
                value={pairingSubjectId}
                onChange={(e) => setPairingSubjectId(Number(e.target.value))}
                className="w-full text-xs bg-white border border-slate-200 rounded-lg px-3 py-2 text-slate-900"
              >
                {subjects
                  .filter((s) => s.id !== 5)
                  .map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Kelas yang Diizinkan
              </label>
              <select
                value={pairingClassId}
                onChange={(e) => setPairingClassId(Number(e.target.value))}
                className="w-full text-xs bg-white border border-slate-200 rounded-lg px-3 py-2 text-slate-900"
              >
                {classes.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>

            <button
              onClick={handleAddPairing}
              className="py-2 px-3 text-xs font-semibold bg-slate-900 hover:bg-slate-800 text-white rounded-lg transition-colors text-center"
            >
              + Tambah Otorisasi
            </button>
          </div>

          <div className="space-y-2">
            <h3 className="text-xs font-bold text-slate-700">
              Daftar Pasangan Otorisasi Guru Terpilih ({teacherPairings.length} Aturan Khusus)
            </h3>
            {teacherPairings.length === 0 ? (
              <div className="p-4 bg-slate-50 text-slate-500 rounded-lg text-xs">
                Belum ada batasan khusus. Guru ini memiliki izin fallback untuk mengajar di seluruh kelas yang diampunya.
              </div>
            ) : (
              <div className="divide-y divide-slate-100 border border-slate-200 rounded-lg overflow-hidden">
                {teacherPairings.map((p, idx) => {
                  const s = subjects.find((sub) => sub.id === p.subject_id);
                  const c = classes.find((cls) => cls.id === p.class_id);
                  return (
                    <div key={idx} className="p-3 flex items-center justify-between text-xs bg-white">
                      <div>
                        <span className="font-semibold text-slate-900">{s?.name}</span>
                        <span className="text-slate-500 ml-2">&rarr; Diizinkan di kelas:</span>
                        <span className="font-bold text-indigo-700 ml-1.5">{c?.name}</span>
                      </div>
                      <button
                        onClick={() => handleRemovePairing(p)}
                        className="text-rose-600 hover:text-rose-900 text-xs font-medium"
                      >
                        Hapus Aturan
                      </button>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </div>
      )}

      {/* 4. UPLOAD ABSENSI HARDCOPY -> SOFTCOPY */}
      {activeSubTab === 'hardcopy' && (
        <div className="bg-white border border-slate-200 rounded-lg p-5 space-y-5">
          <div>
            <h2 className="text-sm font-bold text-slate-900">
              Impor Absensi Hardcopy &rarr; Softcopy
            </h2>
            <p className="text-xs text-slate-500 mt-1">
              Alur kerja guru yang mencatat manual di kertas: unduh template Excel terstandar, isi, lalu unggah kembali untuk dipratinjau sebelum diimpor ke database resmi.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {/* Step 1: Download Template */}
            <div className="p-4 bg-slate-50 border border-slate-200 rounded-lg space-y-3">
              <div className="font-bold text-xs text-slate-900 flex items-center gap-2">
                <FileSpreadsheet className="w-4 h-4 text-emerald-600" />
                Langkah 1: Unduh Template Excel Siap Isi
              </div>

              <div className="grid grid-cols-2 gap-2 text-xs">
                <div>
                  <label className="block text-[11px] text-slate-500 mb-1">Kelas</label>
                  <select
                    value={hardcopyClassId}
                    onChange={(e) => setHardcopyClassId(Number(e.target.value))}
                    className="w-full text-xs bg-white border border-slate-200 rounded-md p-1.5"
                  >
                    {classes.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-[11px] text-slate-500 mb-1">Tanggal</label>
                  <input
                    type="date"
                    value={hardcopyDate}
                    onChange={(e) => setHardcopyDate(e.target.value)}
                    className="w-full text-xs bg-white border border-slate-200 rounded-md p-1.5"
                  />
                </div>
              </div>

              <button
                onClick={handleDownloadHardcopyTemplate}
                className="w-full flex items-center justify-center gap-1.5 py-2 px-3 text-xs font-semibold bg-white border border-slate-200 rounded-lg text-slate-800 hover:bg-slate-100 transition-colors"
              >
                <Download className="w-3.5 h-3.5 text-emerald-600" />
                Unduh Template Excel (.xlsx)
              </button>
            </div>

            {/* Step 2: Upload File */}
            <div className="p-4 bg-slate-50 border border-slate-200 rounded-lg space-y-3">
              <div className="font-bold text-xs text-slate-900 flex items-center gap-2">
                <Upload className="w-4 h-4 text-indigo-600" />
                Langkah 2: Unggah File yang Telah Diisi
              </div>

              <p className="text-[11px] text-slate-500">
                Pilih file Excel yang telah dilengkapi status absensi (H/I/S/A) oleh guru pencatat.
              </p>

              <label className="block">
                <input
                  type="file"
                  accept=".xlsx, .xls, .csv"
                  onChange={handleFileUpload}
                  className="block w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-slate-900 file:text-white hover:file:bg-slate-800 cursor-pointer"
                />
              </label>
            </div>
          </div>

          {/* Pratinjau / Preview Table (PRD 6.7) */}
          {hardcopyPreview && (
            <div className="border border-slate-200 rounded-lg p-4 space-y-3 bg-white">
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-xs font-bold text-slate-900">
                    Pratinjau Impor: {hardcopyPreview.totalRows} Baris Terbaca
                  </h3>
                  <div className="text-[11px] text-slate-500 mt-0.5">
                    Valid: <strong className="text-emerald-700">{hardcopyPreview.validRows}</strong> · Bermasalah: <strong className="text-rose-700">{hardcopyPreview.invalidRows}</strong>
                  </div>
                </div>

                <button
                  onClick={handleCommitHardcopyImport}
                  disabled={hardcopyPreview.validRows === 0}
                  className="px-4 py-1.5 text-xs font-semibold bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg transition-colors shadow-xs"
                >
                  Konfirmasi & Simpan ke Basis Data
                </button>
              </div>

              {hardcopyPreview.warnings.length > 0 && (
                <div className="p-2.5 bg-amber-50 border border-amber-200 rounded-md text-xs text-amber-800 space-y-1">
                  <div className="font-bold">Peringatan Validasi:</div>
                  <ul className="list-disc pl-4 space-y-0.5 text-[11px]">
                    {hardcopyPreview.warnings.map((w, idx) => (
                      <li key={idx}>{w}</li>
                    ))}
                  </ul>
                </div>
              )}

              <div className="max-h-60 overflow-y-auto border border-slate-100 rounded-md">
                <table className="w-full text-xs text-left">
                  <thead className="bg-slate-50 text-slate-500 sticky top-0">
                    <tr>
                      <th className="py-2 px-3">NIS</th>
                      <th className="py-2 px-3">Nama Siswa</th>
                      <th className="py-2 px-3 text-center">Status</th>
                      <th className="py-2 px-3">Catatan</th>
                      <th className="py-2 px-3 text-center">Validasi</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {hardcopyPreview.entries.map((row, idx) => (
                      <tr key={idx} className={!row.isValidStudent || row.status === 'INVALID' ? 'bg-rose-50/50' : ''}>
                        <td className="py-2 px-3 font-mono">{row.nis}</td>
                        <td className="py-2 px-3 font-medium text-slate-900">{row.nama}</td>
                        <td className="py-2 px-3 text-center font-mono font-bold">{row.status}</td>
                        <td className="py-2 px-3 text-slate-500">{row.notes || '-'}</td>
                        <td className="py-2 px-3 text-center">
                          {row.isValidStudent && row.status !== 'INVALID' ? (
                            <span className="text-[10px] text-emerald-700 font-bold">OK</span>
                          ) : (
                            <span className="text-[10px] text-rose-700 font-bold">Error</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}

      {/* 5. AUDIT LOG */}
      {activeSubTab === 'audit' && (
        <div className="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 className="text-sm font-bold text-slate-900">Log Aktivitas (Audit Trail)</h2>
              <p className="text-xs text-slate-500">
                Perekaman historis siapa-melakukan-apa-kapan untuk menjamin transparansi operasional.
              </p>
            </div>

            <div className="flex items-center gap-2">
              <select
                value={logModuleFilter}
                onChange={(e) => setLogModuleFilter(e.target.value)}
                aria-label="Filter Berdasarkan Modul"
                className="text-xs bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-slate-800"
              >
                <option value="all">Semua Modul</option>
                <option value="Absensi">Absensi</option>
                <option value="Nilai">Nilai</option>
                <option value="Siswa">Siswa</option>
                <option value="Akun Guru">Akun Guru</option>
                <option value="BK">BK</option>
                <option value="Backup">Backup</option>
                <option value="Sistem">Sistem</option>
              </select>

              <input
                type="text"
                placeholder="Cari log..."
                value={logSearch}
                onChange={(e) => setLogSearch(e.target.value)}
                className="text-xs bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-slate-800 w-44"
              />
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-xs text-left border-collapse">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-slate-500 font-medium">
                  <th className="py-2.5 px-3 w-36">Waktu</th>
                  <th className="py-2.5 px-3 w-28">Pengguna</th>
                  <th className="py-2.5 px-3 w-24">Modul</th>
                  <th className="py-2.5 px-3 w-36">Aksi</th>
                  <th className="py-2.5 px-3 w-40">Target</th>
                  <th className="py-2.5 px-3">Detail</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredLogs.map((log) => (
                  <tr key={log.id} className="hover:bg-slate-50/80 transition-colors">
                    <td className="py-2.5 px-3 font-mono text-[11px] text-slate-500 whitespace-nowrap">
                      {log.created_at}
                    </td>
                    <td className="py-2.5 px-3 font-mono font-medium text-slate-800">
                      {log.username}
                    </td>
                    <td className="py-2.5 px-3">
                      <span className="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 font-mono">
                        {log.modul}
                      </span>
                    </td>
                    <td className="py-2.5 px-3 font-semibold text-slate-900">{log.aksi}</td>
                    <td className="py-2.5 px-3 text-slate-700">{log.target}</td>
                    <td className="py-2.5 px-3 text-slate-500 font-mono text-[11px]">
                      {log.detail}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* 6. PENGATURAN SEKOLAH & BACKUP DATABASE (PRD 6.7 & 6.10) */}
      {activeSubTab === 'settings' && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* School Settings Form */}
          <div className="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
            <div className="flex items-center gap-2">
              <Settings className="w-4 h-4 text-slate-700" />
              <h2 className="text-sm font-bold text-slate-900">Identitas & Konfigurasi Sekolah</h2>
            </div>

            <form onSubmit={handleSaveSettings} className="space-y-3">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Nama Resmi Sekolah
                </label>
                <input
                  type="text"
                  value={schoolName}
                  onChange={(e) => setSchoolName(e.target.value)}
                  className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-lg focus:ring-1 focus:ring-slate-900"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Tahun Ajaran
                  </label>
                  <input
                    type="text"
                    value={schoolYear}
                    onChange={(e) => setSchoolYear(e.target.value)}
                    className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-lg focus:ring-1 focus:ring-slate-900"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Semester
                  </label>
                  <input
                    type="text"
                    value={schoolSemester}
                    onChange={(e) => setSchoolSemester(e.target.value)}
                    className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-lg focus:ring-1 focus:ring-slate-900"
                  />
                </div>
              </div>

              <button
                type="submit"
                className="px-4 py-2 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors"
              >
                Simpan Perubahan Identitas
              </button>
            </form>
          </div>

          {/* Backup & Disaster Recovery Manager (PRD 6.10 & 9.3) */}
          <div className="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
            <div className="flex items-center gap-2">
              <Database className="w-4 h-4 text-indigo-700" />
              <h2 className="text-sm font-bold text-slate-900">
                Pencadangan Database & Pemulihan (Disaster Recovery)
              </h2>
            </div>
            <p className="text-xs text-slate-500">
              Sistem mengekspor skema dan seluruh baris data ternormalisasi ke dalam file SQL dump yang kompatibel dengan server MySQL fisik, serta cadangan arsip JSON.
            </p>

            {/* Status backup server sungguhan (VACUUM INTO snapshot terjadwal, lihat server/backup.ts) */}
            <div className="p-3 bg-slate-50 border border-slate-200 rounded-lg space-y-2 text-xs">
              <div className="flex items-center gap-1.5 text-[11px] font-semibold text-slate-700 uppercase tracking-wide mb-1">
                <ServerCog className="w-3.5 h-3.5 text-indigo-600" />
                Backup Otomatis Server
              </div>
              {isServerBackupLoading ? (
                <div className="flex items-center gap-1.5 text-slate-500 py-1">
                  <Loader2 className="w-3 h-3 animate-spin" />
                  Memuat status backup server...
                </div>
              ) : serverBackupSettings ? (
                <>
                  <div className="flex items-center justify-between">
                    <span className="text-slate-600">Jadwal Otomatis:</span>
                    <span className="font-mono font-semibold text-emerald-700">Aktif (Tiap 24 Jam)</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-slate-600">Kebijakan Retensi:</span>
                    <span className="font-mono text-slate-800">
                      {serverBackupSettings.backup_retention_weeks} Minggu Terakhir
                    </span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-slate-600">Pencadangan Terakhir:</span>
                    <span
                      className={`font-mono ${
                        serverBackupSettings.last_backup_status === 'failed' ? 'text-rose-700 font-semibold' : 'text-slate-800'
                      }`}
                    >
                      {serverBackupSettings.last_backup_date
                        ? `${serverBackupSettings.last_backup_date} (${
                            serverBackupSettings.last_backup_status === 'failed' ? 'Gagal' : 'Sukses'
                          })`
                        : 'Belum pernah berjalan'}
                    </span>
                  </div>
                </>
              ) : (
                <p className="text-slate-500">Status backup server tidak dapat dimuat (server tidak terjangkau).</p>
              )}
            </div>

            <div className="flex flex-col gap-2 pt-2">
              <button
                onClick={handleServerBackupNow}
                disabled={isRunningServerBackup}
                className="w-full flex items-center justify-center gap-2 py-2 px-3 text-xs font-semibold bg-indigo-700 hover:bg-indigo-800 disabled:opacity-60 disabled:cursor-not-allowed text-white rounded-lg transition-colors shadow-xs"
              >
                {isRunningServerBackup ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <ServerCog className="w-3.5 h-3.5" />
                )}
                {isRunningServerBackup ? 'Membuat Snapshot Database Server...' : 'Backup Database Server Sekarang'}
              </button>

              <button
                onClick={() => setShowGoogleSheetsBackupModal(true)}
                className="w-full flex items-center justify-center gap-2 py-2 px-3 text-xs font-semibold bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg transition-colors shadow-xs"
              >
                <FileSpreadsheet className="w-3.5 h-3.5 text-white" />
                Cadangkan Database ke Google Sheets (Multi-Tab)
              </button>

              <button
                onClick={handleDownloadSqlDump}
                className="w-full flex items-center justify-center gap-2 py-2 px-3 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors shadow-xs"
              >
                <Download className="w-3.5 h-3.5" />
                Unduh Dump SQL Lengkap (MySQL Production)
              </button>

              <button
                onClick={handleDownloadJsonBackup}
                className="w-full flex items-center justify-center gap-2 py-2 px-3 text-xs font-semibold bg-white border border-slate-200 rounded-lg text-slate-800 hover:bg-slate-50 transition-colors"
              >
                <Download className="w-3.5 h-3.5 text-indigo-600" />
                Unduh Cadangan Struktur JSON
              </button>
              <p className="text-[10px] text-slate-400 pt-1">
                "Backup Database Server Sekarang" & jadwal otomatis menyimpan snapshot penuh di server sekolah
                (persisten, tidak tergantung perangkat). Tiga tombol di bawahnya mengunduh salinan ke perangkat ini.
              </p>
            </div>
          </div>
        </div>
      )}

      {/* MODAL: Tambah Akun Guru */}
      {showAddTeacherModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-md w-full p-5 space-y-4">
            <div className="flex items-center justify-between border-b border-slate-200 pb-3">
              <h3 className="text-sm font-bold text-slate-900">Tambah Akun Guru Baru</h3>
              <button onClick={() => setShowAddTeacherModal(false)} className="text-slate-400 text-lg font-bold">
                &times;
              </button>
            </div>

            <form onSubmit={handleCreateTeacher} className="space-y-3">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">Nama Lengkap & Gelar</label>
                <input
                  type="text"
                  required
                  placeholder="cth: Ahmad Fauzan, S.Pd."
                  value={newTeacherName}
                  onChange={(e) => setNewTeacherName(e.target.value)}
                  className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">Username Login</label>
                <input
                  type="text"
                  required
                  placeholder="cth: pak.fauzan"
                  value={newTeacherUsername}
                  onChange={(e) => setNewTeacherUsername(e.target.value)}
                  className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md font-mono"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">Password Awal</label>
                <input
                  type="text"
                  required
                  value={newTeacherPassword}
                  onChange={(e) => setNewTeacherPassword(e.target.value)}
                  className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md font-mono"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">Tugas Wali Kelas (Opsional)</label>
                <select
                  value={newTeacherWaliClass || ''}
                  onChange={(e) => setNewTeacherWaliClass(e.target.value ? Number(e.target.value) : null)}
                  className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md"
                >
                  <option value="">Bukan Wali Kelas</option>
                  {classes.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </div>

              <div className="pt-2 flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setShowAddTeacherModal(false)}
                  className="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  className="px-4 py-1.5 text-xs font-semibold bg-slate-900 text-white rounded hover:bg-slate-800"
                >
                  Simpan Akun
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL: Tambah Siswa */}
      {showAddStudentModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-md w-full p-5 space-y-4">
            <div className="flex items-center justify-between border-b border-slate-200 pb-3">
              <h3 className="text-sm font-bold text-slate-900">Tambah Siswa Baru</h3>
              <button onClick={() => setShowAddStudentModal(false)} className="text-slate-400 text-lg font-bold">
                &times;
              </button>
            </div>

            <form onSubmit={handleCreateStudent} className="space-y-3">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">NIS (Nomor Induk Siswa)</label>
                <input
                  type="text"
                  required
                  placeholder="cth: 24.01/DKV/025"
                  value={newStudentNis}
                  onChange={(e) => setNewStudentNis(e.target.value)}
                  className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md font-mono"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">Nama Lengkap Siswa</label>
                <input
                  type="text"
                  required
                  placeholder="cth: Tegar Putra Pratama"
                  value={newStudentNama}
                  onChange={(e) => setNewStudentNama(e.target.value)}
                  className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Jenis Kelamin</label>
                  <select
                    value={newStudentJk}
                    onChange={(e) => setNewStudentJk(e.target.value as 'L' | 'P')}
                    className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md"
                  >
                    <option value="L">Laki-Laki (L)</option>
                    <option value="P">Perempuan (P)</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Kelas</label>
                  <select
                    value={newStudentClassId}
                    onChange={(e) => setNewStudentClassId(Number(e.target.value))}
                    className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md"
                  >
                    {classes.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="pt-2 flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setShowAddStudentModal(false)}
                  className="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  className="px-4 py-1.5 text-xs font-semibold bg-slate-900 text-white rounded hover:bg-slate-800"
                >
                  Simpan Siswa
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL: Edit Siswa (NIS locked as per PRD 6.7) */}
      {editingStudent && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-md w-full p-5 space-y-4">
            <div className="flex items-center justify-between border-b border-slate-200 pb-3">
              <h3 className="text-sm font-bold text-slate-900">Ubah Data Siswa</h3>
              <button onClick={() => setEditingStudent(null)} className="text-slate-400 text-lg font-bold">
                &times;
              </button>
            </div>

            <form onSubmit={handleSaveStudentEdit} className="space-y-3">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  NIS (Kunci Historis - Terkunci)
                </label>
                <input
                  type="text"
                  disabled
                  value={editingStudent.nis}
                  className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md bg-slate-100 font-mono text-slate-500 cursor-not-allowed"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">Nama Lengkap</label>
                <input
                  type="text"
                  required
                  value={editStudentNama}
                  onChange={(e) => setEditStudentNama(e.target.value)}
                  className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Jenis Kelamin</label>
                  <select
                    value={editStudentJk}
                    onChange={(e) => setEditStudentJk(e.target.value as 'L' | 'P')}
                    className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md"
                  >
                    <option value="L">Laki-Laki (L)</option>
                    <option value="P">Perempuan (P)</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Status Keaktifan</label>
                  <select
                    value={editStudentStatus}
                    onChange={(e) => setEditStudentStatus(e.target.value as any)}
                    className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md"
                  >
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Nonaktif</option>
                    <option value="pindah">Pindah</option>
                    <option value="berhenti">Berhenti</option>
                    <option value="keluar">Keluar</option>
                  </select>
                </div>
              </div>

              <div className="pt-2 flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setEditingStudent(null)}
                  className="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  className="px-4 py-1.5 text-xs font-semibold bg-slate-900 text-white rounded hover:bg-slate-800"
                >
                  Simpan Perubahan
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL: Reset Password */}
      {resettingUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-sm w-full p-5 space-y-4">
            <h3 className="text-sm font-bold text-slate-900">
              Reset Password: {resettingUser.nama}
            </h3>
            <p className="text-xs text-slate-500">
              Password baru akan di-hash dan disimpan. Log aktivitas tidak akan mencatat nilai password mentah.
            </p>
            <form onSubmit={handleResetPassword} className="space-y-3">
              <input
                type="text"
                required
                value={newPasswordVal}
                onChange={(e) => setNewPasswordVal(e.target.value)}
                className="w-full px-3 py-1.5 text-xs border border-slate-200 rounded-md font-mono"
              />
              <div className="flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setResettingUser(null)}
                  className="px-3 py-1.5 text-xs text-slate-600"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  className="px-4 py-1.5 text-xs font-semibold bg-slate-900 text-white rounded"
                >
                  Konfirmasi Reset
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Google Sheets Full Backup Modal */}
      {showGoogleSheetsBackupModal && (
        <GoogleSheetsModal
          isOpen={showGoogleSheetsBackupModal}
          onClose={() => setShowGoogleSheetsBackupModal(false)}
          mode="backup"
          payload={{
            students: storage.getStudents(),
            classes: storage.getClasses(),
            subjects: storage.getSubjects(),
            attendances: storage.getAttendance(),
            activities: storage.getGradeActivities(),
            grades: storage.getGradeValues(),
          }}
        />
      )}
    </div>
  );
};
