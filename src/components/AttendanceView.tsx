import React, { useState, useEffect, useMemo } from 'react';
import { User, ClassItem, Subject, Student, AttendanceStatus, KetuaKelasToken } from '../types';
import { storage } from '../services/storage';
import { exportAttendanceMatrixToExcel } from '../utils/excel';
import { GoogleSheetsModal } from './GoogleSheetsModal';
import {
  Calendar,
  CheckCircle2,
  Trash2,
  Share2,
  Download,
  AlertCircle,
  Copy,
  ExternalLink,
  History,
  Lock,
  FileSpreadsheet,
  Clock,
} from 'lucide-react';

interface AttendanceViewProps {
  currentUser: User;
  classes: ClassItem[];
  subjects: Subject[];
  onOpenDelegationView?: (token: string) => void;
}

export const AttendanceView: React.FC<AttendanceViewProps> = ({
  currentUser,
  classes,
  subjects,
  onOpenDelegationView,
}) => {
  const isWali = currentUser.kelas_wali_id !== null;
  const isGuru = currentUser.roles.includes('guru') || currentUser.roles.includes('superadmin');

  // Mode: 'mapel' vs 'wali'
  const [mode, setMode] = useState<'wali' | 'mapel'>(isWali ? 'wali' : 'mapel');

  // Selected Scope
  const [selectedClassId, setSelectedClassId] = useState<number>(
    isWali && currentUser.kelas_wali_id ? currentUser.kelas_wali_id : classes[0]?.id || 1
  );
  const [selectedSubjectId, setSelectedSubjectId] = useState<number>(
    currentUser.subjects && currentUser.subjects.length > 0 ? currentUser.subjects[0] : 3
  );

  // Date selection (defaults to today in YYYY-MM-DD)
  const [selectedDate, setSelectedDate] = useState<string>(() => {
    return new Date().toISOString().substring(0, 10);
  });

  // Local student attendance state: student_id -> status
  const [attendanceState, setAttendanceState] = useState<Record<number, { status: AttendanceStatus; notes: string }>>({});
  const [isExistingSession, setIsExistingSession] = useState<boolean>(false);
  const [feedbackMessage, setFeedbackMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Modals & Panels
  const [showHistoryModal, setShowHistoryModal] = useState<boolean>(false);
  const [showDelegationModal, setShowDelegationModal] = useState<boolean>(false);
  const [showGoogleSheetsModal, setShowGoogleSheetsModal] = useState<boolean>(false);
  const [generatedToken, setGeneratedToken] = useState<string | null>(null);
  const [generatedTokenObj, setGeneratedTokenObj] = useState<KetuaKelasToken | null>(null);
  const [copiedLink, setCopiedLink] = useState<boolean>(false);

  // Get active students for selected class (only 'aktif' students, sorted alphabetically)
  const activeStudents = useMemo(() => {
    return storage
      .getStudents()
      .filter((s) => s.class_id === selectedClassId && s.status === 'aktif')
      .sort((a, b) => a.nama.localeCompare(b.nama));
  }, [selectedClassId]);

  // Load existing attendance record for this class, subject, and date
  useEffect(() => {
    const subjectParam = mode === 'wali' ? null : selectedSubjectId;
    const allRecords = storage.getAttendance();

    const existingForDate = allRecords.filter(
      (r) =>
        r.class_id === selectedClassId &&
        r.subject_id === subjectParam &&
        r.tanggal === selectedDate
    );

    const newState: Record<number, { status: AttendanceStatus; notes: string }> = {};

    if (existingForDate.length > 0) {
      setIsExistingSession(true);
      for (const rec of existingForDate) {
        newState[rec.student_id] = {
          status: rec.status,
          notes: rec.notes || '',
        };
      }
      // Fill missing with H default
      for (const s of activeStudents) {
        if (!newState[s.id]) {
          newState[s.id] = { status: 'H', notes: '' };
        }
      }
    } else {
      setIsExistingSession(false);
      // Initialize with Hadir (H) as standard default
      for (const s of activeStudents) {
        newState[s.id] = { status: 'H', notes: '' };
      }
    }

    setAttendanceState(newState);
  }, [selectedClassId, selectedSubjectId, selectedDate, mode, activeStudents]);

  // Check Granular Pairing Authorization (PRD 6.2 & 6.6)
  const isAuthorized = useMemo(() => {
    if (mode === 'wali') {
      return currentUser.kelas_wali_id === selectedClassId || currentUser.roles.includes('superadmin');
    }
    return storage.isTeacherAllowed(currentUser.id, selectedSubjectId, selectedClassId) || currentUser.roles.includes('superadmin');
  }, [mode, currentUser, selectedSubjectId, selectedClassId]);

  // Bulk action: Hadir Semua
  const handleMarkAllHadir = () => {
    const updated = { ...attendanceState };
    for (const s of activeStudents) {
      updated[s.id] = { ...updated[s.id], status: 'H' };
    }
    setAttendanceState(updated);
  };

  // Change single student status
  const handleStatusChange = (studentId: number, status: AttendanceStatus) => {
    setAttendanceState((prev) => ({
      ...prev,
      [studentId]: {
        ...prev[studentId],
        status,
      },
    }));
  };

  // Change single student notes
  const handleNotesChange = (studentId: number, notes: string) => {
    setAttendanceState((prev) => ({
      ...prev,
      [studentId]: {
        ...prev[studentId],
        notes,
      },
    }));
  };

  // Submit attendance (Idempotent UPSERT per PRD 6.2)
  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setFeedbackMessage(null);

    if (!isAuthorized) {
      setFeedbackMessage({
        type: 'error',
        text: 'Otorisasi Gagal: Anda tidak memiliki wewenang mencatat kombinasi Mapel dan Kelas ini.',
      });
      return;
    }

    try {
      const subjectParam = mode === 'wali' ? null : selectedSubjectId;
      const entries = activeStudents.map((s) => ({
        student_id: s.id,
        status: attendanceState[s.id]?.status || 'H',
        notes: attendanceState[s.id]?.notes,
      }));

      const res = storage.submitAttendanceBatch({
        class_id: selectedClassId,
        subject_id: subjectParam,
        tanggal: selectedDate,
        recorded_by: currentUser.id,
        recorded_via: mode === 'wali' ? 'wali' : 'guru',
        entries,
      });

      setIsExistingSession(true);
      setFeedbackMessage({
        type: 'success',
        text: `Presensi berhasil disimpan! (${res.created} entri baru, ${res.updated} diperbarui secara idempotent).`,
      });
      setTimeout(() => setFeedbackMessage(null), 5000);
    } catch (err: any) {
      setFeedbackMessage({
        type: 'error',
        text: err.message || 'Gagal menyimpan absensi.',
      });
    }
  };

  // Delete Attendance Session with 7-day rule (PRD 6.2)
  const handleDeleteSession = (dateToDelete: string) => {
    const today = new Date();
    const entryDate = new Date(dateToDelete);
    const diffDays = Math.floor((today.getTime() - entryDate.getTime()) / (1000 * 3600 * 24));

    if (diffDays > 7) {
      alert(`Tidak dapat menghapus! Data absensi tanggal ${dateToDelete} sudah lebih dari 7 hari lalu (${diffDays} hari).`);
      return;
    }

    if (!confirm(`Konfirmasi hapus seluruh data absensi tanggal ${dateToDelete}?`)) {
      return;
    }

    try {
      const subjectParam = mode === 'wali' ? null : selectedSubjectId;
      storage.deleteAttendanceSession(selectedClassId, subjectParam, dateToDelete);
      setFeedbackMessage({
        type: 'success',
        text: `Sesi absensi tanggal ${dateToDelete} berhasil dihapus.`,
      });
      setShowHistoryModal(false);
      // Reset state for today
      setSelectedDate(new Date().toISOString().substring(0, 10));
    } catch (err: any) {
      alert(err.message);
    }
  };

  // Export Matrix to Excel (.xlsx)
  const handleExportExcel = () => {
    const activeClassObj = classes.find((c) => c.id === selectedClassId);
    const subjectParam = mode === 'wali' ? null : selectedSubjectId;
    const activeSubjObj = mode === 'wali' ? { name: 'Absen Harian Wali Kelas' } : subjects.find((s) => s.id === selectedSubjectId);

    const relevantRecords = storage.getAttendance().filter((r) => {
      return r.class_id === selectedClassId && r.subject_id === subjectParam;
    });

    exportAttendanceMatrixToExcel({
      className: activeClassObj?.name || 'Kelas',
      subjectName: activeSubjObj?.name || 'Absen',
      students: activeStudents,
      records: relevantRecords,
    });
  };

  // Generate Ketua Kelas Delegation Token (PRD 6.2 & Cross-Device Firestore Sync)
  const handleGenerateDelegation = async () => {
    // Token diterbitkan server (masa berlaku 24 jam), lalu disalin ke Firestore
    try {
      const tokenObj = await storage.createDelegationToken(selectedClassId, 24);
      setGeneratedToken(tokenObj.token);
      setGeneratedTokenObj(tokenObj);
      setShowDelegationModal(true);
      setCopiedLink(false);
    } catch (err: any) {
      alert(err?.message || 'Gagal membuat tautan delegasi.');
    }
  };

  // Summary counts for current form
  const currentSummary = useMemo(() => {
    let h = 0, i = 0, s = 0, a = 0;
    Object.values(attendanceState).forEach((item) => {
      if (item.status === 'H') h++;
      else if (item.status === 'I') i++;
      else if (item.status === 'S') s++;
      else if (item.status === 'A') a++;
    });
    return { h, i, s, a, total: activeStudents.length };
  }, [attendanceState, activeStudents]);

  // Past dates list for this session
  const pastSessions = useMemo(() => {
    const subjectParam = mode === 'wali' ? null : selectedSubjectId;
    const records = storage.getAttendance().filter((r) => r.class_id === selectedClassId && r.subject_id === subjectParam);
    const dateMap = new Map<string, { hadir: number; izin: number; sakit: number; alpa: number; total: number }>();

    records.forEach((r) => {
      if (!dateMap.has(r.tanggal)) {
        dateMap.set(r.tanggal, { hadir: 0, izin: 0, sakit: 0, alpa: 0, total: 0 });
      }
      const item = dateMap.get(r.tanggal)!;
      item.total++;
      if (r.status === 'H') item.hadir++;
      else if (r.status === 'I') item.izin++;
      else if (r.status === 'S') item.sakit++;
      else if (r.status === 'A') item.alpa++;
    });

    return Array.from(dateMap.entries())
      .map(([date, stats]) => ({ date, ...stats }))
      .sort((a, b) => b.date.localeCompare(a.date));
  }, [selectedClassId, selectedSubjectId, mode, showHistoryModal]);

  return (
    <div className="space-y-6">
      {/* Header & Controls */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-200">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-slate-900">
            {mode === 'wali' ? 'Input Absensi Harian (Wali Kelas)' : 'Input Absensi Mata Pelajaran'}
          </h1>
          <p className="text-xs text-slate-500 mt-1">
            Mencatat kehadiran per siswa dengan validasi relasional dan pembaharuan otomatis (UPSERT).
          </p>
        </div>

        {/* Action Buttons */}
        <div className="flex flex-wrap items-center gap-2">
          {/* Mode switch */}
          {isWali && isGuru && (
            <div className="inline-flex p-1 bg-slate-100 rounded-lg text-xs font-medium">
              <button
                type="button"
                onClick={() => setMode('wali')}
                className={`px-3 py-1.5 rounded-md transition-colors ${
                  mode === 'wali' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                Absen Harian (Wali)
              </button>
              <button
                type="button"
                onClick={() => setMode('mapel')}
                className={`px-3 py-1.5 rounded-md transition-colors ${
                  mode === 'mapel' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                Absen Mapel
              </button>
            </div>
          )}

          {/* Ketua Kelas Delegation (Wali Kelas only) */}
          {mode === 'wali' && (
            <button
              type="button"
              onClick={handleGenerateDelegation}
              className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors"
            >
              <Share2 className="w-3.5 h-3.5 text-indigo-600" />
              Delegasi Ketua Kelas
            </button>
          )}

          {/* History modal trigger */}
          <button
            type="button"
            onClick={() => setShowHistoryModal(true)}
            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors"
          >
            <History className="w-3.5 h-3.5 text-slate-500" />
            Riwayat Pertemuan
          </button>

          {/* Export to Google Sheets */}
          <button
            type="button"
            onClick={() => setShowGoogleSheetsModal(true)}
            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-emerald-800 bg-white border border-emerald-300 rounded-lg hover:bg-emerald-50 transition-colors shadow-xs"
          >
            <FileSpreadsheet className="w-3.5 h-3.5 text-emerald-600" />
            Google Sheets
          </button>

          {/* Export to Excel */}
          <button
            type="button"
            onClick={handleExportExcel}
            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors"
          >
            <Download className="w-3.5 h-3.5 text-slate-500" />
            Excel (.xlsx)
          </button>
        </div>
      </div>

      {/* Scope Filter Bar */}
      <div className="bg-white border border-slate-200 rounded-lg p-4 grid grid-cols-1 sm:grid-cols-3 md:grid-cols-4 gap-3 items-end">
        <div>
          <label className="block text-xs font-medium text-slate-600 mb-1">
            Kelas
          </label>
          <select
            value={selectedClassId}
            onChange={(e) => setSelectedClassId(Number(e.target.value))}
            className="w-full text-xs font-medium bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900 focus:ring-1 focus:ring-slate-900"
          >
            {classes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name} ({c.jurusan})
              </option>
            ))}
          </select>
        </div>

        {mode === 'mapel' && (
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">
              Mata Pelajaran
            </label>
            <select
              value={selectedSubjectId}
              onChange={(e) => setSelectedSubjectId(Number(e.target.value))}
              className="w-full text-xs font-medium bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900 focus:ring-1 focus:ring-slate-900"
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
        )}

        <div>
          <label className="block text-xs font-medium text-slate-600 mb-1">
            Tanggal Presensi
          </label>
          <div className="relative">
            <input
              type="date"
              value={selectedDate}
              onChange={(e) => setSelectedDate(e.target.value)}
              className="w-full text-xs font-medium bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900 focus:ring-1 focus:ring-slate-900"
            />
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={handleMarkAllHadir}
            className="w-full py-2 px-3 text-xs font-medium bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg transition-colors text-center"
          >
            Set Semua Hadir (H)
          </button>
        </div>
      </div>

      {/* Authorization Warning (PRD 6.6) */}
      {!isAuthorized && (
        <div className="flex items-start gap-2.5 p-3.5 bg-rose-50 border border-rose-200 rounded-lg text-xs text-rose-800">
          <AlertCircle className="w-4 h-4 shrink-0 mt-0.5 text-rose-600" />
          <div>
            <strong>Otorisasi Terbatas:</strong> Anda tidak terdaftar sebagai pengajar untuk pasangan mata pelajaran dan kelas ini pada tabel otorisasi granular (<code>teacher_subject_class_pairing</code>). Hubungi Admin untuk penugasan.
          </div>
        </div>
      )}

      {/* Feedback Alert */}
      {feedbackMessage && (
        <div
          className={`flex items-center gap-2 p-3 text-xs rounded-lg ${
            feedbackMessage.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
              : 'bg-rose-50 text-rose-800 border border-rose-200'
          }`}
        >
          {feedbackMessage.type === 'success' ? (
            <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
          ) : (
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
          )}
          <span>{feedbackMessage.text}</span>
        </div>
      )}

      {/* Main Attendance Form */}
      <form onSubmit={handleSubmit} className="bg-white border border-slate-200 rounded-lg shadow-xs overflow-hidden">
        {/* Form top summary */}
        <div className="p-4 bg-slate-50 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <span className="text-xs font-bold text-slate-900">
              Daftar Siswa ({activeStudents.length} Siswa Aktif)
            </span>
            {isExistingSession && (
              <span className="text-[11px] text-indigo-700 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded-sm">
                Mode Edit Sesi Lama (UPSERT)
              </span>
            )}
          </div>

          {/* Quick Counter Badges */}
          <div className="flex items-center gap-3 text-xs font-mono">
            <span className="text-emerald-700 font-semibold">H: {currentSummary.h}</span>
            <span className="text-slate-600">I: {currentSummary.i}</span>
            <span className="text-amber-700 font-semibold">S: {currentSummary.s}</span>
            <span className="text-rose-700 font-semibold">A: {currentSummary.a}</span>
          </div>
        </div>

        {/* Student Rows: kartu untuk mobile/tablet, tabel untuk desktop */}
        {activeStudents.length === 0 ? (
          <div className="py-8 text-center text-slate-400 text-xs">
            Tidak ada siswa aktif di kelas ini.
          </div>
        ) : (
          <>
            <div className="md:hidden divide-y divide-slate-100">
              {activeStudents.map((student, idx) => {
                const curr = attendanceState[student.id] || { status: 'H', notes: '' };
                return (
                  <div
                    key={student.id}
                    className={`p-3 space-y-2.5 ${
                      curr.status === 'A' ? 'bg-rose-50/30' : curr.status === 'S' ? 'bg-amber-50/20' : ''
                    }`}
                  >
                    <div className="flex items-center gap-2">
                      <span className="text-slate-400 font-mono text-xs w-5 shrink-0">{idx + 1}</span>
                      <div className="min-w-0">
                        <div className="font-medium text-slate-900 text-sm truncate">{student.nama}</div>
                        <div className="text-[11px] text-slate-500 font-mono">{student.nis} · {student.jk}</div>
                      </div>
                    </div>
                    <div className="flex items-center gap-1.5">
                      {(['H', 'I', 'S', 'A'] as AttendanceStatus[]).map((st) => (
                        <button
                          key={st}
                          type="button"
                          onClick={() => handleStatusChange(student.id, st)}
                          aria-label={`Status ${st}`}
                          className={`flex-1 min-h-11 rounded-lg text-sm font-bold font-mono transition-all ${
                            curr.status === st
                              ? st === 'H'
                                ? 'bg-emerald-600 text-white shadow-xs'
                                : st === 'I'
                                ? 'bg-sky-600 text-white shadow-xs'
                                : st === 'S'
                                ? 'bg-amber-600 text-white shadow-xs'
                                : 'bg-rose-600 text-white shadow-xs'
                              : 'bg-slate-100 text-slate-600 active:bg-slate-200'
                          }`}
                        >
                          {st}
                        </button>
                      ))}
                    </div>
                    <input
                      type="text"
                      placeholder="Catatan (cth: izin surat dokter / lomba)"
                      value={curr.notes || ''}
                      onChange={(e) => handleNotesChange(student.id, e.target.value)}
                      className="w-full px-3 py-2.5 text-sm border border-slate-200 rounded-lg focus:outline-hidden focus:ring-1 focus:ring-slate-900"
                    />
                  </div>
                );
              })}
            </div>

            <div className="hidden md:block overflow-x-auto">
              <table className="w-full text-xs text-left border-collapse">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50/50 text-slate-500 font-medium">
                    <th className="py-2.5 px-3 w-10 text-center">No</th>
                    <th className="py-2.5 px-3 w-32">NIS</th>
                    <th className="py-2.5 px-3">Nama Siswa</th>
                    <th className="py-2.5 px-3 w-12 text-center">JK</th>
                    <th className="py-2.5 px-3 w-64 text-center">Status Presensi</th>
                    <th className="py-2.5 px-3">Catatan Khusus</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {activeStudents.map((student, idx) => {
                    const curr = attendanceState[student.id] || { status: 'H', notes: '' };
                    return (
                      <tr
                        key={student.id}
                        className={`hover:bg-slate-50/80 transition-colors ${
                          curr.status === 'A'
                            ? 'bg-rose-50/30'
                            : curr.status === 'S'
                            ? 'bg-amber-50/20'
                            : ''
                        }`}
                      >
                        <td className="py-2.5 px-3 text-center font-mono text-slate-400">
                          {idx + 1}
                        </td>
                        <td className="py-2.5 px-3 font-mono text-slate-600">
                          {student.nis}
                        </td>
                        <td className="py-2.5 px-3 font-medium text-slate-900">
                          {student.nama}
                        </td>
                        <td className="py-2.5 px-3 text-center text-slate-500 font-mono">
                          {student.jk}
                        </td>
                        <td className="py-2.5 px-3">
                          <div className="flex items-center justify-center gap-1">
                            {(['H', 'I', 'S', 'A'] as AttendanceStatus[]).map((st) => (
                              <button
                                key={st}
                                type="button"
                                onClick={() => handleStatusChange(student.id, st)}
                                className={`w-9 h-7 rounded text-xs font-bold font-mono transition-all ${
                                  curr.status === st
                                    ? st === 'H'
                                      ? 'bg-emerald-600 text-white shadow-xs'
                                      : st === 'I'
                                      ? 'bg-sky-600 text-white shadow-xs'
                                      : st === 'S'
                                      ? 'bg-amber-600 text-white shadow-xs'
                                      : 'bg-rose-600 text-white shadow-xs'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                }`}
                              >
                                {st}
                              </button>
                            ))}
                          </div>
                        </td>
                        <td className="py-2.5 px-3">
                          <input
                            type="text"
                            placeholder="cth: izin surat dokter / lomba"
                            value={curr.notes || ''}
                            onChange={(e) => handleNotesChange(student.id, e.target.value)}
                            className="w-full px-2 py-1 text-xs border border-slate-200 rounded-md focus:outline-hidden focus:ring-1 focus:ring-slate-900"
                          />
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </>
        )}

        {/* Form Footer */}
        <div className="p-4 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3">
          <div className="text-xs text-slate-500">
            Total {activeStudents.length} siswa akan disimpan untuk tanggal <strong className="font-mono">{selectedDate}</strong>.
          </div>

          <div className="flex items-center gap-2">
            <button
              type="submit"
              disabled={!isAuthorized || activeStudents.length === 0}
              className={`px-5 py-2 text-xs font-semibold rounded-lg text-white shadow-xs transition-colors ${
                !isAuthorized || activeStudents.length === 0
                  ? 'bg-slate-400 cursor-not-allowed'
                  : 'bg-slate-900 hover:bg-slate-800'
              }`}
            >
              {isExistingSession ? 'Perbarui Presensi (UPSERT)' : 'Simpan Presensi Baru'}
            </button>
          </div>
        </div>
      </form>

      {/* History Modal & 7-Day Delete Policy (PRD 6.2) */}
      {showHistoryModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[85vh] flex flex-col overflow-hidden">
            <div className="p-4 border-b border-slate-200 flex items-center justify-between">
              <div>
                <h2 className="text-sm font-bold text-slate-900">
                  Riwayat Pertemuan & Presensi
                </h2>
                <p className="text-xs text-slate-500">
                  Kebijakan keamanan data: hanya sesi dalam 7 hari terakhir yang dapat dihapus.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setShowHistoryModal(false)}
                className="text-slate-400 hover:text-slate-700 text-lg font-bold"
              >
                &times;
              </button>
            </div>

            <div className="p-4 overflow-y-auto space-y-2 flex-1">
              {pastSessions.length === 0 ? (
                <div className="py-8 text-center text-xs text-slate-400">
                  Belum ada riwayat sesi tersimpan untuk kelas ini.
                </div>
              ) : (
                pastSessions.map((sess) => {
                  const today = new Date();
                  const sessDate = new Date(sess.date);
                  const diffDays = Math.floor((today.getTime() - sessDate.getTime()) / (1000 * 3600 * 24));
                  const isDeletable = diffDays <= 7;

                  return (
                    <div
                      key={sess.date}
                      className="p-3 bg-slate-50 border border-slate-200 rounded-lg flex items-center justify-between gap-3 text-xs"
                    >
                      <div>
                        <div className="font-mono font-bold text-slate-900">
                          {sess.date}
                        </div>
                        <div className="text-[11px] text-slate-500 mt-0.5">
                          H: {sess.hadir} · I: {sess.izin} · S: {sess.sakit} · A: {sess.alpa} ({sess.total} total)
                        </div>
                      </div>

                      <div className="flex items-center gap-2">
                        <button
                          type="button"
                          onClick={() => {
                            setSelectedDate(sess.date);
                            setShowHistoryModal(false);
                          }}
                          className="px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-200 rounded hover:bg-slate-100"
                        >
                          Buka / Edit
                        </button>

                        {isDeletable ? (
                          <button
                            type="button"
                            onClick={() => handleDeleteSession(sess.date)}
                            className="p-1.5 text-rose-600 hover:bg-rose-50 rounded"
                            title="Hapus sesi ini (dalam batas 7 hari)"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        ) : (
                          <span
                            className="p-1.5 text-slate-300 flex items-center gap-1 cursor-not-allowed"
                            title="Terkunci: Data lebih dari 7 hari lalu"
                          >
                            <Lock className="w-3.5 h-3.5" />
                          </span>
                        )}
                      </div>
                    </div>
                  );
                })
              )}
            </div>

            <div className="p-3 bg-slate-50 border-t border-slate-200 text-right">
              <button
                type="button"
                onClick={() => setShowHistoryModal(false)}
                className="px-4 py-1.5 text-xs font-semibold bg-slate-900 text-white rounded-md"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Ketua Kelas Delegation Modal (PRD 6.2) */}
      {showDelegationModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-md w-full p-5 space-y-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-slate-200 pb-3">
              <h2 className="text-sm font-bold text-slate-900">
                Tautan Delegasi Ketua Kelas
              </h2>
              <button
                type="button"
                onClick={() => setShowDelegationModal(false)}
                aria-label="Tutup"
                className="text-slate-400 hover:text-slate-700 min-h-9 min-w-9 flex items-center justify-center text-lg font-bold"
              >
                &times;
              </button>
            </div>

            <p className="text-xs text-slate-600 leading-relaxed">
              Wali kelas dapat memberikan tautan ini kepada Ketua Kelas agar dapat menginput absen harian mandiri tanpa harus memiliki akun guru. Tautan disinkronkan ke Cloud Firestore sehingga dapat langsung dibuka di perangkat murid manapun.
            </p>

            {generatedTokenObj?.expires_at && (
              <div className="p-2.5 bg-amber-50 border border-amber-200 rounded-lg flex items-start gap-2 text-xs text-amber-900">
                <Clock className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                <div className="space-y-0.5">
                  <span className="font-semibold">Masa Berlaku Token: 24 Jam</span>
                  <p className="text-[11px] text-amber-800 leading-snug">
                    Tautan aktif hingga <strong>{new Date(generatedTokenObj.expires_at).toLocaleString('id-ID')}</strong>. Setelah itu tautan akan otomatis dikunci demi keamanan.
                  </p>
                </div>
              </div>
            )}

            <div className="p-3 bg-slate-50 border border-slate-200 rounded-lg">
              <div className="text-[11px] text-slate-500 mb-1">Token Akses Aman (Cloud Firestore):</div>
              <div className="font-mono text-xs text-slate-900 break-all select-all font-semibold">
                {generatedToken}
              </div>
            </div>

            <div className="flex flex-col gap-2 pt-2">
              <button
                type="button"
                onClick={() => {
                  navigator.clipboard.writeText(
                    `${window.location.origin}/?token=${generatedToken}`
                  );
                  setCopiedLink(true);
                  setTimeout(() => setCopiedLink(false), 3000);
                }}
                className="w-full flex items-center justify-center gap-1.5 py-2 px-3 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors"
              >
                <Copy className="w-3.5 h-3.5" />
                {copiedLink ? 'Tautan Berhasil Disalin!' : 'Salin Tautan Presensi'}
              </button>

              {onOpenDelegationView && generatedToken && (
                <button
                  type="button"
                  onClick={() => {
                    setShowDelegationModal(false);
                    onOpenDelegationView(generatedToken);
                  }}
                  className="w-full flex items-center justify-center gap-1.5 py-2 px-3 text-xs font-semibold text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-lg hover:bg-indigo-100 transition-colors"
                >
                  <ExternalLink className="w-3.5 h-3.5" />
                  Buka Tampilan Ketua Kelas Sekarang
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Google Sheets Export Modal */}
      {showGoogleSheetsModal && (
        <GoogleSheetsModal
          isOpen={showGoogleSheetsModal}
          onClose={() => setShowGoogleSheetsModal(false)}
          mode="attendance"
          payload={{
            className: classes.find((c) => c.id === selectedClassId)?.name || 'Kelas',
            subjectName: mode === 'wali' ? 'Absen Harian' : subjects.find((s) => s.id === selectedSubjectId)?.name || 'Mapel',
            students: activeStudents,
            records: storage.getAttendance().filter((r) => {
              const subParam = mode === 'wali' ? null : selectedSubjectId;
              return r.class_id === selectedClassId && r.subject_id === subParam;
            }),
          }}
        />
      )}
    </div>
  );
};
