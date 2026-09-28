import React, { useState, useMemo } from 'react';
import { User, ClassItem, Subject, GradeActivity, GradeValue } from '../types';
import { storage, generateGlobalUUID } from '../services/storage';
import { exportGradeRecapToExcel } from '../utils/excel';
import { GoogleSheetsModal } from './GoogleSheetsModal';
import {
  GraduationCap,
  Plus,
  Trash2,
  Edit2,
  Download,
  CheckCircle2,
  AlertCircle,
  FileSpreadsheet,
  Lock,
} from 'lucide-react';

interface GradesViewProps {
  currentUser: User;
  classes: ClassItem[];
  subjects: Subject[];
}

export const GradesView: React.FC<GradesViewProps> = ({
  currentUser,
  classes,
  subjects,
}) => {
  // Scope
  const [selectedClassId, setSelectedClassId] = useState<number>(classes[0]?.id || 1);
  const [selectedSubjectId, setSelectedSubjectId] = useState<number>(
    currentUser.subjects && currentUser.subjects.length > 0 ? currentUser.subjects[0] : 3
  );

  // Active Tab: 'entry' | 'activities' | 'recap'
  const [activeTab, setActiveTab] = useState<'entry' | 'activities' | 'recap'>('entry');
  const [showGoogleSheetsModal, setShowGoogleSheetsModal] = useState<boolean>(false);

  // Form state for creating/editing assessment activity
  const [activityId, setActivityId] = useState<string>(() => `act-${Date.now()}`);
  const [namaKegiatan, setNamaKegiatan] = useState<string>('Ulangan Harian 2: Responsive Web');
  const [tanggalKegiatan, setTanggalKegiatan] = useState<string>(() =>
    new Date().toISOString().substring(0, 10)
  );
  const [tipeSkala, setTipeSkala] = useState<'angka' | 'huruf'>('angka');

  // Student scores map: student_id -> score string ("85" or "A")
  const [scores, setScores] = useState<Record<number, string>>({});
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Active students for selected class
  const activeStudents = useMemo(() => {
    return storage
      .getStudents()
      .filter((s) => s.class_id === selectedClassId && s.status === 'aktif')
      .sort((a, b) => a.nama.localeCompare(b.nama));
  }, [selectedClassId]);

  // Existing activities for current class and subject
  const currentActivities = useMemo(() => {
    return storage
      .getGradeActivities()
      .filter((a) => a.class_id === selectedClassId && a.subject_id === selectedSubjectId)
      .sort((a, b) => b.tanggal_kegiatan.localeCompare(a.tanggal_kegiatan));
  }, [selectedClassId, selectedSubjectId, feedback]);

  const allGradeValues = storage.getGradeValues();

  // Load activity for editing
  const handleLoadActivity = (act: GradeActivity) => {
    setActivityId(act.id);
    setNamaKegiatan(act.nama_kegiatan);
    setTanggalKegiatan(act.tanggal_kegiatan);
    setTipeSkala(act.tipe_skala);

    const values = allGradeValues.filter((v) => v.activity_id === act.id);
    const scoreMap: Record<number, string> = {};
    values.forEach((v) => {
      scoreMap[v.student_id] = v.nilai;
    });
    setScores(scoreMap);
    setActiveTab('entry');
    setFeedback({
      type: 'success',
      text: `Memuat kegiatan "${act.nama_kegiatan}" untuk diedit.`,
    });
  };

  // Start new activity
  const handleNewActivity = () => {
    setActivityId(generateGlobalUUID('act'));
    setNamaKegiatan('');
    setTanggalKegiatan(new Date().toISOString().substring(0, 10));
    setTipeSkala('angka');
    setScores({});
    setActiveTab('entry');
  };

  // Handle score change
  const handleScoreChange = (studentId: number, val: string) => {
    setScores((prev) => ({
      ...prev,
      [studentId]: val,
    }));
  };

  // Quick fill default
  const handleQuickFill = (val: string) => {
    const updated: Record<number, string> = {};
    activeStudents.forEach((s) => {
      updated[s.id] = val;
    });
    setScores(updated);
  };

  // Save activity & grades (PRD 6.3)
  const handleSaveGrades = (e: React.FormEvent) => {
    e.preventDefault();
    setFeedback(null);

    if (!namaKegiatan.trim()) {
      setFeedback({ type: 'error', text: 'Nama kegiatan penilaian wajib diisi.' });
      return;
    }

    try {
      const activityObj: GradeActivity = {
        id: activityId,
        teacher_id: currentUser.id,
        subject_id: selectedSubjectId,
        class_id: selectedClassId,
        nama_kegiatan: namaKegiatan.trim(),
        tanggal_kegiatan: tanggalKegiatan,
        tipe_skala: tipeSkala,
        created_at: new Date().toISOString(),
      };

      const valuesToSave = activeStudents.map((s) => ({
        student_id: s.id,
        nilai: scores[s.id] || (tipeSkala === 'angka' ? '0' : 'C'),
      }));

      storage.saveGradeActivityWithValues(activityObj, valuesToSave);

      setFeedback({
        type: 'success',
        text: `Penilaian "${namaKegiatan}" berhasil disimpan untuk ${valuesToSave.length} siswa!`,
      });
      setTimeout(() => setFeedback(null), 5000);
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message || 'Gagal menyimpan penilaian.' });
    }
  };

  // Delete activity with 7-day rule (PRD 6.3)
  const handleDeleteActivity = (act: GradeActivity) => {
    const today = new Date();
    const actDate = new Date(act.tanggal_kegiatan);
    const diffDays = Math.floor((today.getTime() - actDate.getTime()) / (1000 * 3600 * 24));

    if (diffDays > 7) {
      alert(`Tidak dapat menghapus kegiatan tanggal ${act.tanggal_kegiatan} karena sudah lebih dari 7 hari.`);
      return;
    }

    if (!confirm(`Hapus kegiatan "${act.nama_kegiatan}" beserta seluruh nilai siswa?`)) {
      return;
    }

    try {
      storage.deleteGradeActivity(act.id);
      setFeedback({ type: 'success', text: `Kegiatan "${act.nama_kegiatan}" berhasil dihapus.` });
    } catch (err: any) {
      alert(err.message);
    }
  };

  // Export recap to Excel
  const handleExportRecap = () => {
    const classObj = classes.find((c) => c.id === selectedClassId);
    const subjectObj = subjects.find((s) => s.id === selectedSubjectId);

    exportGradeRecapToExcel({
      className: classObj?.name || 'Kelas',
      subjectName: subjectObj?.name || 'Mapel',
      students: activeStudents,
      activities: currentActivities,
      grades: allGradeValues,
    });
  };

  return (
    <div className="space-y-6">
      {/* Top Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-200">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-slate-900">
            Penilaian Siswa (Bebas Per Kegiatan)
          </h1>
          <p className="text-xs text-slate-500 mt-1">
            Fleksibel per tugas, ulangan harian, atau praktik portofolio dengan skala Angka (0-100) atau Huruf (A-E).
          </p>
        </div>

        {/* Action Controls */}
        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex p-1 bg-slate-100 rounded-lg text-xs font-medium">
            <button
              onClick={() => setActiveTab('entry')}
              className={`px-3 py-1.5 rounded-md transition-colors ${
                activeTab === 'entry' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              Input / Edit Nilai
            </button>
            <button
              onClick={() => setActiveTab('activities')}
              className={`px-3 py-1.5 rounded-md transition-colors ${
                activeTab === 'activities' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              Daftar Kegiatan ({currentActivities.length})
            </button>
            <button
              onClick={() => setActiveTab('recap')}
              className={`px-3 py-1.5 rounded-md transition-colors ${
                activeTab === 'recap' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              Matriks Rekap
            </button>
          </div>

          <button
            onClick={handleNewActivity}
            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors"
          >
            <Plus className="w-3.5 h-3.5" />
            + Buat Kegiatan Baru
          </button>
        </div>
      </div>

      {/* Scope Bar */}
      <div className="bg-white border border-slate-200 rounded-lg p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
        <div>
          <label className="block text-xs font-medium text-slate-600 mb-1">
            Kelas
          </label>
          <select
            value={selectedClassId}
            onChange={(e) => setSelectedClassId(Number(e.target.value))}
            className="w-full text-xs font-medium bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"
          >
            {classes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name} ({c.jurusan})
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-xs font-medium text-slate-600 mb-1">
            Mata Pelajaran
          </label>
          <select
            value={selectedSubjectId}
            onChange={(e) => setSelectedSubjectId(Number(e.target.value))}
            className="w-full text-xs font-medium bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"
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
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
          )}
          <span>{feedback.text}</span>
        </div>
      )}

      {/* TAB 1: Input / Edit Nilai Kegiatan */}
      {activeTab === 'entry' && (
        <form onSubmit={handleSaveGrades} className="bg-white border border-slate-200 rounded-lg shadow-xs overflow-hidden">
          <div className="p-4 bg-slate-50 border-b border-slate-200 space-y-3">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Nama Kegiatan Penilaian
                </label>
                <input
                  type="text"
                  placeholder="cth: Tugas 2: Pembuatan Komponen UI"
                  value={namaKegiatan}
                  onChange={(e) => setNamaKegiatan(e.target.value)}
                  required
                  className="w-full px-3 py-1.5 text-xs bg-white border border-slate-200 rounded-lg focus:outline-hidden focus:ring-1 focus:ring-slate-900"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Tanggal Kegiatan
                </label>
                <input
                  type="date"
                  value={tanggalKegiatan}
                  onChange={(e) => setTanggalKegiatan(e.target.value)}
                  required
                  className="w-full px-3 py-1.5 text-xs bg-white border border-slate-200 rounded-lg focus:outline-hidden focus:ring-1 focus:ring-slate-900"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Tipe Skala Penilaian
                </label>
                <div className="flex items-center gap-2 mt-1">
                  <label className="flex items-center gap-1.5 text-xs text-slate-700 cursor-pointer">
                    <input
                      type="radio"
                      name="tipeSkala"
                      value="angka"
                      checked={tipeSkala === 'angka'}
                      onChange={() => setTipeSkala('angka')}
                      className="text-slate-900 focus:ring-slate-900"
                    />
                    <span>Angka (0–100)</span>
                  </label>
                  <label className="flex items-center gap-1.5 text-xs text-slate-700 cursor-pointer ml-3">
                    <input
                      type="radio"
                      name="tipeSkala"
                      value="huruf"
                      checked={tipeSkala === 'huruf'}
                      onChange={() => setTipeSkala('huruf')}
                      className="text-slate-900 focus:ring-slate-900"
                    />
                    <span>Huruf (A–E)</span>
                  </label>
                </div>
              </div>
            </div>

            {/* Quick Fill Helpers */}
            <div className="flex items-center gap-2 pt-2 border-t border-slate-200/60 text-xs text-slate-500">
              <span>Isi Cepat Semua:</span>
              {tipeSkala === 'angka' ? (
                <>
                  <button
                    type="button"
                    onClick={() => handleQuickFill('80')}
                    className="px-2 py-0.5 bg-slate-200/80 hover:bg-slate-300 rounded text-slate-800"
                  >
                    80
                  </button>
                  <button
                    type="button"
                    onClick={() => handleQuickFill('85')}
                    className="px-2 py-0.5 bg-slate-200/80 hover:bg-slate-300 rounded text-slate-800"
                  >
                    85
                  </button>
                  <button
                    type="button"
                    onClick={() => handleQuickFill('90')}
                    className="px-2 py-0.5 bg-slate-200/80 hover:bg-slate-300 rounded text-slate-800"
                  >
                    90
                  </button>
                </>
              ) : (
                <>
                  <button
                    type="button"
                    onClick={() => handleQuickFill('A')}
                    className="px-2 py-0.5 bg-slate-200/80 hover:bg-slate-300 rounded text-slate-800 font-bold"
                  >
                    A
                  </button>
                  <button
                    type="button"
                    onClick={() => handleQuickFill('B')}
                    className="px-2 py-0.5 bg-slate-200/80 hover:bg-slate-300 rounded text-slate-800 font-bold"
                  >
                    B
                  </button>
                  <button
                    type="button"
                    onClick={() => handleQuickFill('C')}
                    className="px-2 py-0.5 bg-slate-200/80 hover:bg-slate-300 rounded text-slate-800 font-bold"
                  >
                    C
                  </button>
                </>
              )}
            </div>
          </div>

          {/* Student Grade Entry Table */}
          <div className="overflow-x-auto">
            <table className="w-full text-xs text-left border-collapse">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50/50 text-slate-500 font-medium">
                  <th className="py-2 px-3 w-10 text-center">No</th>
                  <th className="py-2 px-3 w-32">NIS</th>
                  <th className="py-2 px-3">Nama Siswa</th>
                  <th className="py-2 px-3 w-36 text-center">
                    Nilai ({tipeSkala === 'angka' ? '0–100' : 'A/B/C/D/E'})
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {activeStudents.map((s, idx) => {
                  const val = scores[s.id] || '';
                  return (
                    <tr key={s.id} className="hover:bg-slate-50/80 transition-colors">
                      <td className="py-2.5 px-3 text-center font-mono text-slate-400">
                        {idx + 1}
                      </td>
                      <td className="py-2.5 px-3 font-mono text-slate-600">
                        {s.nis}
                      </td>
                      <td className="py-2.5 px-3 font-medium text-slate-900">
                        {s.nama}
                      </td>
                      <td className="py-2.5 px-3 text-center">
                        {tipeSkala === 'angka' ? (
                          <input
                            type="number"
                            min="0"
                            max="100"
                            placeholder="0-100"
                            value={val}
                            onChange={(e) => handleScoreChange(s.id, e.target.value)}
                            className="w-24 text-center py-1 font-mono font-bold text-slate-900 border border-slate-200 rounded-md focus:ring-1 focus:ring-slate-900"
                          />
                        ) : (
                          <select
                            value={val || 'B'}
                            onChange={(e) => handleScoreChange(s.id, e.target.value)}
                            className="w-24 text-center py-1 font-mono font-bold text-slate-900 border border-slate-200 rounded-md focus:ring-1 focus:ring-slate-900"
                          >
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="C">C</option>
                            <option value="D">D</option>
                            <option value="E">E</option>
                          </select>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <div className="p-4 bg-slate-50 border-t border-slate-200 flex items-center justify-between">
            <span className="text-xs text-slate-500">
              Total {activeStudents.length} siswa akan disimpan.
            </span>
            <button
              type="submit"
              className="px-5 py-2 text-xs font-semibold bg-slate-900 hover:bg-slate-800 text-white rounded-lg shadow-xs transition-colors"
            >
              Simpan Nilai Kegiatan
            </button>
          </div>
        </form>
      )}

      {/* TAB 2: Daftar Kegiatan & Riwayat */}
      {activeTab === 'activities' && (
        <div className="bg-white border border-slate-200 rounded-lg p-5">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h2 className="text-sm font-bold text-slate-900">
                Riwayat Kegiatan Penilaian Tersimpan
              </h2>
              <p className="text-xs text-slate-500">
                Data dapat diedit atau dihapus dalam batas waktu 7 hari.
              </p>
            </div>
          </div>

          {currentActivities.length === 0 ? (
            <div className="py-12 text-center text-xs text-slate-400">
              Belum ada kegiatan penilaian untuk mata pelajaran dan kelas ini.
            </div>
          ) : (
            <div className="divide-y divide-slate-100">
              {currentActivities.map((act) => {
                const values = allGradeValues.filter((v) => v.activity_id === act.id);
                const today = new Date();
                const actDate = new Date(act.tanggal_kegiatan);
                const diffDays = Math.floor((today.getTime() - actDate.getTime()) / (1000 * 3600 * 24));
                const isDeletable = diffDays <= 7;

                return (
                  <div key={act.id} className="py-3 flex items-center justify-between gap-4">
                    <div>
                      <div className="text-xs font-bold text-slate-900">
                        {act.nama_kegiatan}
                      </div>
                      <div className="flex items-center gap-2 text-[11px] text-slate-500 mt-1">
                        <span className="font-mono">{act.tanggal_kegiatan}</span>
                        <span aria-hidden="true">·</span>
                        <span className="capitalize">Skala: {act.tipe_skala}</span>
                        <span aria-hidden="true">·</span>
                        <span>{values.length} siswa dinilai</span>
                      </div>
                    </div>

                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => handleLoadActivity(act)}
                        className="flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-slate-700 bg-slate-100 hover:bg-slate-200 rounded transition-colors"
                      >
                        <Edit2 className="w-3.5 h-3.5" />
                        Edit Nilai
                      </button>

                      {isDeletable ? (
                        <button
                          onClick={() => handleDeleteActivity(act)}
                          className="p-1.5 text-rose-600 hover:bg-rose-50 rounded"
                          title="Hapus kegiatan ini (dalam batas 7 hari)"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      ) : (
                        <span
                          className="p-1.5 text-slate-300 cursor-not-allowed"
                          title="Terkunci: Kegiatan lebih dari 7 hari lalu"
                        >
                          <Lock className="w-3.5 h-3.5" />
                        </span>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {/* TAB 3: Matriks Rekap Nilai Kelas */}
      {activeTab === 'recap' && (
        <div className="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 className="text-sm font-bold text-slate-900">
                Matriks Rekap Nilai Siswa
              </h2>
              <p className="text-xs text-slate-500">
                Gabungan seluruh penilaian per kegiatan untuk kelas dan mapel ini.
              </p>
            </div>

            <div className="flex items-center gap-2">
              <button
                onClick={() => setShowGoogleSheetsModal(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-emerald-800 bg-white border border-emerald-300 rounded-lg hover:bg-emerald-50 transition-colors shadow-xs"
              >
                <FileSpreadsheet className="w-3.5 h-3.5 text-emerald-600" />
                Google Sheets
              </button>

              <button
                onClick={handleExportRecap}
                className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors"
              >
                <Download className="w-3.5 h-3.5 text-slate-500" />
                Excel (.xlsx)
              </button>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-xs text-left border-collapse">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-slate-600 font-medium">
                  <th className="py-2.5 px-3 w-10 text-center">No</th>
                  <th className="py-2.5 px-3 w-32">NIS</th>
                  <th className="py-2.5 px-3 min-w-[150px]">Nama Siswa</th>
                  {currentActivities.map((act) => (
                    <th key={act.id} className="py-2.5 px-3 text-center whitespace-nowrap">
                      <div>{act.nama_kegiatan}</div>
                      <div className="text-[10px] text-slate-400 font-mono">
                        ({act.tipe_skala})
                      </div>
                    </th>
                  ))}
                  <th className="py-2.5 px-3 text-center font-bold text-slate-900">
                    Rata-Rata (Angka)
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {activeStudents.map((s, idx) => {
                  let numSum = 0;
                  let numCount = 0;

                  return (
                    <tr key={s.id} className="hover:bg-slate-50/80 transition-colors">
                      <td className="py-2.5 px-3 text-center font-mono text-slate-400">
                        {idx + 1}
                      </td>
                      <td className="py-2.5 px-3 font-mono text-slate-500">{s.nis}</td>
                      <td className="py-2.5 px-3 font-medium text-slate-900">{s.nama}</td>
                      {currentActivities.map((act) => {
                        const val = allGradeValues.find(
                          (v) => v.activity_id === act.id && v.student_id === s.id
                        )?.nilai || '-';

                        if (act.tipe_skala === 'angka') {
                          const n = parseFloat(val);
                          if (!isNaN(n)) {
                            numSum += n;
                            numCount++;
                          }
                        }

                        return (
                          <td key={act.id} className="py-2.5 px-3 text-center font-mono tabular-nums">
                            {val}
                          </td>
                        );
                      })}
                      <td className="py-2.5 px-3 text-center font-mono font-bold text-slate-900 tabular-nums">
                        {numCount > 0 ? (numSum / numCount).toFixed(1) : '-'}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Google Sheets Export Modal */}
      {showGoogleSheetsModal && (
        <GoogleSheetsModal
          isOpen={showGoogleSheetsModal}
          onClose={() => setShowGoogleSheetsModal(false)}
          mode="grades"
          payload={{
            className: classes.find((c) => c.id === selectedClassId)?.name || 'Kelas',
            subjectName: subjects.find((s) => s.id === selectedSubjectId)?.name || 'Mapel',
            students: activeStudents,
            activities: currentActivities,
            grades: allGradeValues,
          }}
        />
      )}
    </div>
  );
};
