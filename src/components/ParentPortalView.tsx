import React, { useEffect, useState } from 'react';
import { AttendanceStatus } from '../types';
import { ShieldAlert, Loader2, CalendarCheck2, AlertTriangle, Clock, CheckCircle2 } from 'lucide-react';

interface ParentPortalViewProps {
  token: string;
  onExit: () => void;
}

interface ParentSummary {
  valid: boolean;
  student?: { nama: string; nis: string; class_name: string };
  stats?: {
    total: number;
    hadir: number;
    izin: number;
    sakit: number;
    alpa: number;
    rate: number;
    threshold: number;
    meets85Percent: boolean;
  };
  attention?: {
    category: 'alpa_tinggi' | 'sakit_tinggi' | 'izin_tinggi' | 'jarang_masuk_gabungan' | null;
    alpa: number;
    izin: number;
    sakit: number;
    totalAbsen: number;
  };
  patternAlerts?: { day_of_week: string; count: number; status_type: 'Peringatan' | 'Pola' }[];
  recentAttendance?: { tanggal: string; status: AttendanceStatus; notes?: string }[];
  error?: string;
}

const ATTENTION_LABELS: Record<string, string> = {
  alpa_tinggi: 'Alpa (Tanpa Keterangan) Tinggi',
  sakit_tinggi: 'Izin Sakit Tinggi',
  izin_tinggi: 'Izin Tinggi',
  jarang_masuk_gabungan: 'Jarang Masuk (Gabungan I/S/A)',
};

const STATUS_LABELS: Record<AttendanceStatus, string> = {
  H: 'Hadir',
  I: 'Izin',
  S: 'Sakit',
  A: 'Alpa',
};

const STATUS_COLORS: Record<AttendanceStatus, string> = {
  H: 'bg-emerald-50 text-emerald-700',
  I: 'bg-sky-50 text-sky-700',
  S: 'bg-amber-50 text-amber-700',
  A: 'bg-rose-50 text-rose-700',
};

