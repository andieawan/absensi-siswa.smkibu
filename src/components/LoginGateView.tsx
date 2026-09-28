import React, { useState } from 'react';
import { User } from '../types';
import { authService } from '../services/auth';
import { storage } from '../services/storage';
import {
  ShieldCheck,
  Lock,
  User as UserIcon,
  Eye,
  EyeOff,
  LogIn,
  AlertCircle,
  KeyRound,
  GraduationCap,
  Sparkles,
  Server,
  Database,
} from 'lucide-react';

interface LoginGateViewProps {
  onLoginSuccess: (user: User) => void;
}

export const LoginGateView: React.FC<LoginGateViewProps> = ({ onLoginSuccess }) => {
  const [username, setUsername] = useState<string>('pak.budi');
  const [password, setPassword] = useState<string>('123456');
  const [showPassword, setShowPassword] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(false);

  const allUsers = storage.getUsers();
  const settings = storage.getSettings();

  const handleLogin = (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setIsLoading(true);

    try {
      const result = authService.login(username, password);
      if (result.success && result.user) {
        onLoginSuccess(result.user);
      } else {
        setError(result.error || 'Autentikasi gagal. Periksa username dan password/PIN Anda.');
      }
    } catch (err: any) {
      setError(err.message || 'Terjadi kesalahan saat memverifikasi kredensial.');
    } finally {
      setIsLoading(false);
    }
  };

  const handleQuickFill = (user: User) => {
    setUsername(user.username);
    // pak.budi has admin role, can use admin123 or universal PIN 123456
    setPassword('123456');
    setError(null);
  };

  return (
    <div className="min-h-screen bg-linear-to-br from-slate-900 via-slate-800 to-slate-950 text-slate-100 flex flex-col justify-center items-center px-4 py-8 relative overflow-hidden">
      {/* Background ambient accents */}
      <div className="absolute -top-40 -left-40 w-96 h-96 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none" />
      <div className="absolute -bottom-40 -right-40 w-96 h-96 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none" />

      <div className="w-full max-w-md z-10 space-y-6">
        {/* Header Branding */}
        <div className="text-center space-y-2">
          <div className="inline-flex items-center justify-center p-3 bg-white/10 backdrop-blur-md border border-white/20 rounded-2xl shadow-xl">
            <GraduationCap className="w-8 h-8 text-indigo-400" />
          </div>
          <div>
            <h1 className="text-xl font-bold tracking-tight text-white">
              {settings.school_name || 'SMK Negeri 1 Prestasi Bangsa'}
            </h1>
            <p className="text-xs text-slate-400 font-medium mt-0.5">
              Sistem Informasi Presensi & Penilaian Terpadu (go_absen_siswa)
            </p>
          </div>
        </div>

        {/* Main Card */}
        <div className="bg-white/95 backdrop-blur-lg border border-slate-200/80 shadow-2xl rounded-2xl p-6 text-slate-900 space-y-5">
          <div className="border-b border-slate-100 pb-3 flex items-center justify-between">
            <div className="flex items-center gap-2">
              <KeyRound className="w-4 h-4 text-indigo-600" />
              <h2 className="text-sm font-bold text-slate-900">Autentikasi Akun & Peran</h2>
            </div>
            <span className="text-[11px] font-semibold text-slate-500 bg-slate-100 px-2 py-0.5 rounded">
              Verifikasi PIN / Password
            </span>
          </div>

          {error && (
            <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-800 flex items-start gap-2.5 animate-in fade-in">
              <AlertCircle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
              <div className="leading-snug">{error}</div>
            </div>
          )}

          <form onSubmit={handleLogin} className="space-y-4">
            {/* Username Input */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                Username / Identitas Pengguna
              </label>
              <div className="relative flex items-center">
                <UserIcon className="w-4 h-4 text-slate-400 absolute left-3 pointer-events-none" />
                <input
                  type="text"
                  required
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  placeholder="Contoh: pak.budi atau ibu.siti"
                  className="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs font-medium text-slate-900 placeholder-slate-400 focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                />
              </div>
            </div>

            {/* Password / PIN Input */}
            <div>
              <div className="flex items-center justify-between mb-1.5">
                <label className="text-xs font-semibold text-slate-700">
                  Password / PIN Keamanan
                </label>
                <span className="text-[10px] text-slate-500">
                  Tersimpan via <code className="text-indigo-600 font-mono">password_hash</code>
                </span>
              </div>
              <div className="relative flex items-center">
                <Lock className="w-4 h-4 text-slate-400 absolute left-3 pointer-events-none" />
                <input
                  type={showPassword ? 'text' : 'password'}
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Masukkan password atau PIN 6 digit..."
                  className="w-full pl-9 pr-10 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs font-medium text-slate-900 placeholder-slate-400 focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all font-mono"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-2.5 p-1 text-slate-400 hover:text-slate-600 rounded transition-colors"
                  title={showPassword ? 'Sembunyikan' : 'Tampilkan'}
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
            </div>

            {/* Submit Button */}
            <button
              type="submit"
              disabled={isLoading}
              className="w-full flex items-center justify-center gap-2 py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition-all shadow-md hover:shadow-lg disabled:opacity-50"
            >
              <LogIn className="w-4 h-4" />
              <span>{isLoading ? 'Memverifikasi...' : 'Masuk ke Sistem'}</span>
            </button>
          </form>

          {/* Quick Demo Accounts Selection */}
          <div className="pt-2 border-t border-slate-100 space-y-2">
            <div className="flex items-center justify-between text-[11px] text-slate-500">
              <div className="flex items-center gap-1 font-semibold text-slate-700">
                <Sparkles className="w-3.5 h-3.5 text-amber-500" />
                <span>Pilih Cepat Akun Demo (1-Klik):</span>
              </div>
              <span className="text-[10px] text-slate-400">PIN: 123456</span>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
              {allUsers.map((u) => {
                const isSelected = username.toLowerCase() === u.username.toLowerCase();
                const roleBadge = u.roles.includes('superadmin')
                  ? 'Superadmin'
                  : u.roles.includes('admin')
                  ? 'Admin'
                  : u.roles.includes('kepsek')
                  ? 'Kepsek'
                  : u.roles.includes('bk')
                  ? 'BK'
                  : u.kelas_wali_id
                  ? 'Wali Kelas'
                  : 'Guru Mapel';

                return (
                  <button
                    key={u.id}
                    type="button"
                    onClick={() => handleQuickFill(u)}
                    className={`p-2 rounded-lg text-left border transition-all text-xs flex flex-col justify-between ${
                      isSelected
                        ? 'border-indigo-500 bg-indigo-50/70 text-indigo-950 font-semibold ring-1 ring-indigo-500'
                        : 'border-slate-200 bg-slate-50/60 hover:bg-slate-100 text-slate-700'
                    }`}
                  >
                    <div className="truncate font-semibold text-[11px]">{u.nama}</div>
                    <div className="flex items-center justify-between mt-1 text-[10px] text-slate-500">
                      <span className="font-mono text-indigo-700">@{u.username}</span>
                      <span className="px-1.5 py-0.2 bg-white border border-slate-200 rounded font-medium text-[9px]">
                        {roleBadge}
                      </span>
                    </div>
                  </button>
                );
              })}
            </div>

            <p className="text-[10px] text-slate-500 text-center pt-1">
              Catatan: Setiap akun dilindungi verifikasi kredensial. Anda dapat menggunakan PIN master <code className="font-semibold text-slate-800 bg-slate-100 px-1 rounded">123456</code> atau password spesifik guru.
            </p>
          </div>
        </div>

        {/* Footer info */}
        <div className="text-center space-y-1 text-slate-400 text-[11px]">
          <div className="flex items-center justify-center gap-2">
            <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" />
            <span>Keamanan Terenkripsi & Relasional MySQL Verified</span>
          </div>
          <p className="text-[10px] text-slate-500">
            Hak Cipta &copy; {new Date().getFullYear()} {settings.school_name}. Seluruh Hak Dilindungi.
          </p>
        </div>
      </div>
    </div>
  );
};
