import React from 'react';
import { User, Role } from '../types';
import { GoogleAuthButton } from './GoogleAuthButton';
import {
  CalendarCheck2,
  LayoutDashboard,
  GraduationCap,
  UserCheck,
  ShieldCheck,
  HeartHandshake,
  UserCog,
  RefreshCw,
  LogOut,
} from 'lucide-react';

interface NavbarProps {
  currentUser: User;
  onSelectUser?: (userId: number) => void;
  onRequestSwitchUser: (userId: number) => void;
  onLogout: () => void;
  allUsers: User[];
  activeTab: string;
  onTabChange: (tab: string) => void;
  onResetDemo: () => void;
  isDelegatedMode?: boolean;
  onExitDelegation?: () => void;
}

export const Navbar: React.FC<NavbarProps> = ({
  currentUser,
  onRequestSwitchUser,
  onLogout,
  allUsers,
  activeTab,
  onTabChange,
  onResetDemo,
  isDelegatedMode,
  onExitDelegation,
}) => {
  const hasRole = (role: Role) => currentUser.roles.includes(role);
  const isSuperadminOrAdmin = hasRole('admin') || hasRole('superadmin');
  const isWali = currentUser.kelas_wali_id !== null;

  return (
    <header className="sticky top-0 z-30 bg-white border-b border-slate-200 shadow-xs">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          {/* Zone 1: Single text element wordmark */}
          <div className="flex items-center gap-2 sm:gap-3 min-w-0">
            <div className="w-9 h-9 rounded-lg bg-slate-900 text-white flex items-center justify-center font-bold text-base shadow-xs shrink-0">
              GA
            </div>
            <div className="min-w-0">
              <div className="flex items-center gap-2">
                <span className="text-sm sm:text-base font-bold tracking-tight text-slate-900 truncate">
                  go_absen_siswa
                </span>
                <span className="text-xs text-slate-500 hidden lg:inline">
                  · Node.js & MySQL
                </span>
              </div>
              <p className="text-[11px] text-slate-500 leading-none hidden sm:block truncate">
                Sistem Absensi & Penilaian Sekolah
              </p>
            </div>
          </div>

          {/* Zone 2: Navigation Links (hidden if in delegated student mode) */}
          {!isDelegatedMode ? (
            <nav className="hidden md:flex items-center gap-1">
              <button
                onClick={() => onTabChange('dashboard')}
                className={`flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-md transition-colors whitespace-nowrap ${
                  activeTab === 'dashboard'
                    ? 'bg-slate-100 text-slate-900'
                    : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'
                }`}
              >
                <LayoutDashboard className="w-3.5 h-3.5 text-slate-500" />
                Dashboard
              </button>

              <button
                onClick={() => onTabChange('attendance')}
                className={`flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-md transition-colors whitespace-nowrap ${
                  activeTab === 'attendance'
                    ? 'bg-slate-100 text-slate-900'
                    : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'
                }`}
              >
                <CalendarCheck2 className="w-3.5 h-3.5 text-slate-500" />
                Absensi
                {isWali && (
                  <span className="text-[10px] text-indigo-600 font-semibold ml-0.5">
                    (Wali)
                  </span>
                )}
              </button>

              <button
                onClick={() => onTabChange('grades')}
                className={`flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-md transition-colors whitespace-nowrap ${
                  activeTab === 'grades'
                    ? 'bg-slate-100 text-slate-900'
                    : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'
                }`}
              >
                <GraduationCap className="w-3.5 h-3.5 text-slate-500" />
                Nilai
              </button>

              <button
                onClick={() => onTabChange('students')}
                className={`flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-md transition-colors whitespace-nowrap ${
                  activeTab === 'students'
                    ? 'bg-slate-100 text-slate-900'
                    : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'
                }`}
              >
                <UserCheck className="w-3.5 h-3.5 text-slate-500" />
                Riwayat Siswa
              </button>

              {(hasRole('bk') || isSuperadminOrAdmin) && (
                <button
                  onClick={() => onTabChange('bk')}
                  className={`flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-md transition-colors whitespace-nowrap ${
                    activeTab === 'bk'
                      ? 'bg-slate-100 text-slate-900'
                      : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'
                  }`}
                >
                  <HeartHandshake className="w-3.5 h-3.5 text-slate-500" />
                  Integrasi BK
                </button>
              )}

              {isSuperadminOrAdmin && (
                <button
                  onClick={() => onTabChange('admin')}
                  className={`flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-md transition-colors whitespace-nowrap ${
                    activeTab === 'admin'
                      ? 'bg-slate-100 text-slate-900'
                      : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'
                  }`}
                >
                  <ShieldCheck className="w-3.5 h-3.5 text-slate-500" />
                  Admin Panel
                </button>
              )}
            </nav>
          ) : (
            <div className="flex items-center gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 px-3 py-1 rounded-md">
              <span>Mode Delegasi: Ketua Kelas (Entri Absen Harian Terbatas)</span>
              <button
                onClick={onExitDelegation}
                className="underline font-semibold hover:text-amber-900 ml-2"
              >
                Kembali ke Akun Guru
              </button>
            </div>
          )}

          {/* Zone 3: Active User Switcher & Actions (desktop/tablet — baris terpisah di mobile) */}
          {!isDelegatedMode && (
            <div className="hidden md:flex items-center gap-2">
              <GoogleAuthButton />

              <div className="relative flex items-center">
                <UserCog className="w-4 h-4 text-slate-400 absolute left-2 pointer-events-none" />
                <select
                  value={currentUser.id}
                  onChange={(e) => {
                    const targetId = Number(e.target.value);
                    if (targetId !== currentUser.id) {
                      onRequestSwitchUser(targetId);
                    }
                  }}
                  aria-label="Pilih Akun / Peran Pengguna"
                  className="pl-8 pr-3 py-1.5 bg-slate-50 border border-slate-200 rounded-md text-xs font-medium text-slate-800 hover:bg-slate-100 transition-colors focus:ring-1 focus:ring-slate-900 focus:outline-hidden max-w-[220px]"
                >
                  {allUsers.map((u) => {
                    const roleList = u.roles.join(', ');
                    const waliLabel = u.kelas_wali_id ? ' (Wali XI DKV 1)' : '';
                    return (
                      <option key={u.id} value={u.id}>
                        {u.nama} · {roleList}
                        {waliLabel}
                      </option>
                    );
                  })}
                </select>
              </div>

              <button
                onClick={onResetDemo}
                title="Reset Data Demo ke Kondisi Awal"
                className="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-md transition-colors"
              >
                <RefreshCw className="w-3.5 h-3.5" />
              </button>

              <button
                onClick={onLogout}
                title="Keluar / Kunci Sesi"
                className="flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 rounded-md transition-colors"
              >
                <LogOut className="w-3.5 h-3.5" />
                <span className="hidden lg:inline">Keluar</span>
              </button>
            </div>
          )}

          {/* Zone 3 versi mobile: hanya tombol ganti akun + keluar, target sentuh 44px */}
          {!isDelegatedMode && (
            <div className="flex md:hidden items-center gap-1.5">
              <div className="relative flex items-center">
                <select
                  value={currentUser.id}
                  onChange={(e) => {
                    const targetId = Number(e.target.value);
                    if (targetId !== currentUser.id) {
                      onRequestSwitchUser(targetId);
                    }
                  }}
                  aria-label="Pilih Akun / Peran Pengguna"
                  className="min-h-11 pl-2.5 pr-1.5 bg-slate-50 border border-slate-200 rounded-md text-xs font-medium text-slate-800 focus:ring-1 focus:ring-slate-900 focus:outline-hidden max-w-[110px]"
                >
                  {allUsers.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.nama}
                    </option>
                  ))}
                </select>
              </div>
              <button
                onClick={onLogout}
                title="Keluar / Kunci Sesi"
                aria-label="Keluar"
                className="min-h-11 min-w-11 flex items-center justify-center text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 rounded-md transition-colors"
              >
                <LogOut className="w-4 h-4" />
              </button>
            </div>
          )}
        </div>
      </div>

      {/* Baris aksi mobile: Google Workspace + reset demo (di bawah header agar tidak padat) */}
      {!isDelegatedMode && (
        <div className="md:hidden flex items-center justify-between gap-2 border-t border-slate-100 px-3 py-2 bg-white">
          <GoogleAuthButton />
          <button
            onClick={onResetDemo}
            title="Reset Data Demo ke Kondisi Awal"
            aria-label="Reset Data Demo"
            className="min-h-11 min-w-11 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-md transition-colors shrink-0"
          >
            <RefreshCw className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Mobile nav bar */}
      {!isDelegatedMode && (
        <div className="md:hidden flex overflow-x-auto border-t border-slate-100 px-2 py-1.5 gap-1 bg-white">
          <button
            onClick={() => onTabChange('dashboard')}
            className={`px-2.5 py-1 text-xs rounded-md whitespace-nowrap ${
              activeTab === 'dashboard' ? 'bg-slate-100 text-slate-900 font-semibold' : 'text-slate-600'
            }`}
          >
            Dashboard
          </button>
          <button
            onClick={() => onTabChange('attendance')}
            className={`px-2.5 py-1 text-xs rounded-md whitespace-nowrap ${
              activeTab === 'attendance' ? 'bg-slate-100 text-slate-900 font-semibold' : 'text-slate-600'
            }`}
          >
            Absensi
          </button>
          <button
            onClick={() => onTabChange('grades')}
            className={`px-2.5 py-1 text-xs rounded-md whitespace-nowrap ${
              activeTab === 'grades' ? 'bg-slate-100 text-slate-900 font-semibold' : 'text-slate-600'
            }`}
          >
            Nilai
          </button>
          <button
            onClick={() => onTabChange('students')}
            className={`px-2.5 py-1 text-xs rounded-md whitespace-nowrap ${
              activeTab === 'students' ? 'bg-slate-100 text-slate-900 font-semibold' : 'text-slate-600'
            }`}
          >
            Siswa
          </button>
          {(hasRole('bk') || isSuperadminOrAdmin) && (
            <button
              onClick={() => onTabChange('bk')}
              className={`px-2.5 py-1 text-xs rounded-md whitespace-nowrap ${
                activeTab === 'bk' ? 'bg-slate-100 text-slate-900 font-semibold' : 'text-slate-600'
              }`}
            >
              BK
            </button>
          )}
          {isSuperadminOrAdmin && (
            <button
              onClick={() => onTabChange('admin')}
              className={`px-2.5 py-1 text-xs rounded-md whitespace-nowrap ${
                activeTab === 'admin' ? 'bg-slate-100 text-slate-900 font-semibold' : 'text-slate-600'
              }`}
            >
              Admin
            </button>
          )}
        </div>
      )}
    </header>
  );
};