export const ParentPortalView: React.FC<ParentPortalViewProps> = ({ token, onExit }) => {
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [data, setData] = useState<ParentSummary | null>(null);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  useEffect(() => {
    let isMounted = true;
    const load = async () => {
      setIsLoading(true);
      setErrorMsg(null);
      try {
        const res = await fetch(`/api/parent-access/summary?token=${encodeURIComponent(token)}`);
        const body: ParentSummary = await res.json().catch(() => ({ valid: false }));
        if (!isMounted) return;
        if (!res.ok || !body.valid) {
          setErrorMsg(body.error || 'Tautan akses tidak valid atau sudah dicabut.');
          setData(null);
        } else {
          setData(body);
        }
      } catch (err) {
        if (!isMounted) return;
        setErrorMsg('Tidak dapat menghubungi server sekolah. Periksa koneksi internet Anda dan coba lagi.');
      } finally {
        if (isMounted) setIsLoading(false);
      }
    };
    load();
    return () => {
      isMounted = false;
    };
  }, [token]);

  if (isLoading) {
    return (
      <div className="max-w-md mx-auto my-20 p-8 bg-white border border-slate-200 rounded-xl text-center space-y-4 shadow-sm">
        <Loader2 className="w-10 h-10 text-indigo-600 animate-spin mx-auto" />
        <h2 className="text-base font-bold text-slate-900">Memuat Rekap Kehadiran...</h2>
      </div>
    );
  }

  if (errorMsg || !data?.student || !data.stats) {
    return (
      <div className="max-w-md mx-auto my-16 p-6 bg-white border border-rose-200 rounded-xl text-center space-y-4 shadow-sm">
        <ShieldAlert className="w-12 h-12 text-rose-500 mx-auto" />
        <h2 className="text-base font-bold text-slate-900">Tautan Tidak Valid</h2>
        <p className="text-xs text-slate-600 leading-relaxed">
          {errorMsg || 'Tautan akses orang tua/wali murid ini tidak terdaftar di sistem sekolah.'}
        </p>
        <p className="text-[11px] text-slate-500">
          Kalau ini seharusnya masih berlaku, silakan hubungi wali kelas untuk meminta tautan akses baru.
        </p>
      </div>
    );
  }

  const { student, stats, attention, patternAlerts = [], recentAttendance = [] } = data;

  return (
    <div className="max-w-xl mx-auto my-6 sm:my-10 px-4 space-y-4">
      <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4">
        <div className="flex items-center gap-2 text-[11px] font-semibold text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-full px-3 py-1 w-fit">
          <CalendarCheck2 className="w-3.5 h-3.5" />
          Portal Orang Tua/Wali Murid · Baca-saja
        </div>

        <div>
          <h1 className="text-lg font-bold text-slate-900">{student.nama}</h1>
          <p className="text-xs text-slate-500 mt-0.5">
            NIS {student.nis} · Kelas {student.class_name}
          </p>
        </div>

        {/* Attendance Rate */}
        <div className="flex flex-col sm:flex-row sm:items-center gap-4 pt-2 border-t border-slate-100">
          <div>
            <div className="text-xs text-slate-500">Tingkat Kehadiran</div>
            <div
              className={`text-3xl font-bold font-mono tabular-nums ${
                stats.rate >= 90 ? 'text-emerald-700' : stats.rate >= 80 ? 'text-amber-700' : 'text-rose-700'
              }`}
            >
              {stats.rate.toFixed(1)}%
            </div>
            <div className="text-[10px] text-slate-400 font-mono">
              {stats.hadir} Hadir dari {stats.total} Sesi Tercatat
            </div>
          </div>
          <div className="flex items-center gap-1.5 text-xs font-mono">
            <span className="text-emerald-700 bg-emerald-50 px-2 py-1 rounded">H: {stats.hadir}</span>
            <span className="text-sky-700 bg-sky-50 px-2 py-1 rounded">I: {stats.izin}</span>
            <span className="text-amber-700 bg-amber-50 px-2 py-1 rounded">S: {stats.sakit}</span>
            <span className="text-rose-700 bg-rose-50 px-2 py-1 rounded">A: {stats.alpa}</span>
          </div>
        </div>

        {!stats.meets85Percent && (
          <div className="p-2.5 bg-rose-50 border border-rose-200 rounded-lg text-xs text-rose-800">
            Kehadiran anak Bapak/Ibu saat ini berada di bawah standar minimum sekolah (85%). Mohon dikonfirmasi ke
            wali kelas apabila ada kendala yang menyebabkan ketidakhadiran.
          </div>
        )}

        {/* Attention category */}
        {attention?.category && (
          <div className="p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-start gap-2 text-xs text-amber-900">
            <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
            <div>
              <strong>Status Perlu Perhatian: {ATTENTION_LABELS[attention.category]}</strong>
              <p className="text-[11px] text-amber-800 mt-0.5">
                Alpa: {attention.alpa} · Izin: {attention.izin} · Sakit: {attention.sakit} · Total Tidak Hadir:{' '}
                {attention.totalAbsen}. Guru BK/Wali Kelas mungkin akan menghubungi Bapak/Ibu untuk berdiskusi lebih
                lanjut.
              </p>
            </div>
          </div>
        )}

        {/* Periodic pattern alerts */}
        {patternAlerts.length > 0 && (
          <div className="p-3 bg-sky-50 border border-sky-200 rounded-lg flex items-start gap-2 text-xs text-sky-900">
            <Clock className="w-4 h-4 text-sky-600 shrink-0 mt-0.5" />
            <div>
              <strong>Pola Absen Berkala Terdeteksi</strong>
              <ul className="mt-1 space-y-0.5 text-[11px] text-sky-800">
                {patternAlerts.map((p, idx) => (
                  <li key={idx}>
                    Sering tidak hadir pada hari <strong>{p.day_of_week}</strong> ({p.count}x, interval ~14 hari) —{' '}
                    {p.status_type === 'Pola' ? 'sudah menjadi pola' : 'masih tahap peringatan'}.
                  </li>
                ))}
              </ul>
            </div>
          </div>
        )}

        {stats.meets85Percent && !attention?.category && patternAlerts.length === 0 && (
          <div className="p-2.5 bg-emerald-50 border border-emerald-200 rounded-lg flex items-center gap-2 text-xs text-emerald-800">
            <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
            Tidak ada catatan khusus. Kehadiran anak Bapak/Ibu dalam kondisi baik.
          </div>
        )}
      </div>

      {/* Recent attendance log */}
      <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <h2 className="text-sm font-bold text-slate-900 mb-3">Riwayat Kehadiran Terbaru</h2>
        {recentAttendance.length === 0 ? (
          <p className="text-xs text-slate-400">Belum ada data kehadiran tercatat.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs text-left border-collapse">
              <thead>
                <tr className="border-b border-slate-200 text-slate-500 font-medium">
                  <th className="py-2 px-2">Tanggal</th>
                  <th className="py-2 px-2">Status</th>
                  <th className="py-2 px-2">Catatan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {recentAttendance.map((r, idx) => (
                  <tr key={idx}>
                    <td className="py-2 px-2 font-mono text-slate-700">{r.tanggal}</td>
                    <td className="py-2 px-2">
                      <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${STATUS_COLORS[r.status]}`}>
                        {STATUS_LABELS[r.status]}
                      </span>
                    </td>
                    <td className="py-2 px-2 text-slate-500">{r.notes || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="text-center">
        <button onClick={onExit} className="text-xs text-slate-500 underline hover:text-slate-800">
          Tutup halaman ini
        </button>
      </div>
    </div>
  );
};
