import React, { useState, useMemo } from 'react';
import { User, ClassItem, Subject } from '../types';
import { storage } from '../services/storage';
import {
  Users,
  AlertTriangle,
  Clock,
  Sparkles,
  ArrowUpRight,
  TrendingDown,
  Info,
} from 'lucide-react';

interface DashboardViewProps {
  currentUser: User;
  classes: ClassItem[];
  subjects: Subject[];
  onSelectStudent: (studentId: number) => void;
}

export const DashboardView: React.FC<DashboardViewProps> = ({
  currentUser,
  classes,
  subjects,
  onSelectStudent,
}) => {
  // Determine available variants based on user roles
  const isKepsek = currentUser.roles.includes('kepsek') || currentUser.roles.includes('superadmin');
  const isWali = currentUser.kelas_wali_id !== null;
  const isGuru = currentUser.roles.includes('guru');

  // Active dashboard variant: 'wali' | 'mapel' | 'sekolah'
  const defaultVariant = isWali ? 'wali' : isKepsek ? 'sekolah' : 'mapel';
  const [variant, setVariant] = useState<'wali' | 'mapel' | 'sekolah'>(defaultVariant);

  // Filters
  const [selectedClassId, setSelectedClassId] = useState<number>(
    isWali && currentUser.kelas_wali_id ? currentUser.kelas_wali_id : classes[0]?.id || 1
  );
  const [selectedSubjectId, setSelectedSubjectId] = useState<number | null>(
    currentUser.subjects && currentUser.subjects.length > 0 ? currentUser.subjects[0] : 3
  );

  const [attentionCategoryFilter, setAttentionCategoryFilter] = useState<string>('all');

  // Compute metrics depending on current variant
  const allAttendance = storage.getAttendance();
  const allStudents = storage.getStudents();
  const pairings = storage.getPairings();

  // Active filter context
  const activeClass = classes.find((c) => c.id === selectedClassId);
  const activeSubject = subjects.find((s) => s.id === selectedSubjectId);

  // 1. Records subset according to PRD 6.4 rules:
  // - Wali: 1 class, subject_id is null ("Absen Harian")
  // - Mapel: teacher's classes, specific subject_id
  // - Sekolah (Kepsek): ALL classes, strictly subject_id === null to prevent double counting!
  const filteredRecords = useMemo(() => {
    return allAttendance.filter((r) => {
      if (variant === 'wali') {
        const waliClassId = currentUser.kelas_wali_id || selectedClassId;
        return r.class_id === waliClassId && r.subject_id === null;
      } else if (variant === 'sekolah') {
        if (selectedClassId && selectedClassId !== 0) {
          return r.class_id === selectedClassId && r.subject_id === null;
        }
        return r.subject_id === null;
      } else {
        // 'mapel'
        if (selectedSubjectId !== null && r.subject_id !== selectedSubjectId) return false;
        if (selectedClassId && r.class_id !== selectedClassId) return false;
        return true;
      }
    });
  }, [allAttendance, variant, selectedClassId, selectedSubjectId, currentUser.kelas_wali_id]);

  // Aggregate stats
  const totalEntries = filteredRecords.length;
  const hadirCount = filteredRecords.filter((r) => r.status === 'H').length;
  const izinCount = filteredRecords.filter((r) => r.status === 'I').length;
  const sakitCount = filteredRecords.filter((r) => r.status === 'S').length;
  const alpaCount = filteredRecords.filter((r) => r.status === 'A').length;

  const attendanceRate = totalEntries > 0 ? (hadirCount / totalEntries) * 100 : 100;
  const uniqueDates = Array.from(new Set(filteredRecords.map((r) => r.tanggal))).sort();
  const totalMeetings = uniqueDates.length;

  // Pola Absen Berkala (PRD 6.4)
  const patternAlerts = useMemo(() => {
    const classScope = variant === 'sekolah' ? (selectedClassId !== 0 ? selectedClassId : undefined) : selectedClassId;
    const subjectScope = variant === 'wali' || variant === 'sekolah' ? null : selectedSubjectId;
    return storage.detectPeriodicPatterns(classScope, subjectScope);
  }, [variant, selectedClassId, selectedSubjectId]);

  // Perlu Perhatian (PRD 6.4)
  const attentionStudents = useMemo(() => {
    const classScope = variant === 'sekolah' ? (selectedClassId !== 0 ? selectedClassId : undefined) : selectedClassId;
    const subjectScope = variant === 'wali' || variant === 'sekolah' ? null : selectedSubjectId;
    return storage.getAttentionStudents(classScope, subjectScope);
  }, [variant, selectedClassId, selectedSubjectId]);

  const filteredAttentionStudents = useMemo(() => {
    if (attentionCategoryFilter === 'all') return attentionStudents;
    return attentionStudents.filter((s) => s.category === attentionCategoryFilter);
  }, [attentionStudents, attentionCategoryFilter]);

  // Priority Signal: Students declining in BOTH attendance AND grades (PRD 6.4)
  const priorityDualDropCount = attentionStudents.filter((s) => s.hasGradeDrop).length;

  // Automated narrative & recommendation (Threshold-based, Indonesian)
  const narrative = useMemo(() => {
    const scopeLabel =
      variant === 'wali'
        ? `Kelas Wali ${activeClass?.name || 'XI DKV 1'}`
        : variant === 'sekolah'
        ? selectedClassId !== 0
          ? `Kelas ${activeClass?.name || ''} (Tingkat Sekolah)`
          : 'Seluruh Kelas (Agregat Sekolah)'
        : `Mata Pelajaran ${activeSubject?.name || ''} - ${activeClass?.name || ''}`;

    return storage.generateAutomatedNarrative({
      rate: attendanceRate,
      hadirCount,
      alpaCount,
      izinCount,
      sakitCount,
      attentionCount: attentionStudents.length,
      patternCount: patternAlerts.length,
      scopeName: scopeLabel,
      hasGradeDropCount: priorityDualDropCount,
    });
  }, [
    attendanceRate,
    hadirCount,
    alpaCount,
    izinCount,
    sakitCount,
    attentionStudents.length,
    patternAlerts.length,
    variant,
    activeClass,
    activeSubject,
    selectedClassId,
    priorityDualDropCount,
  ]);

  // Date trend
  const dateTrend = useMemo(() => {
    return uniqueDates.map((date) => {
      const recordsOnDate = filteredRecords.filter((r) => r.tanggal === date);
      const h = recordsOnDate.filter((r) => r.status === 'H').length;
      const total = recordsOnDate.length;
      const pct = total > 0 ? Math.round((h / total) * 100) : 0;
      return { date, pct, total, h };
    });
  }, [uniqueDates, filteredRecords]);

  return (
    <div className="space-y-6">
      {/* Top Controls: Dashboard Variant Switcher & Scope Selectors */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-200">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-slate-900">
            {variant === 'wali'
              ? `Dashboard Wali Kelas — ${activeClass?.name || 'XI DKV 1'}`
              : variant === 'sekolah'
              ? 'Dashboard Sekolah (Kepsek) — Agregat Harian'
              : `Dashboard Guru Mapel — ${activeSubject?.name || 'Pemrograman'}`}
          </h1>
          <div className="flex items-center gap-2 text-xs text-slate-500 mt-1">
            <span>Tahun Ajaran 2024/2025</span>
            <span aria-hidden="true">·</span>
            <span>Semester Genap</span>
            <span aria-hidden="true">·</span>
            <span className="font-mono tabular-nums">{totalMeetings} Pertemuan Tercatat</span>
          </div>
        </div>

        {/* Variant Tabs & Selectors */}
        <div className="flex flex-wrap items-center gap-2">
          {/* Variant Segmented Control */}
          <div className="inline-flex p-1 bg-slate-100 rounded-lg text-xs font-medium">
            {isWali && (
              <button
                onClick={() => setVariant('wali')}
                className={`px-3 py-1.5 rounded-md transition-colors ${
                  variant === 'wali' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                Wali Kelas
              </button>
            )}
            {isGuru && (
              <button
                onClick={() => setVariant('mapel')}
                className={`px-3 py-1.5 rounded-md transition-colors ${
                  variant === 'mapel' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                Per Mapel
              </button>
            )}
            {isKepsek && (
              <button
                onClick={() => setVariant('sekolah')}
                className={`px-3 py-1.5 rounded-md transition-colors ${
                  variant === 'sekolah' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                Sekolah (Kepsek)
              </button>
            )}
          </div>

          {/* Class Filter */}
          <select
            value={selectedClassId}
            onChange={(e) => setSelectedClassId(Number(e.target.value))}
            aria-label="Pilih Kelas"
            className="text-xs font-medium bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-slate-800 focus:outline-hidden focus:ring-1 focus:ring-slate-900"
          >
            {variant === 'sekolah' && <option value={0}>Semua Kelas (Agregat)</option>}
            {classes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name} ({c.jurusan})
              </option>
            ))}
          </select>

          {/* Subject Filter (Visible only in 'mapel' variant) */}
          {variant === 'mapel' && (
            <select
              value={selectedSubjectId || ''}
              onChange={(e) => setSelectedSubjectId(Number(e.target.value))}
              aria-label="Pilih Mata Pelajaran"
              className="text-xs font-medium bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-slate-800 focus:outline-hidden focus:ring-1 focus:ring-slate-900"
            >
              {subjects
                .filter((s) => s.id !== 5) // Exclude BK subject from standard mapel list
                .map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
            </select>
          )}
        </div>
      </div>

      {/* Notice for Kepsek aggregation rule (PRD 6.4: Only daily attendance, no double count) */}
      {variant === 'sekolah' && (
        <div className="flex items-start gap-2.5 p-3 text-xs text-slate-700 bg-slate-100 border border-slate-200 rounded-lg">
          <Info className="w-4 h-4 text-slate-500 shrink-0 mt-0.5" />
          <p>
            <strong>Aturan Agregasi Sekolah:</strong> Data dashboard kepsek dihitung murni dari rekaman absensi harian wali kelas (bukan gabungan mapel), guna menjamin keakuratan statistik tanpa risiko <em>double-counting</em> siswa yang hadir di beberapa sesi pelajaran.
          </p>
        </div>
      )}

      {/* KPI Metric Cards */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div className="col-span-2 md:col-span-1 bg-white border border-slate-200 rounded-lg p-4">
          <div className="text-xs font-medium text-slate-500">Tingkat Kehadiran</div>
          <div className="flex items-baseline gap-2 mt-2">
            <span
              className={`text-2xl font-bold font-mono tabular-nums ${
                attendanceRate >= 90
                  ? 'text-emerald-700'
                  : attendanceRate >= 80
                  ? 'text-amber-700'
                  : 'text-rose-700'
              }`}
            >
              {attendanceRate.toFixed(1)}%
            </span>
          </div>
          <div className="text-[11px] text-slate-400 mt-1">
            Standar target sekolah: &ge; 85%
          </div>
        </div>

        <div className="bg-white border border-slate-200 rounded-lg p-4">
          <div className="text-xs font-medium text-slate-500">Hadir (H)</div>
          <div className="text-2xl font-bold text-slate-900 font-mono tabular-nums mt-2">
            {hadirCount}
          </div>
          <div className="text-[11px] text-emerald-600 mt-1">Presensi aktif</div>
        </div>

        <div className="bg-white border border-slate-200 rounded-lg p-4">
          <div className="text-xs font-medium text-slate-500">Izin (I)</div>
          <div className="text-2xl font-bold text-slate-900 font-mono tabular-nums mt-2">
            {izinCount}
          </div>
          <div className="text-[11px] text-slate-500 mt-1">Surat terverifikasi</div>
        </div>

        <div className="bg-white border border-slate-200 rounded-lg p-4">
          <div className="text-xs font-medium text-slate-500">Sakit (S)</div>
          <div className="text-2xl font-bold text-slate-900 font-mono tabular-nums mt-2">
            {sakitCount}
          </div>
          <div className="text-[11px] text-amber-600 mt-1">Surat dokter / wali</div>
        </div>

        <div className="bg-white border border-slate-200 rounded-lg p-4">
          <div className="text-xs font-medium text-slate-500">Alpa (A)</div>
          <div className="text-2xl font-bold text-rose-700 font-mono tabular-nums mt-2">
            {alpaCount}
          </div>
          <div className="text-[11px] text-rose-600 mt-1">Tanpa keterangan</div>
        </div>
      </div>

      {/* Ringkasan & Saran Otomatis (Threshold-Based Narrative Engine) */}
      <div className="bg-slate-900 text-white rounded-lg p-5">
        <div className="flex items-center gap-2 mb-2">
          <Sparkles className="w-4 h-4 text-amber-400" />
          <h2 className="text-sm font-semibold tracking-wide text-amber-300">
            Ringkasan & Saran Otomatis Berdasarkan Kondisi Data
          </h2>
          <span className="text-[11px] text-slate-400 ml-auto hidden sm:inline">
            Mesin Logika Ambang Batas (Non-Generatif)
          </span>
        </div>
        <p className="text-sm text-slate-200 leading-relaxed">
          {narrative.summary}
        </p>
        <div className="mt-3 pt-3 border-t border-slate-800 text-xs text-amber-200 flex items-start gap-2">
          <ArrowUpRight className="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
          <span>{narrative.recommendation}</span>
        </div>
      </div>

      {/* Grid: Trend & Pola Absen Berkala */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Trend Bar Timeline */}
        <div className="lg:col-span-2 bg-white border border-slate-200 rounded-lg p-5">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h2 className="text-sm font-bold text-slate-900">
                Tren Kehadiran dari Waktu ke Waktu
              </h2>
              <p className="text-xs text-slate-500">
                Persentase siswa hadir per tanggal sesi
              </p>
            </div>
            <span className="text-xs font-mono text-slate-500">
              {dateTrend.length} sesi pertemuan
            </span>
          </div>

          {dateTrend.length === 0 ? (
            <div className="py-12 text-center text-xs text-slate-400">
              Belum ada data rekaman absensi untuk kombinasi ini.
            </div>
          ) : (
            <div className="space-y-3">
              {dateTrend.map((d) => (
                <div key={d.date} className="flex items-center gap-3 text-xs">
                  <span className="w-24 font-mono text-slate-600 shrink-0">
                    {d.date}
                  </span>
                  <div className="flex-1 h-3.5 bg-slate-100 rounded-full overflow-hidden flex">
                    <div
                      className={`h-full transition-all ${
                        d.pct >= 90
                          ? 'bg-emerald-600'
                          : d.pct >= 80
                          ? 'bg-amber-500'
                          : 'bg-rose-600'
                      }`}
                      style={{ width: `${d.pct}%` }}
                    />
                  </div>
                  <span className="w-12 text-right font-mono font-semibold text-slate-800 tabular-nums">
                    {d.pct}%
                  </span>
                  <span className="text-[11px] text-slate-400 w-16 text-right tabular-nums">
                    ({d.h}/{d.total})
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Pola Absen Berkala Detector (PRD 6.4) */}
        <div className="bg-white border border-slate-200 rounded-lg p-5">
          <div className="flex items-center justify-between mb-3">
            <div className="flex items-center gap-1.5">
              <Clock className="w-4 h-4 text-amber-600" />
              <h2 className="text-sm font-bold text-slate-900">
                Deteksi Pola Absen Berkala
              </h2>
            </div>
          </div>
          <p className="text-xs text-slate-500 mb-3 leading-relaxed">
            Mendeteksi siswa yang berulang kali absen pada <strong>hari yang sama</strong> dengan jarak antarkejadian ~14 hari (toleransi 10–18 hari).
          </p>

          {patternAlerts.length === 0 ? (
            <div className="p-4 bg-slate-50 border border-slate-100 rounded-lg text-center text-xs text-slate-500">
              Tidak terdeteksi pola keteraturan berkala pada rentang ini.
            </div>
          ) : (
            <div className="space-y-2.5">
              {patternAlerts.map((alert, i) => (
                <div
                  key={i}
                  className="p-3 bg-amber-50/70 border border-amber-200 rounded-lg"
                >
                  <div className="flex items-center justify-between">
                    <button
                      onClick={() => onSelectStudent(alert.student_id)}
                      className="text-xs font-semibold text-slate-900 hover:underline text-left"
                    >
                      {alert.student_name}
                    </button>
                    <span
                      className={`text-[10px] font-bold px-1.5 py-0.5 rounded-sm ${
                        alert.status_type === 'Pola'
                          ? 'bg-rose-100 text-rose-800 border border-rose-300'
                          : 'bg-amber-100 text-amber-800 border border-amber-300'
                      }`}
                    >
                      {alert.status_type} ({alert.count}x)
                    </span>
                  </div>
                  <div className="text-[11px] text-slate-600 mt-1">
                    Selalu absen di hari <strong>{alert.day_of_week}</strong>
                  </div>
                  <div className="text-[10px] font-mono text-slate-500 mt-1">
                    Tanggal: {alert.dates.join(', ')}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Daftar Siswa "Perlu Perhatian" (4 Kategori + Priority Dual Drop Signal) */}
      <div className="bg-white border border-slate-200 rounded-lg p-5">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
          <div>
            <div className="flex items-center gap-2">
              <AlertTriangle className="w-4 h-4 text-rose-600" />
              <h2 className="text-sm font-bold text-slate-900">
                Daftar Siswa Perlu Perhatian
              </h2>
              {priorityDualDropCount > 0 && (
                <span className="text-[11px] font-semibold text-rose-700 bg-rose-50 border border-rose-200 px-2 py-0.5 rounded-md">
                  {priorityDualDropCount} Sinyal Prioritas (Absen + Nilai Turun)
                </span>
              )}
            </div>
            <p className="text-xs text-slate-500 mt-0.5">
              Diurutkan berdasarkan tingkat signifikansi ketidakhadiran & dampak akademik
            </p>
          </div>

          {/* Category Tabs */}
          <div className="inline-flex p-1 bg-slate-100 rounded-lg text-xs font-medium">
            <button
              onClick={() => setAttentionCategoryFilter('all')}
              className={`px-2.5 py-1 rounded-md transition-colors ${
                attentionCategoryFilter === 'all'
                  ? 'bg-white text-slate-900 shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              Semua ({attentionStudents.length})
            </button>
            <button
              onClick={() => setAttentionCategoryFilter('alpa_tinggi')}
              className={`px-2.5 py-1 rounded-md transition-colors ${
                attentionCategoryFilter === 'alpa_tinggi'
                  ? 'bg-white text-slate-900 shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              Alpa Tinggi
            </button>
            <button
              onClick={() => setAttentionCategoryFilter('sakit_tinggi')}
              className={`px-2.5 py-1 rounded-md transition-colors ${
                attentionCategoryFilter === 'sakit_tinggi'
                  ? 'bg-white text-slate-900 shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              Sakit Tinggi
            </button>
            <button
              onClick={() => setAttentionCategoryFilter('izin_tinggi')}
              className={`px-2.5 py-1 rounded-md transition-colors ${
                attentionCategoryFilter === 'izin_tinggi'
                  ? 'bg-white text-slate-900 shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              Izin Tinggi
            </button>
            <button
              onClick={() => setAttentionCategoryFilter('jarang_masuk_gabungan')}
              className={`px-2.5 py-1 rounded-md transition-colors ${
                attentionCategoryFilter === 'jarang_masuk_gabungan'
                  ? 'bg-white text-slate-900 shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              Jarang Masuk
            </button>
          </div>
        </div>

        {filteredAttentionStudents.length === 0 ? (
          <div className="py-8 text-center text-xs text-slate-400">
            Tidak ada siswa dalam kategori perhatian ini. Seluruh siswa terpantau tertib.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs text-left border-collapse">
              <thead>
                <tr className="border-b border-slate-200 text-slate-500 font-medium bg-slate-50">
                  <th className="py-2.5 px-3">Nama Siswa</th>
                  <th className="py-2.5 px-3">NIS</th>
                  <th className="py-2.5 px-3">Kategori</th>
                  <th className="py-2.5 px-3 text-center">Alpa</th>
                  <th className="py-2.5 px-3 text-center">Izin</th>
                  <th className="py-2.5 px-3 text-center">Sakit</th>
                  <th className="py-2.5 px-3 text-center">Total Absen</th>
                  <th className="py-2.5 px-3">Status Sinyal</th>
                  <th className="py-2.5 px-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredAttentionStudents.map((s) => (
                  <tr key={s.student_id} className="hover:bg-slate-50/80 transition-colors">
                    <td className="py-2.5 px-3 font-medium text-slate-900">
                      {s.student_name}
                    </td>
                    <td className="py-2.5 px-3 font-mono text-slate-500">
                      {s.nis}
                    </td>
                    <td className="py-2.5 px-3">
                      <span className="text-slate-700 capitalize">
                        {s.category.replace(/_/g, ' ')}
                      </span>
                    </td>
                    <td className="py-2.5 px-3 text-center font-mono font-bold text-rose-700 tabular-nums">
                      {s.alpa_count}
                    </td>
                    <td className="py-2.5 px-3 text-center font-mono text-slate-700 tabular-nums">
                      {s.izin_count}
                    </td>
                    <td className="py-2.5 px-3 text-center font-mono text-amber-700 tabular-nums">
                      {s.sakit_count}
                    </td>
                    <td className="py-2.5 px-3 text-center font-mono font-bold text-slate-900 tabular-nums">
                      {s.total_absen}
                    </td>
                    <td className="py-2.5 px-3">
                      {s.hasGradeDrop ? (
                        <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-rose-700">
                          <TrendingDown className="w-3.5 h-3.5" />
                          Nilai & Kehadiran Anjlok
                        </span>
                      ) : (
                        <span className="text-[11px] text-slate-400">Normal</span>
                      )}
                    </td>
                    <td className="py-2.5 px-3 text-right">
                      <button
                        onClick={() => onSelectStudent(s.student_id)}
                        className="text-xs text-indigo-600 hover:text-indigo-900 font-semibold"
                      >
                        Lihat Riwayat &rarr;
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
};
