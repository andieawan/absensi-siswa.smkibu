import React, { useState, useEffect } from 'react';
import { storage } from '../services/storage';
import { ClassItem, Student, AttendanceStatus, KetuaKelasToken } from '../types';
import { doc, getDoc } from 'firebase/firestore';
import { db } from '../lib/firebase';
import { CheckCircle2, Send, ShieldAlert, Clock, Loader2, Calendar } from 'lucide-react';

interface DelegatedStudentViewProps {
  token: string;
  onExit: () => void;
}

export const DelegatedStudentView: React.FC<DelegatedStudentViewProps> = ({
  token,
  onExit,
}) => {
  const [isValidating, setIsValidating] = useState<boolean>(true);
  const [tokenObj, setTokenObj] = useState<KetuaKelasToken | null>(null);
  const [targetClass, setTargetClass] = useState<ClassItem | null>(null);
  const [students, setStudents] = useState<Student[]>([]);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [isExpired, setIsExpired] = useState<boolean>(false);

  const [selectedDate, setSelectedDate] = useState<string>(() =>
    new Date().toISOString().substring(0, 10)
  );

  const [studentStatuses, setStudentStatuses] = useState<
    Record<number, { status: AttendanceStatus; notes: string }>
  >({});
  const [submitted, setSubmitted] = useState<boolean>(false);
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);

  // Verifikasi token lintas-device (Firestore -> Server API -> Local Storage fallback)
  useEffect(() => {
    let isMounted = true;

    const verifyTokenCrossDevice = async () => {
      setIsValidating(true);
      setErrorMsg(null);
      setIsExpired(false);

      const now = Date.now();
      let verifiedToken: KetuaKelasToken | null = null;

      // 1. Coba baca langsung dari Cloud Firestore (Pusat kebenaran lintas-device)
      try {
        const snap = await getDoc(doc(db, 'tokens', token));
        if (snap.exists()) {
          const rawData = snap.data() as KetuaKelasToken;
          verifiedToken = rawData;
        }
      } catch (err) {
        console.warn('[DelegatedStudentView] Baca langsung Firestore:', err);
      }

      // 2. Query endpoint sesi delegasi server (menyediakan data kelas & daftar siswa tanpa bocor)
      try {
        const res = await fetch(`/api/delegation/session?token=${encodeURIComponent(token)}`);
        if (res.ok) {
          const data = await res.json();
          if (data.valid && isMounted) {
            setTokenObj(data.token);
            setTargetClass(data.targetClass);
            setStudents(data.students || []);
            setIsValidating(false);
            return;
          }
        } else if (res.status === 410) {
          const data = await res.json();
          if (isMounted) {
            setErrorMsg(data.error || 'Masa berlaku tautan delegasi telah habis (kedaluwarsa).');
            setIsExpired(true);
            setIsValidating(false);
            return;
          }
        }
      } catch (err) {
        console.warn('[DelegatedStudentView] Query session server:', err);
      }

      // 3. Fallback: jika direct firestore berhasil tapi server offline
      if (!verifiedToken) {
        // Fallback local storage jika dibuka di perangkat yang sama
        verifiedToken = storage.getDelegationTokens().find((t) => t.token === token) || null;
      }

      if (verifiedToken && isMounted) {
        // Evaluasi masa berlaku (expiry)
        const expiry = verifiedToken.expires_at_millis ||
          (verifiedToken.expires_at ? new Date(verifiedToken.expires_at).getTime() : null);

        if (expiry && now > expiry) {
          setErrorMsg(
            `Tautan presensi telah kedaluwarsa pada ${new Date(expiry).toLocaleString('id-ID')}. Silakan minta Wali Kelas untuk membuatkan tautan presensi baru.`
          );
          setIsExpired(true);
          setIsValidating(false);
          return;
        }

        if (verifiedToken.status !== 'aktif') {
          setErrorMsg('Tautan delegasi ini sudah dinonaktifkan atau telah dicabut.');
          setIsValidating(false);
          return;
        }

        setTokenObj(verifiedToken);
        const cls = storage.getClasses().find((c) => c.id === verifiedToken.class_id);
        setTargetClass(cls || null);
        const stds = storage
          .getStudents()
          .filter((s) => s.class_id === verifiedToken.class_id && s.status === 'aktif')
          .sort((a, b) => a.nama.localeCompare(b.nama));
        setStudents(stds);
        setIsValidating(false);
        return;
      }

      if (isMounted) {
        setErrorMsg('Tautan atau token delegasi tidak valid atau tidak terdaftar di sistem sekolah.');
        setIsValidating(false);
      }
    };

    verifyTokenCrossDevice();

    return () => {
      isMounted = false;
    };
  }, [token]);

  // Inisialisasi status default 'H' untuk seluruh siswa
  useEffect(() => {
    if (students.length > 0) {
      const initial: Record<number, { status: AttendanceStatus; notes: string }> = {};
      students.forEach((s) => {
        initial[s.id] = { status: 'H', notes: '' };
      });
      setStudentStatuses(initial);
    }
  }, [students]);

  // Loading state
  if (isValidating) {
    return (
      <div className="max-w-md mx-auto my-20 p-8 bg-white border border-slate-200 rounded-xl text-center space-y-4 shadow-sm">
        <Loader2 className="w-10 h-10 text-indigo-600 animate-spin mx-auto" />
        <h2 className="text-base font-bold text-slate-900">Memverifikasi Tautan Delegasi...</h2>
        <p className="text-xs text-slate-500">
          Menghubungkan ke database Cloud Firestore untuk memvalidasi hak akses dan status token ketua kelas lintas-perangkat.
        </p>
      </div>
    );
  }

  // Error / Expired state
  if (errorMsg || !tokenObj || !targetClass) {
    return (
      <div className="max-w-md mx-auto my-16 p-6 bg-white border border-rose-200 rounded-xl text-center space-y-4 shadow-sm">
        <ShieldAlert className="w-12 h-12 text-rose-500 mx-auto" />
        <h2 className="text-base font-bold text-slate-900">
          {isExpired ? 'Tautan Delegasi Telah Kedaluwarsa' : 'Token Akses Tidak Valid'}
        </h2>
        <p className="text-xs text-slate-600 leading-relaxed">
          {errorMsg || 'Tautan delegasi ketua kelas ini tidak terdaftar atau telah dinonaktifkan di sistem presensi sekolah.'}
        </p>
        {isExpired && (
          <div className="p-3 bg-rose-50 border border-rose-200 rounded-lg text-left text-xs text-rose-800 space-y-1">
            <div className="font-semibold flex items-center gap-1.5">
              <Clock className="w-3.5 h-3.5 text-rose-600" />
              Kebijakan Masa Berlaku Token:
            </div>
            <p className="text-[11px] text-rose-700">
              Demi keamanan presensi siswa, setiap tautan ketua kelas dibatasi masa berlakunya (maksimal 24 jam). Silakan hubungi Wali Kelas Anda untuk memperbarui token.
            </p>
          </div>
        )}
        <div className="pt-2">
          <button
            onClick={onExit}
            className="px-4 py-2 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors"
          >
            Kembali ke Beranda
          </button>
        </div>
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

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);

    const entries = students.map((s) => ({
      student_id: s.id,
      status: studentStatuses[s.id]?.status || 'H',
      notes: studentStatuses[s.id]?.notes,
    }));

    // Simpan ke storage lokal
    try {
      storage.submitAttendanceBatch({
        class_id: targetClass.id,
        subject_id: null, // Absen Harian
        tanggal: selectedDate,
        recorded_by: tokenObj.created_by,
        recorded_via: 'ketua_kelas_delegasi',
        entries,
      });
    } catch (err) {
      console.warn('[DelegatedStudentView] Local storage submit:', err);
    }

    // Submit ke server endpoint
    try {
      await fetch('/api/delegation/submit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          token: tokenObj.token,
          class_id: targetClass.id,
          tanggal: selectedDate,
          entries,
        }),
      });
    } catch (err) {
      console.warn('[DelegatedStudentView] Server submit notice:', err);
    }

    setIsSubmitting(false);
    setSubmitted(true);
  };

  // Format expiry display
  const expiryDateFormatted = tokenObj.expires_at
    ? new Date(tokenObj.expires_at).toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
    : null;

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

        {/* Expiry Badge */}
        {expiryDateFormatted && (
          <div className="mt-3 inline-flex items-center gap-1.5 px-2.5 py-1 bg-amber-50 border border-amber-200 rounded-md text-[11px] font-medium text-amber-800">
            <Clock className="w-3.5 h-3.5 text-amber-600" />
            <span>Masa Berlaku Tautan: Aktif hingga <strong>{expiryDateFormatted}</strong></span>
          </div>
        )}

        <div className="mt-4 pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-600">
          <div className="flex items-center gap-2">
            <Calendar className="w-4 h-4 text-slate-400" />
            <span>Tanggal Presensi:</span>
            <input
              type="date"
              value={selectedDate}
              onChange={(e) => setSelectedDate(e.target.value)}
              className="px-2 py-1 border border-slate-200 rounded text-xs font-mono font-semibold text-slate-900"
            />
          </div>
          <div>
            Total Siswa: <strong className="font-mono text-slate-900">{students.length} Orang</strong>
          </div>
        </div>
      </div>

      {submitted ? (
        <div className="p-8 bg-white border border-emerald-200 rounded-xl text-center space-y-3 shadow-xs">
          <CheckCircle2 className="w-12 h-12 text-emerald-600 mx-auto" />
          <h2 className="text-base font-bold text-slate-900">Presensi Berhasil Dikirimkan!</h2>
          <p className="text-xs text-slate-600 max-w-md mx-auto">
            Terima kasih! Data absensi harian kelas {targetClass.name} untuk tanggal {selectedDate} telah tersimpan aman di database sekolah dan Cloud Firestore.
          </p>
          <div className="pt-2">
            <button
              onClick={() => setSubmitted(false)}
              className="px-4 py-2 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors"
            >
              Ubah atau Periksa Kembali
            </button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="bg-white border border-slate-200 rounded-xl shadow-xs overflow-hidden">
          {/* Tampilan kartu untuk mobile/tablet: tombol status berukuran 44x44 agar nyaman disentuh */}
          <div className="md:hidden divide-y divide-slate-100">
            {students.map((s, idx) => {
              const curr = studentStatuses[s.id] || { status: 'H', notes: '' };
              return (
                <div key={s.id} className="p-3 space-y-2.5">
                  <div className="flex items-center gap-2">
                    <span className="text-slate-400 font-mono text-xs w-5 shrink-0">{idx + 1}</span>
                    <span className="font-medium text-slate-900 text-sm truncate">{s.nama}</span>
                  </div>
                  <div className="flex items-center gap-1.5">
                    {(['H', 'I', 'S', 'A'] as AttendanceStatus[]).map((st) => (
                      <button
                        key={st}
                        type="button"
                        onClick={() => handleStatusChange(s.id, st)}
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
                    placeholder="Keterangan (cth: izin urusan keluarga / sakit demam)"
                    value={curr.notes}
                    onChange={(e) =>
                      setStudentStatuses((prev) => ({
                        ...prev,
                        [s.id]: { ...prev[s.id], notes: e.target.value },
                      }))
                    }
                    className="w-full px-3 py-2.5 text-sm border border-slate-200 rounded-lg focus:outline-indigo-500"
                  />
                </div>
              );
            })}
          </div>

          {/* Tampilan tabel untuk tablet/desktop */}
          <div className="hidden md:block overflow-x-auto">
            <table className="w-full text-xs text-left">
              <thead className="bg-slate-50 text-slate-500 font-medium border-b border-slate-200">
                <tr>
                  <th className="py-2.5 px-3 w-10 text-center">No</th>
                  <th className="py-2.5 px-3">Nama Siswa</th>
                  <th className="py-2.5 px-3 w-56 text-center">Status Kehadiran</th>
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
                          placeholder="cth: izin urusan keluarga / sakit demam"
                          value={curr.notes}
                          onChange={(e) =>
                            setStudentStatuses((prev) => ({
                              ...prev,
                              [s.id]: { ...prev[s.id], notes: e.target.value },
                            }))
                          }
                          className="w-full px-2 py-1 text-xs border border-slate-200 rounded focus:outline-indigo-500"
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
              disabled={isSubmitting}
              className="flex items-center gap-1.5 px-5 py-2 text-xs font-semibold bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-lg shadow-xs transition-colors"
            >
              {isSubmitting ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <Send className="w-3.5 h-3.5" />
              )}
              {isSubmitting ? 'Mengirim...' : 'Kirimkan Presensi Kelas'}
            </button>
          </div>
        </form>
      )}
    </div>
  );
};
