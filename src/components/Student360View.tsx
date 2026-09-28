import React, { useState, useMemo } from 'react';
import { Student, ClassItem, User } from '../types';
import { storage } from '../services/storage';
import { GoogleDocsModal } from './GoogleDocsModal';
import { ParentAccessModal } from './ParentAccessModal';
import {
  Search,
  UserCheck,
  Calendar,
  AlertTriangle,
  GraduationCap,
  Clock,
  ArrowLeft,
  FileText,
  ShieldCheck,
  CheckCircle2,
  XCircle,
  AlertOctagon,
  Loader2,
  Users,
} from 'lucide-react';

interface Student360ViewProps {
  initialStudentId?: number | null;
  classes: ClassItem[];
  currentUser: User;
  onBack?: () => void;
}

export const Student360View: React.FC<Student360ViewProps> = ({
  initialStudentId,
  classes,
  currentUser,
  onBack,
}) => {
  const allStudents = storage.getStudents();
  const [selectedStudentId, setSelectedStudentId] = useState<number>(
    initialStudentId || allStudents[0]?.id || 1
  );
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [classFilter, setClassFilter] = useState<number>(0);
  const [showGoogleDocsModal, setShowGoogleDocsModal] = useState<boolean>(false);
  const [showParentAccessModal, setShowParentAccessModal] = useState<boolean>(false);

  // Server business rules clearance state
  const [clearanceLoading, setClearanceLoading] = useState<boolean>(false);
  const [clearanceResult, setClearanceResult] = useState<any>(null);
  const [adminOverrideEnabled, setAdminOverrideEnabled] = useState<boolean>(false);

  // Reset clearance result on student switch
  React.useEffect(() => {
    setClearanceResult(null);
  }, [selectedStudentId]);

  const handleTestServerClearance = async () => {
    if (!student) return;
    setClearanceLoading(true);
    setClearanceResult(null);
    try {
      const res = await storage.requestAcademicClearanceServer(student.id, {
        admin_override: adminOverrideEnabled,
        override_reason: adminOverrideEnabled ? 'Dispensasi Khusus Medis / Kepala Sekolah' : undefined,
      });
      setClearanceResult(res);
    } catch (err: any) {
      setClearanceResult({ success: false, clearance_granted: false, error: err.message });
    } finally {
      setClearanceLoading(false);
    }
  };

  // Filter students for search dropdown
  const filteredStudents = useMemo(() => {
    return allStudents.filter((s) => {
      if (classFilter !== 0 && s.class_id !== classFilter) return false;
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        return s.nama.toLowerCase().includes(q) || s.nis.toLowerCase().includes(q);
      }
      return true;
    });
  }, [allStudents, classFilter, searchQuery]);

  // Selected student
  const student = allStudents.find((s) => s.id === selectedStudentId);
  const studentClass = classes.find((c) => c.id === student?.class_id);

  // Kelola akses portal orang tua: khusus Wali Kelas dari kelas siswa ini,
  // atau Administrator/Superadmin (sama persis dengan aturan otorisasi di
  // server untuk POST /api/parent-access/create).
  const isAdminUser = currentUser.roles.includes('admin') || currentUser.roles.includes('superadmin');
  const canManageParentAccess = Boolean(
    student && (isAdminUser || currentUser.kelas_wali_id === student.class_id)
  );

  // Student Attendance Dossier
  const studentAttendance = useMemo(() => {
    if (!student) return [];
    return storage
      .getAttendance()
      .filter((a) => a.student_id === student.id)
      .sort((a, b) => b.tanggal.localeCompare(a.tanggal));
  }, [student]);

  // Total stats
  const totalRecords = studentAttendance.length;
  const hadirCount = studentAttendance.filter((a) => a.status === 'H').length;
  const izinCount = studentAttendance.filter((a) => a.status === 'I').length;
  const sakitCount = studentAttendance.filter((a) => a.status === 'S').length;
  const alpaCount = studentAttendance.filter((a) => a.status === 'A').length;
  const attendanceRate = totalRecords > 0 ? (hadirCount / totalRecords) * 100 : 100;

  // Absences only (I, S, A)
  const absenceEvents = useMemo(() => {
    return studentAttendance.filter((a) => a.status !== 'H');
  }, [studentAttendance]);

  // Student Grades Dossier
  const studentGrades = useMemo(() => {
    if (!student) return [];
    const values = storage.getGradeValues().filter((v) => v.student_id === student.id);
    const activities = storage.getGradeActivities();
    const subjects = storage.getSubjects();

    return values.map((val) => {
      const act = activities.find((a) => a.id === val.activity_id);
      const sub = subjects.find((s) => s.id === act?.subject_id);
      return {
        activityName: act?.nama_kegiatan || 'Kegiatan',
        subjectName: sub?.name || 'Mapel',
        tanggal: act?.tanggal_kegiatan || '-',
        skala: act?.tipe_skala || 'angka',
        nilai: val.nilai,
      };
    });
  }, [student]);

  // Check if student has periodic pattern (PRD 6.4 & 6.5)
  const patternAlerts = useMemo(() => {
    if (!student) return [];
    const allAlerts = storage.detectPeriodicPatterns();
    return allAlerts.filter((p) => p.student_id === student.id);
  }, [student]);

  return (
    <div className="space-y-6">
      {/* Header & Back */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200">
        <div className="flex items-center gap-3">
          {onBack && (
            <button
              onClick={onBack}
              className="p-1.5 text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors"
            >
              <ArrowLeft className="w-4 h-4" />
            </button>
          )}
          <div>
            <h1 className="text-xl font-bold tracking-tight text-slate-900">
              Cek Riwayat Siswa (Student 360)
            </h1>
            <p className="text-xs text-slate-500 mt-0.5">
              Tinjauan komprehensif kehadiran, daftar kejadian absen khusus, dan performa nilai akademik.
            </p>
          </div>
        </div>

        {/* Search controls */}
        <div className="flex items-center gap-2">
          <select
            value={classFilter}
            onChange={(e) => setClassFilter(Number(e.target.value))}
            aria-label="Filter Kelas Siswa"
            className="text-xs bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-slate-800"
          >
            <option value={0}>Semua Kelas</option>
            {classes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>

          <div className="relative">
            <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-2.5 pointer-events-none" />
            <input
              type="text"
              placeholder="Cari NIS / Nama siswa..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="pl-8 pr-3 py-1.5 text-xs bg-white border border-slate-200 rounded-lg text-slate-800 w-48 sm:w-60 focus:ring-1 focus:ring-slate-900"
            />
          </div>
        </div>
      </div>

      {/* Student Selector Quick Bar */}
      <div className="flex overflow-x-auto gap-2 pb-2">
        {filteredStudents.slice(0, 15).map((s) => (
          <button
            key={s.id}
            onClick={() => setSelectedStudentId(s.id)}
            className={`px-3 py-1.5 text-xs rounded-lg whitespace-nowrap transition-colors flex items-center gap-1.5 shrink-0 ${
              s.id === selectedStudentId
                ? 'bg-slate-900 text-white font-medium shadow-xs'
                : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50'
            }`}
          >
            <span>{s.nama}</span>
            <span className="font-mono text-[10px] opacity-70">({s.nis})</span>
          </button>
        ))}
      </div>

      {/* Selected Student Profile Header Card */}
      {student && (
        <div className="bg-white border border-slate-200 rounded-lg p-5">
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-700 font-bold text-base">
                {student.nama.charAt(0)}
              </div>
              <div>
                <div className="flex items-center gap-2">
                  <h2 className="text-base font-bold text-slate-900">{student.nama}</h2>
                  <span className="text-xs font-mono text-slate-500">· {student.nis}</span>
                  <span className="text-[11px] px-1.5 py-0.5 rounded-sm bg-slate-100 text-slate-700 font-mono">
                    JK: {student.jk}
                  </span>
                </div>
                <div className="text-xs text-slate-500 mt-1 flex items-center gap-2">
                  <span>Kelas {studentClass?.name}</span>
                  <span aria-hidden="true">·</span>
                  <span>Jurusan: {studentClass?.jurusan}</span>
                  <span aria-hidden="true">·</span>
                  <span className="capitalize">Status: {student.status}</span>
                </div>
              </div>
            </div>

            {/* Attendance Rate Dial */}
            <div className="flex items-center gap-4 border-t md:border-t-0 md:border-l border-slate-200 pt-3 md:pt-0 md:pl-6">
              <div>
                <div className="text-xs text-slate-500">Tingkat Kehadiran</div>
                <div
                  className={`text-2xl font-bold font-mono tabular-nums ${
                    attendanceRate >= 90
                      ? 'text-emerald-700'
                      : attendanceRate >= 80
                      ? 'text-amber-700'
                      : 'text-rose-700'
                  }`}
                >
                  {attendanceRate.toFixed(1)}%
                </div>
                <div className="text-[10px] text-slate-400 font-mono">
                  {hadirCount} Hadir dari {totalRecords} Sesi
                </div>
              </div>

              <div className="flex items-center gap-1.5 text-xs font-mono pl-4">
                <span className="text-emerald-700 bg-emerald-50 px-2 py-1 rounded">
                  H: {hadirCount}
                </span>
                <span className="text-slate-700 bg-slate-100 px-2 py-1 rounded">
                  I: {izinCount}
                </span>
                <span className="text-amber-700 bg-amber-50 px-2 py-1 rounded">
                  S: {sakitCount}
                </span>
                <span className="text-rose-700 bg-rose-50 px-2 py-1 rounded">
                  A: {alpaCount}
                </span>
              </div>
            </div>
          </div>

          {/* Action Row */}
          <div className="mt-4 pt-3 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <span className="text-xs text-slate-500">
              Dokumentasi Resmi & Surat Administrasi Siswa
            </span>
            <div className="flex flex-wrap items-center gap-2">
              {canManageParentAccess && (
                <button
                  onClick={() => setShowParentAccessModal(true)}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-lg hover:bg-indigo-100 transition-colors shadow-xs"
                >
                  <Users className="w-3.5 h-3.5" />
                  <span>Akses Orang Tua/Wali Murid</span>
                </button>
              )}
              <button
                onClick={() => setShowGoogleDocsModal(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors shadow-xs"
              >
                <FileText className="w-3.5 h-3.5" />
                <span>Buat Surat Resmi (Google Docs)</span>
              </button>
            </div>
          </div>

          {/* Periodic Pattern Warning if detected */}
          {patternAlerts.length > 0 && (
            <div className="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-start gap-2 text-xs text-amber-900">
              <Clock className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
              <div>
                <strong>Peringatan Pola Berkala:</strong> Siswa ini terdeteksi memiliki kebiasaan absen pada hari{' '}
                <strong>{patternAlerts[0].day_of_week}</strong> ({patternAlerts[0].count}x kejadian dengan interval ~14 hari). Perlu perhatian dan konfirmasi wali kelas/BK.
              </div>
            </div>
          )}
        </div>
      )}

      {/* Lapisan Validasi Server: Aturan Bisnis 85% & Pengesahan Akademik */}
      {student && (
        <div className="bg-white border border-slate-200 rounded-lg p-5">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div className="flex items-center gap-2.5">
              <div className="p-2 rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-100 shrink-0">
                <ShieldCheck className="w-5 h-5" />
              </div>
              <div>
                <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2 flex-wrap">
                  <span>Validasi Aturan Bisnis Server (Batas Minimal 85%)</span>
                  {attendanceRate >= 85 ? (
                    <span className="inline-flex items-center gap-1 text-[11px] font-semibold bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full">
                      <CheckCircle2 className="w-3 h-3" /> Memenuhi Syarat (≥ 85%)
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1 text-[11px] font-semibold bg-rose-100 text-rose-800 px-2 py-0.5 rounded-full">
                      <AlertOctagon className="w-3 h-3" /> Pelanggaran Syarat (&lt; 85%)
                    </span>
                  )}
                </h3>
                <p className="text-xs text-slate-500 mt-0.5">
                  Ditegakkan oleh backend endpoint <code>/api/students/academic-clearance</code>. Menolak pengesahan kelulusan dan kenaikan kelas jika kehadiran di bawah 85%.
                </p>
              </div>
            </div>

            <div className="flex items-center gap-2.5 shrink-0 flex-wrap">
              <label className="flex items-center gap-1.5 text-xs text-slate-600 cursor-pointer select-none bg-slate-50 border border-slate-200 px-2.5 py-1.5 rounded-lg">
                <input
                  type="checkbox"
                  checked={adminOverrideEnabled}
                  onChange={(e) => setAdminOverrideEnabled(e.target.checked)}
                  className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 h-3.5 w-3.5"
                />
                <span>Override Admin</span>
              </label>

              <button
                type="button"
                onClick={handleTestServerClearance}
                disabled={clearanceLoading}
                className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800 disabled:opacity-50 transition-colors shadow-xs"
              >
                {clearanceLoading ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    <span>Memverifikasi Server...</span>
                  </>
                ) : (
                  <>
                    <GraduationCap className="w-3.5 h-3.5" />
                    <span>Uji Pengesahan Server</span>
                  </>
                )}
              </button>
            </div>
          </div>

          {/* Feedback Hasil Validasi Server */}
          {clearanceResult && (
            <div
              className={`mt-4 p-3.5 rounded-lg border text-xs ${
                clearanceResult.clearance_granted
                  ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
                  : 'bg-rose-50 border-rose-200 text-rose-900'
              }`}
            >
              <div className="flex items-start gap-2.5">
                {clearanceResult.clearance_granted ? (
                  <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                ) : (
                  <XCircle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
                )}
                <div className="space-y-1">
                  <div className="font-semibold text-slate-900">
                    {clearanceResult.clearance_granted
                      ? 'Pengesahan Akademik Disetujui Server'
                      : 'Pengesahan Akademik Ditolak Server (Aturan 85% Ditegakkan)'}
                  </div>
                  <div className="text-slate-700">
                    {clearanceResult.message || clearanceResult.error}
                  </div>
                  {clearanceResult.clearance_code && (
                    <div className="font-mono text-[11px] text-emerald-800 bg-white/70 px-2 py-1 rounded inline-block">
                      Kode Sertifikasi Server: {clearanceResult.clearance_code}
                    </div>
                  )}
                  {clearanceResult.details && (
                    <div className="text-[11px] text-rose-700 mt-1">
                      <span>Defisit Kehadiran: {clearanceResult.details.deficit_sessions} sesi lagi untuk mencapai batas 85%.</span>
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Grid: Absences Log & Academic Grades */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Left: Absence Log */}
        <div className="bg-white border border-slate-200 rounded-lg p-5">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-1.5">
              <AlertTriangle className="w-4 h-4 text-amber-600" />
              <h3 className="text-sm font-bold text-slate-900">
                Riwayat Ketidakhadiran (Izin / Sakit / Alpa)
              </h3>
            </div>
            <span className="text-xs font-mono text-slate-500">
              {absenceEvents.length} kejadian
            </span>
          </div>

          {absenceEvents.length === 0 ? (
            <div className="py-12 text-center text-xs text-emerald-700 bg-emerald-50/50 rounded-lg">
              Siswa memiliki catatan kehadiran sempurna (100% Hadir)!
            </div>
          ) : (
            <div className="space-y-2.5">
              {absenceEvents.map((ev) => (
                <div
                  key={ev.id}
                  className="p-3 bg-slate-50 border border-slate-200 rounded-lg text-xs"
                >
                  <div className="flex items-center justify-between">
                    <span className="font-mono font-bold text-slate-900">
                      {ev.tanggal}
                    </span>
                    <span
                      className={`font-bold font-mono px-2 py-0.5 rounded text-[11px] ${
                        ev.status === 'A'
                          ? 'bg-rose-100 text-rose-800'
                          : ev.status === 'S'
                          ? 'bg-amber-100 text-amber-800'
                          : 'bg-sky-100 text-sky-800'
                      }`}
                    >
                      {ev.status === 'A'
                        ? 'Alpa (A)'
                        : ev.status === 'S'
                        ? 'Sakit (S)'
                        : 'Izin (I)'}
                    </span>
                  </div>
                  <div className="text-[11px] text-slate-600 mt-1">
                    Sesi: {ev.subject_id === null ? 'Absen Harian (Wali Kelas)' : `Mapel ID #${ev.subject_id}`} · Dicatat via {ev.recorded_via}
                  </div>
                  {ev.notes && (
                    <div className="text-[11px] text-slate-500 italic mt-1 bg-white p-1.5 rounded border border-slate-200">
                      &ldquo;{ev.notes}&rdquo;
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Right: Academic Grades Dossier */}
        <div className="bg-white border border-slate-200 rounded-lg p-5">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-1.5">
              <GraduationCap className="w-4 h-4 text-indigo-600" />
              <h3 className="text-sm font-bold text-slate-900">
                Nilai Kegiatan Akademik
              </h3>
            </div>
            <span className="text-xs font-mono text-slate-500">
              {studentGrades.length} kegiatan
            </span>
          </div>

          {studentGrades.length === 0 ? (
            <div className="py-12 text-center text-xs text-slate-400">
              Belum ada rekaman nilai kegiatan untuk siswa ini.
            </div>
          ) : (
            <div className="divide-y divide-slate-100">
              {studentGrades.map((g, idx) => (
                <div key={idx} className="py-2.5 flex items-center justify-between text-xs">
                  <div>
                    <div className="font-semibold text-slate-900">{g.activityName}</div>
                    <div className="text-[11px] text-slate-500">
                      {g.subjectName} · <span className="font-mono">{g.tanggal}</span>
                    </div>
                  </div>
                  <div className="text-right">
                    <span
                      className={`font-mono text-sm font-bold px-2 py-0.5 rounded ${
                        g.skala === 'angka'
                          ? parseFloat(g.nilai) >= 80
                            ? 'text-emerald-700 bg-emerald-50'
                            : parseFloat(g.nilai) >= 70
                            ? 'text-amber-700 bg-amber-50'
                            : 'text-rose-700 bg-rose-50'
                          : g.nilai === 'A' || g.nilai === 'B'
                          ? 'text-emerald-700 bg-emerald-50'
                          : 'text-rose-700 bg-rose-50'
                      }`}
                    >
                      {g.nilai}
                    </span>
                    <div className="text-[10px] text-slate-400 capitalize">
                      Skala {g.skala}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Google Docs Generation Modal */}
      {showGoogleDocsModal && student && (
        <GoogleDocsModal
          isOpen={showGoogleDocsModal}
          onClose={() => setShowGoogleDocsModal(false)}
          student={student}
          className={studentClass?.name || 'Kelas'}
          records={studentAttendance}
          settings={storage.getSettings()}
          defaultMode="warning_letter"
        />
      )}

      {/* Akses Portal Orang Tua/Wali Murid */}
      <ParentAccessModal
        isOpen={showParentAccessModal}
        student={student || null}
        onClose={() => setShowParentAccessModal(false)}
      />
    </div>
  );
};
