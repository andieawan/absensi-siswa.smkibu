import React, { useState, useEffect } from 'react';
import { storage } from '../services/storage';
import { ClassItem, AttendanceStatus } from '../types';
import { CheckCircle2, UserCheck, Send, ShieldAlert } from 'lucide-react';

interface DelegatedStudentViewProps {
  token: string;
  onExit: () => void;
}

export const DelegatedStudentView: React.FC<DelegatedStudentViewProps> = ({
  token,
  onExit,
}) => {
  const tokens = storage.getDelegationTokens();
  const tokenObj = tokens.find((t) => t.token === token && t.status === 'aktif');
  const classes = storage.getClasses();
  const targetClass = classes.find((c) => c.id === tokenObj?.class_id);

  const [selectedDate, setSelectedDate] = useState<string>(() =>
    new Date().toISOString().substring(0, 10)
  );

  const [studentStatuses, setStudentStatuses] = useState<
    Record<number, { status: AttendanceStatus; notes: string }>
  >({});
  const [submitted, setSubmitted] = useState<boolean>(false);

  const students = React.useMemo(() => {
    if (!targetClass) return [];
    return storage
      .getStudents()
      .filter((s) => s.class_id === targetClass.id && s.status === 'aktif')
      .sort((a, b) => a.nama.localeCompare(b.nama));
  }, [targetClass]);

  useEffect(() => {
    const initial: Record<number, { status: AttendanceStatus; notes: string }> = {};
    students.forEach((s) => {
      initial[s.id] = { status: 'H', notes: '' };
    });
    setStudentStatuses(initial);
  }, [students]);

  if (!tokenObj || !targetClass) {
    return (
      <div className="max-w-md mx-auto my-16 p-6 bg-white border border-rose-200 rounded-xl text-center space-y-4 shadow-sm">
        <ShieldAlert className="w-12 h-12 text-rose-500 mx-auto" />
        <h2 className="text-base font-bold text-slate-900">Token Akses Tidak Valid</h2>
        <p className="text-xs text-slate-500">
          Tautan delegasi ketua kelas ini telah kedaluwarsa atau tidak terdaftar di sistem sekolah.
        </p>
        <button
          onClick={onExit}
          className="px-4 py-2 text-xs font-semibold bg-slate-900 text-white rounded-lg"
        >
          Kembali ke Halaman Utama
        </button>
      </div>
    );
  }

  const handleStatusChange = (studentId: number, status: AttendanceStatus) => {
    setStudentStatuses((prev) => ({
      ...prev,
      [studentId]: {
        ...prev[studentId],
        status,
      },
    }));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const entries = students.map((s) => ({
      student_id: s.id,
      status: studentStatuses[s.id]?.status || 'H',
      notes: studentStatuses[s.id]?.notes,
    }));

    storage.submitAttendanceBatch({
      class_id: targetClass.id,
      subject_id: null, // Absen Harian
      tanggal: selectedDate,
      recorded_by: tokenObj.created_by, // Assigned through delegating teacher
      recorded_via: 'ketua_kelas_delegasi',
      entries,
    });

    setSubmitted(true);
  };

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      {/* Header for student delegate */}
      <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-xs">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-indigo-600 text-white flex items-center justify-center font-bold">
              KK
            </div>
            <div>
              <h1 className="text-base font-bold text-slate-900">
                Presensi Harian Kelas {targetClass.name}
              </h1>
              <p className="text-xs text-slate-500">
                Portal Delegasi Mandiri Ketua Kelas · {targetClass.jurusan}
              </p>
            </div>
          </div>

          <button
            onClick={onExit}
            className="text-xs text-slate-500 hover:text-slate-800 underline"
          >
            Keluar Mode Delegasi
          </button>
        </div>

        <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-600">
          <span>Tanggal Presensi: <strong className="font-mono text-slate-900">{selectedDate}</strong></span>
          <span>Total Siswa: <strong className="font-mono text-slate-900">{students.length} Orang</strong></span>
        </div>
      </div>

      {submitted ? (
        <div className="p-8 bg-white border border-emerald-200 rounded-xl text-center space-y-3 shadow-xs">
          <CheckCircle2 className="w-12 h-12 text-emerald-600 mx-auto" />
          <h2 className="text-base font-bold text-slate-900">Presensi Berhasil Dikirimkan!</h2>
          <p className="text-xs text-slate-600 max-w-md mx-auto">
            Terima kasih! Data absensi harian kelas {targetClass.name} untuk tanggal {selectedDate} telah tersimpan aman di database sekolah.
          </p>
          <div className="pt-2">
            <button
              onClick={() => setSubmitted(false)}
              className="px-4 py-2 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800"
            >
              Ubah atau Periksa Kembali
            </button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="bg-white border border-slate-200 rounded-xl shadow-xs overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-xs text-left">
              <thead className="bg-slate-50 text-slate-500 font-medium border-b border-slate-200">
                <tr>
                  <th className="py-2.5 px-3 w-10 text-center">No</th>
                  <th className="py-2.5 px-3">Nama Siswa</th>
                  <th className="py-2.5 px-3 w-56 text-center">Status</th>
                  <th className="py-2.5 px-3">Keterangan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {students.map((s, idx) => {
                  const curr = studentStatuses[s.id] || { status: 'H', notes: '' };
                  return (
                    <tr key={s.id} className="hover:bg-slate-50">
                      <td className="py-2.5 px-3 text-center text-slate-400 font-mono">
                        {idx + 1}
                      </td>
                      <td className="py-2.5 px-3 font-medium text-slate-900">
                        {s.nama}
                      </td>
                      <td className="py-2.5 px-3">
                        <div className="flex items-center justify-center gap-1">
                          {(['H', 'I', 'S', 'A'] as AttendanceStatus[]).map((st) => (
                            <button
                              key={st}
                              type="button"
                              onClick={() => handleStatusChange(s.id, st)}
                              className={`w-9 h-7 rounded text-xs font-bold font-mono transition-all ${
                                curr.status === st
                                  ? st === 'H'
                                    ? 'bg-emerald-600 text-white'
                                    : st === 'I'
                                    ? 'bg-sky-600 text-white'
                                    : st === 'S'
                                    ? 'bg-amber-600 text-white'
                                    : 'bg-rose-600 text-white'
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
                          placeholder="cth: izin sakit flu"
                          value={curr.notes}
                          onChange={(e) =>
                            setStudentStatuses((prev) => ({
                              ...prev,
                              [s.id]: { ...prev[s.id], notes: e.target.value },
                            }))
                          }
                          className="w-full px-2 py-1 text-xs border border-slate-200 rounded"
                        />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <div className="p-4 bg-slate-50 border-t border-slate-200 flex items-center justify-between">
            <span className="text-xs text-slate-500">
              Pastikan seluruh status teman sekelas terverifikasi sebelum mengirim.
            </span>
            <button
              type="submit"
              className="flex items-center gap-1.5 px-5 py-2 text-xs font-semibold bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg shadow-xs transition-colors"
            >
              <Send className="w-3.5 h-3.5" />
              Kirimkan Presensi Kelas
            </button>
          </div>
        </form>
      )}
    </div>
  );
};
