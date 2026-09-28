import React, { useEffect, useState } from 'react';
import { Student, ParentAccessToken } from '../types';
import { storage } from '../services/storage';
import { Users, Copy, AlertCircle, Loader2, Ban, Plus, CheckCircle2 } from 'lucide-react';

interface ParentAccessModalProps {
  isOpen: boolean;
  student: Student | null;
  onClose: () => void;
}

export const ParentAccessModal: React.FC<ParentAccessModalProps> = ({ isOpen, student, onClose }) => {
  const [tokens, setTokens] = useState<ParentAccessToken[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isCreating, setIsCreating] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [copiedToken, setCopiedToken] = useState<string | null>(null);
  const [revokingToken, setRevokingToken] = useState<string | null>(null);

  const refreshTokens = async () => {
    if (!student) return;
    setIsLoading(true);
    setError(null);
    try {
      const list = await storage.listParentAccessTokens(student.id);
      setTokens(list);
    } catch (err: any) {
      setError(err?.message || 'Gagal memuat daftar akses wali murid.');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    if (isOpen && student) {
      refreshTokens();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, student?.id]);

  if (!isOpen || !student) return null;

  const activeTokens = tokens.filter((t) => t.status === 'aktif');

  const handleCreate = async () => {
    setIsCreating(true);
    setError(null);
    try {
      await storage.createParentAccessToken(student.id);
      await refreshTokens();
    } catch (err: any) {
      setError(err?.message || 'Gagal membuat tautan akses.');
    } finally {
      setIsCreating(false);
    }
  };

  const handleRevoke = async (token: string) => {
    setRevokingToken(token);
    setError(null);
    try {
      await storage.revokeParentAccessToken(token);
      await refreshTokens();
    } catch (err: any) {
      setError(err?.message || 'Gagal mencabut akses.');
    } finally {
      setRevokingToken(null);
    }
  };

  const linkFor = (token: string) => `${window.location.origin}/?wali=${token}`;

  const handleCopy = (token: string) => {
    navigator.clipboard.writeText(linkFor(token));
    setCopiedToken(token);
    setTimeout(() => setCopiedToken(null), 3000);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="bg-white rounded-lg shadow-xl max-w-lg w-full p-5 space-y-4 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between border-b border-slate-200 pb-3">
          <div className="flex items-center gap-2">
            <Users className="w-4 h-4 text-indigo-700" />
            <h2 className="text-sm font-bold text-slate-900">Akses Portal Orang Tua/Wali Murid</h2>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Tutup"
            className="text-slate-400 hover:text-slate-700 min-h-9 min-w-9 flex items-center justify-center text-lg font-bold"
          >
            &times;
          </button>
        </div>

        <p className="text-xs text-slate-600 leading-relaxed">
          Tautan ini memberi orang tua/wali <strong>{student.nama}</strong> ({student.nis}) akses baca-saja untuk melihat
          rekap kehadiran dan peringatan pola absen anaknya — <strong>tanpa perlu akun</strong> dan tidak bisa mengubah
          data apa pun. Tidak ada masa kedaluwarsa otomatis; cabut manual kapan saja lewat tombol di bawah kalau tautan
          sudah tidak perlu berlaku (misal salah kirim, atau siswa pindah sekolah).
        </p>

        {error && (
          <div className="p-2.5 bg-rose-50 border border-rose-200 rounded-lg text-xs text-rose-800 flex items-start gap-2">
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
            <span>{error}</span>
          </div>
        )}

        <button
          type="button"
          onClick={handleCreate}
          disabled={isCreating}
          className="w-full flex items-center justify-center gap-1.5 py-2 px-3 text-xs font-semibold bg-indigo-700 hover:bg-indigo-800 disabled:opacity-60 text-white rounded-lg transition-colors"
        >
          {isCreating ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Plus className="w-3.5 h-3.5" />}
          {isCreating ? 'Membuat Tautan...' : 'Buat Tautan Akses Baru'}
        </button>

        <div className="space-y-2">
          <div className="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">
            Tautan Aktif {activeTokens.length > 0 ? `(${activeTokens.length})` : ''}
          </div>

          {isLoading ? (
            <div className="flex items-center gap-1.5 text-xs text-slate-500 py-3">
              <Loader2 className="w-3.5 h-3.5 animate-spin" />
              Memuat...
            </div>
          ) : activeTokens.length === 0 ? (
            <p className="text-xs text-slate-400 py-2">Belum ada tautan akses aktif untuk siswa ini.</p>
          ) : (
            <div className="space-y-2">
              {activeTokens.map((t) => (
                <div key={t.token} className="p-3 bg-slate-50 border border-slate-200 rounded-lg space-y-2">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-mono text-[11px] text-slate-700 break-all">{t.token}</span>
                    <span className="text-[10px] text-slate-400 shrink-0">
                      Dibuat {new Date(t.created_at).toLocaleDateString('id-ID')}
                    </span>
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      type="button"
                      onClick={() => handleCopy(t.token)}
                      className="flex-1 flex items-center justify-center gap-1.5 py-1.5 px-2 text-[11px] font-semibold bg-slate-900 text-white rounded-md hover:bg-slate-800 transition-colors"
                    >
                      {copiedToken === t.token ? (
                        <>
                          <CheckCircle2 className="w-3 h-3" /> Tersalin!
                        </>
                      ) : (
                        <>
                          <Copy className="w-3 h-3" /> Salin Tautan
                        </>
                      )}
                    </button>
                    <button
                      type="button"
                      onClick={() => handleRevoke(t.token)}
                      disabled={revokingToken === t.token}
                      className="flex items-center justify-center gap-1.5 py-1.5 px-2 text-[11px] font-semibold text-rose-700 bg-rose-50 border border-rose-200 rounded-md hover:bg-rose-100 disabled:opacity-60 transition-colors"
                    >
                      {revokingToken === t.token ? (
                        <Loader2 className="w-3 h-3 animate-spin" />
                      ) : (
                        <Ban className="w-3 h-3" />
                      )}
                      Cabut
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
