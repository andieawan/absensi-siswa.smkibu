import React, { useState } from 'react';
import { User } from '../types';
import {
  Lock,
  Eye,
  EyeOff,
  AlertCircle,
  KeyRound,
  ShieldCheck,
  UserCheck,
} from 'lucide-react';

interface SwitchUserModalProps {
  isOpen: boolean;
  targetUser: User | null;
  onClose: () => void;
  onConfirmSwitch: (password: string) => Promise<{ success: boolean; error?: string }>;
}

export const SwitchUserModal: React.FC<SwitchUserModalProps> = ({
  isOpen,
  targetUser,
  onClose,
  onConfirmSwitch,
}) => {
  const [password, setPassword] = useState<string>('');
  const [showPassword, setShowPassword] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [isVerifying, setIsVerifying] = useState<boolean>(false);

  if (!isOpen || !targetUser) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setIsVerifying(true);

    try {
      const result = await onConfirmSwitch(password);
      if (!result.success) {
        setError(result.error || 'Password atau PIN tidak valid.');
      }
      // Sukses: parent menutup modal ini sendiri.
    } catch (err: any) {
      setError(err.message || 'Gagal memverifikasi password.');
    } finally {
      setIsVerifying(false);
    }
  };

  const roleText = targetUser.roles.join(', ');
  const waliLabel = targetUser.kelas_wali_id ? ' · Wali Kelas XI DKV 1' : '';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs animate-in fade-in">
      <div className="bg-white rounded-xl shadow-2xl max-w-sm w-full p-5 space-y-4 border border-slate-200 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
          <div className="flex items-center gap-2 text-slate-900 font-bold text-sm">
            <KeyRound className="w-4 h-4 text-indigo-600" />
            <span>Verifikasi Kredensial Ganti Akun</span>
          </div>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-slate-700 min-h-9 min-w-9 flex items-center justify-center text-lg font-bold leading-none"
            aria-label="Tutup"
          >
            &times;
          </button>
        </div>

        {/* Target user badge */}
        <div className="p-3 bg-slate-50 border border-slate-200 rounded-lg space-y-1">
          <div className="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
            Target Akun Tujuan:
          </div>
          <div className="flex items-center gap-2">
            <div className="w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-xs shrink-0">
              {targetUser.nama.charAt(0)}
            </div>
            <div className="truncate">
              <div className="text-xs font-bold text-slate-900 truncate">{targetUser.nama}</div>
              <div className="text-[10px] text-slate-500 truncate">
                @{targetUser.username} ({roleText}{waliLabel})
              </div>
            </div>
          </div>
        </div>

        {error && (
          <div className="p-2.5 bg-rose-50 border border-rose-200 rounded-lg text-xs text-rose-800 flex items-start gap-2">
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
            <span>{error}</span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-3">
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1">
              Masukkan Password / PIN:
            </label>
            <div className="relative flex items-center">
              <Lock className="w-3.5 h-3.5 text-slate-400 absolute left-3 pointer-events-none" />
              <input
                type={showPassword ? 'text' : 'password'}
                required
                autoFocus
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="Password atau PIN..."
                className="w-full pl-9 pr-9 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs font-medium text-slate-900 focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-indigo-500 font-mono"
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-2.5 p-1 text-slate-400 hover:text-slate-600 rounded"
              >
                {showPassword ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
              </button>
            </div>
            <p className="text-[10px] text-slate-500 mt-1">
              Masukkan password/PIN milik akun tujuan untuk beralih.
            </p>
          </div>

          <div className="pt-2 flex items-center justify-end gap-2 border-t border-slate-100">
            <button
              type="button"
              onClick={onClose}
              className="px-3 min-h-11 sm:min-h-0 sm:py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg font-medium"
            >
              Batal
            </button>
            <button
              type="submit"
              disabled={isVerifying}
              className="flex items-center gap-1.5 px-4 min-h-11 sm:min-h-0 sm:py-1.5 text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition-colors shadow-xs disabled:opacity-50"
            >
              <UserCheck className="w-3.5 h-3.5" />
              <span>{isVerifying ? 'Memverifikasi...' : 'Verifikasi & Beralih'}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
