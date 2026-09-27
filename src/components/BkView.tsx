import React, { useState, useMemo } from 'react';
import { User, ClassItem, Student } from '../types';
import { storage } from '../services/storage';
import { GoogleDocsModal } from './GoogleDocsModal';
import {
  HeartHandshake,
  CheckCircle2,
  AlertTriangle,
  Send,
  Calendar,
  UserCheck,
  FileText,
} from 'lucide-react';

interface BkViewProps {
  currentUser: User;
  classes: ClassItem[];
  onSelectStudent: (studentId: number) => void;
}

export const BkView: React.FC<BkViewProps> = ({
  currentUser,
  classes,
  onSelectStudent,
}) => {
  const [selectedClassId, setSelectedClassId] = useState<number>(classes[0]?.id || 1);
  const [manualStudentId, setManualStudentId] = useState<number>(0);
  const [manualStatus, setManualStatus] = useState<'H' | 'I' | 'S' | 'A'>('I');
  const [manualDate, setManualDate] = useState<string>(() =>
    new Date().toISOString().substring(0, 10)
  );
  const [manualNotes, setManualNotes] = useState<string>('Konseling BK: Izin dispensasi pendampingan keluarga');
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [selectedDocsStudent, setSelectedDocsStudent] = useState<Student | null>(null);

  const activeStudents = useMemo(() => {
    return storage
      .getStudents()
      .filter((s) => s.class_id === selectedClassId && s.status === 'aktif');
  }, [selectedClassId]);

  // Set default selected student
  React.useEffect(() => {
    if (activeStudents.length > 0 && manualStudentId === 0) {
      setManualStudentId(activeStudents[0].id);
    }
  }, [activeStudents, manualStudentId]);

  // BK Attendance Recap for this class (PRD 6.8: endpoint getAbsenUntukBK)
  const bkRecap = useMemo(() => {
    const allAtt = storage.getAttendance().filter((a) => a.class_id === selectedClassId);
    return activeStudents.map((s) => {
      const sRecords = allAtt.filter((r) => r.student_id === s.id);
      let h = 0, i = 0, sc = 0, a = 0;
      sRecords.forEach((r) => {
        if (r.status === 'H') h++;
        else if (r.status === 'I') i++;
        else if (r.status === 'S') sc++;
        else if (r.status === 'A') a++;
      });
      const total = h + i + sc + a;
      return {
        student: s,
        hadir: h,
        izin: i,
        sakit: sc,
        alpa: a,
        totalAbsen: i + sc + a,
        totalPertemuan: total,
      };
    }).sort((a, b) => b.totalAbsen - a.totalAbsen);
  }, [selectedClassId, activeStudents, feedback]);

  // Submit manual BK attendance (PRD 6.8: endpoint simpanAbsenUntukBK)
  const handleSaveBkAttendance = (e: React.FormEvent) => {
    e.preventDefault();
    setFeedback(null);

    const st = activeStudents.find((s) => s.id === manualStudentId);
    if (!st) {
      setFeedback({ type: 'error', text: 'Pilih siswa terlebih dahulu.' });
      return;
    }

    try {
      // Writes to the SAME unified attendance table as specified in PRD 6.8!
      storage.submitAttendanceBatch({
        class_id: selectedClassId,
        subject_id: null, // Unified with Absen Harian
        tanggal: manualDate,
        recorded_by: currentUser.id,
        recorded_via: 'bk_manual',
        entries: [
          {
            student_id: st.id,
            status: manualStatus,
            notes: manualNotes.trim(),
          },
        ],
      });

      storage.addAuditLog(
        'Input Absensi Manual BK',
        'BK',
        st.nama,
        `Status ${manualStatus} pada tanggal ${manualDate}. Catatan: ${manualNotes}`
      );

      setFeedback({
        type: 'success',
        text: `Presensi manual BK berhasil dicatat untuk ${st.nama} ke dalam tabel terpadu attendance.`,
      });
      setTimeout(() => setFeedback(null), 5000);
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message || 'Gagal menyimpan presensi BK.' });
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200">
        <div>
          <div className="flex items-center gap-2">
            <HeartHandshake className="w-5 h-5 text-indigo-700" />
            <h1 className="text-xl font-bold tracking-tight text-slate-900">
              Integrasi Aplikasi Manajemen Bimbingan Konseling (BK)
            </h1>
          </div>
          <p className="text-xs text-slate-500 mt-1">
            Menyediakan akses rekap ketidakhadiran per kelas serta entri absensi manual resmi yang terhubung langsung ke tabel terpadu basis data sekolah.
          </p>
        </div>

        <div>
          <select
            value={selectedClassId}
            onChange={(e) => setSelectedClassId(Number(e.target.value))}
            aria-label="Pilih Kelas Monitoring BK"
            className="text-xs font-semibold bg-white border border-slate-200 rounded-lg px-3 py-2 text-slate-800 focus:ring-1 focus:ring-slate-900"
          >
            {classes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name} ({c.jurusan})
              </option>
            ))}
          </select>
        </div>
      </div>

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

      {/* Grid: Form Input Manual BK + Tabel Rekap Siswa BK */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left: Form Simpan Absen Manual dari BK */}
        <div className="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
          <div>
            <h2 className="text-sm font-bold text-slate-900">
              Entri Absensi Manual BK
            </h2>
            <p className="text-xs text-slate-500 mt-0.5">
              Ditulis langsung ke tabel <code>attendance</code> dengan flag <code>recorded_via: &apos;bk_manual&apos;</code>.
            </p>
          </div>

          <form onSubmit={handleSaveBkAttendance} className="space-y-3">
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Pilih Siswa
              </label>
              <select
                value={manualStudentId}
                onChange={(e) => setManualStudentId(Number(e.target.value))}
                className="w-full text-xs bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"
              >
                {activeStudents.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.nama} ({s.nis})
                  </option>
                ))}
              </select>
            </div>

            <div className="grid grid-cols-2 gap-2">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Tanggal
                </label>
                <input
                  type="date"
                  value={manualDate}
                  onChange={(e) => setManualDate(e.target.value)}
                  className="w-full text-xs bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Status
                </label>
                <select
                  value={manualStatus}
                  onChange={(e) => setManualStatus(e.target.value as any)}
                  className="w-full text-xs bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 font-bold"
                >
                  <option value="I">Izin (I)</option>
                  <option value="S">Sakit (S)</option>
                  <option value="A">Alpa (A)</option>
                  <option value="H">Hadir (H)</option>
                </select>
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Catatan Bimbingan / Rekomendasi BK
              </label>
              <textarea
                rows={3}
                value={manualNotes}
                onChange={(e) => setManualNotes(e.target.value)}
                placeholder="cth: Telah konseling dengan orang tua, dispensasi urusan keluarga..."
                className="w-full text-xs p-2 bg-slate-50 border border-slate-200 rounded-lg focus:ring-1 focus:ring-slate-900"
              />
            </div>

            <button
              type="submit"
              className="w-full flex items-center justify-center gap-1.5 py-2 px-3 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors shadow-xs"
            >
              <Send className="w-3.5 h-3.5" />
              Simpan Presensi Manual BK
            </button>
          </form>
        </div>

        {/* Right: Rekap Absensi Siswa Kelas (getAbsenUntukBK endpoint view) */}
        <div className="lg:col-span-2 bg-white border border-slate-200 rounded-lg p-5 space-y-3">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-sm font-bold text-slate-900">
                Pemantauan Absensi Kelas untuk Bimbingan Konseling
              </h2>
              <p className="text-xs text-slate-500">
                Siswa dengan akumulasi Alpa atau ketidakhadiran tinggi diprioritaskan di baris teratas.
              </p>
            </div>
            <span className="text-xs font-mono text-slate-500">
              {activeStudents.length} siswa
            </span>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-xs text-left border-collapse">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-slate-500 font-medium">
                  <th className="py-2.5 px-3">Nama Siswa</th>
                  <th className="py-2.5 px-3">NIS</th>
                  <th className="py-2.5 px-3 text-center">Hadir</th>
                  <th className="py-2.5 px-3 text-center">Izin</th>
                  <th className="py-2.5 px-3 text-center">Sakit</th>
                  <th className="py-2.5 px-3 text-center font-bold text-rose-700">Alpa</th>
                  <th className="py-2.5 px-3 text-center font-bold text-slate-900">Total Absen</th>
                  <th className="py-2.5 px-3 text-right">Tindakan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {bkRecap.map((item) => (
                  <tr key={item.student.id} className="hover:bg-slate-50/80 transition-colors">
                    <td className="py-2.5 px-3 font-medium text-slate-900">
                      {item.student.nama}
                    </td>
                    <td className="py-2.5 px-3 font-mono text-slate-500">
                      {item.student.nis}
                    </td>
                    <td className="py-2.5 px-3 text-center font-mono text-emerald-700 font-semibold tabular-nums">
                      {item.hadir}
                    </td>
                    <td className="py-2.5 px-3 text-center font-mono text-slate-700 tabular-nums">
                      {item.izin}
                    </td>
                    <td className="py-2.5 px-3 text-center font-mono text-amber-700 tabular-nums">
                      {item.sakit}
                    </td>
                    <td className="py-2.5 px-3 text-center font-mono font-bold text-rose-700 tabular-nums">
                      {item.alpa}
                    </td>
                    <td className="py-2.5 px-3 text-center font-mono font-bold text-slate-900 tabular-nums">
                      {item.totalAbsen}
                    </td>
                    <td className="py-2.5 px-3 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <button
                          onClick={() => setSelectedDocsStudent(item.student)}
                          className="flex items-center gap-1 text-[11px] text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded hover:bg-blue-100 font-semibold"
                          title="Buat Surat Peringatan / Panggilan Orang Tua di Google Docs"
                        >
                          <FileText className="w-3 h-3" />
                          <span>Surat Docs</span>
                        </button>
                        <button
                          onClick={() => onSelectStudent(item.student.id)}
                          className="text-xs text-indigo-600 hover:text-indigo-900 font-semibold"
                        >
                          Profil &rarr;
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Google Docs Modal for BK */}
      {selectedDocsStudent && (
        <GoogleDocsModal
          isOpen={Boolean(selectedDocsStudent)}
          onClose={() => setSelectedDocsStudent(null)}
          student={selectedDocsStudent}
          className={classes.find((c) => c.id === selectedClassId)?.name || 'Kelas'}
          records={storage.getAttendance().filter((a) => a.student_id === selectedDocsStudent.id)}
          settings={storage.getSettings()}
          defaultMode="parent_summons"
        />
      )}
    </div>
  );
};
