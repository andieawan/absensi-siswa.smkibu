/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React, { useState, useEffect } from 'react';
import { storage } from './services/storage';
import { authService } from './services/auth';
import { User, ClassItem, Subject } from './types';
import { Navbar } from './components/Navbar';
import { LoginGateView } from './components/LoginGateView';
import { SwitchUserModal } from './components/SwitchUserModal';
import { DashboardView } from './components/DashboardView';
import { AttendanceView } from './components/AttendanceView';
import { GradesView } from './components/GradesView';
import { Student360View } from './components/Student360View';
import { AdminPanelView } from './components/AdminPanelView';
import { BkView } from './components/BkView';
import { DelegatedStudentView } from './components/DelegatedStudentView';
import {
  testFirestoreConnection,
  syncStorageToFirestore,
  syncFirestoreToStorage,
  initializeBidirectionalSync,
} from './services/firestoreSync';
import { initializeSqlBidirectionalSync } from './services/sqlSync';
import { ShieldCheck, Server, Database, Check, Flame, RefreshCw } from 'lucide-react';

export default function App() {
  const [currentUser, setCurrentUser] = useState<User | null>(() => authService.getAuthenticatedUser());
  const [isAuthenticated, setIsAuthenticated] = useState<boolean>(() => authService.isAuthenticated());
  const [allUsers, setAllUsers] = useState<User[]>(() => storage.getUsers());
  const [classes, setClasses] = useState<ClassItem[]>(() => storage.getClasses());
  const [subjects, setSubjects] = useState<Subject[]>(() => storage.getSubjects());
  const [firestoreConnected, setFirestoreConnected] = useState<boolean>(false);
  const [syncStatus, setSyncStatus] = useState<'idle' | 'syncing' | 'synced' | 'error'>('idle');
  const [syncedCounts, setSyncedCounts] = useState<{ students: number; attendance: number } | null>(null);
  const [lastSyncTime, setLastSyncTime] = useState<string | null>(null);

  // Active navigation tab
  const [activeTab, setActiveTab] = useState<string>('dashboard');

  // Selected student for deep dive in Student360View
  const [inspectedStudentId, setInspectedStudentId] = useState<number | null>(null);

  // Delegated mode (Ketua Kelas token)
  const [delegatedToken, setDelegatedToken] = useState<string | null>(null);

  // Switching user modal state
  const [pendingSwitchUserId, setPendingSwitchUserId] = useState<number | null>(null);

  const runBidirectionalSync = async () => {
    setSyncStatus('syncing');
    try {
      const res = await initializeBidirectionalSync();
      if (res.success) {
        setSyncStatus('synced');
        setSyncedCounts(res.counts);
        setLastSyncTime(
          new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
        );
        // Refresh component states with newly hydrated data
        setAllUsers(storage.getUsers());
        setClasses(storage.getClasses());
        setSubjects(storage.getSubjects());
      } else {
        setSyncStatus('idle');
      }
    } catch {
      setSyncStatus('error');
    }
  };

  // Sinkronisasi utama: server SQLite sekolah sendiri. Tidak butuh login Google —
  // berjalan untuk SEMUA user (login lokal username/PIN ataupun Google Workspace),
  // sehingga inilah jalur sinkronisasi lintas-perangkat utama aplikasi.
  const runSqlSync = async () => {
    setSyncStatus('syncing');
    try {
      const res = await initializeSqlBidirectionalSync();
      if (res.success) {
        setSyncStatus('synced');
        setSyncedCounts(res.counts);
        setLastSyncTime(
          new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
        );
        setAllUsers(storage.getUsers());
        setClasses(storage.getClasses());
        setSubjects(storage.getSubjects());
      } else {
        setSyncStatus('idle');
      }
    } catch {
      setSyncStatus('error');
    }
  };

  // /api/sync/pull & /api/sync/push sekarang butuh token sesi server (lihat
  // server.ts requireAuth/requireAdmin), jadi sinkronisasi baru dijalankan
  // SETELAH user login (bukan lagi saat aplikasi baru dibuka / belum login).
  useEffect(() => {
    if (isAuthenticated) {
      runSqlSync();
    }
  }, [isAuthenticated]);

  // Firestore tetap tersedia sebagai jalur sinkronisasi tambahan khusus untuk
  // user yang sign-in dengan akun Google Workspace (mis. untuk fitur Docs/Sheets).
  useEffect(() => {
    testFirestoreConnection().then(async (connected) => {
      setFirestoreConnected(connected);
      if (connected) {
        await runBidirectionalSync();
      }
    });
  }, []);

  // Check URL query parameters for token (e.g., ?token=...)
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const tokenParam = params.get('token');
    if (tokenParam) {
      setDelegatedToken(tokenParam);
    }
  }, []);

  /**
   * Protected user selection:
   * STRICT REQUIREMENT: Cannot be invoked without credential verification (password/PIN).
   */
  const handleSelectUser = async (
    userId: number,
    passwordAttempt?: string
  ): Promise<{ success: boolean; error?: string }> => {
    if (!passwordAttempt) {
      console.warn('handleSelectUser ditolak: Kredensial password/PIN wajib disertakan dan diverifikasi.');
      return {
        success: false,
        error: 'Verifikasi kredensial (password/PIN) diperlukan sebelum beralih akun.',
      };
    }

    const verification = await authService.verifyCredentials(userId, passwordAttempt);
    if (!verification.success || !verification.user) {
      return {
        success: false,
        error: verification.error || 'Password atau PIN tidak valid.',
      };
    }

    // Token sesi server harus ikut berpindah ke akun baru. Kalau server menjawab
    // dan menolak, pergantian akun dibatalkan (server = sumber kebenaran akun).
    const serverResult = await authService.loginToServer(verification.user.username, passwordAttempt.trim());
    if (serverResult.status === 'rejected') {
      return { success: false, error: serverResult.error };
    }

    // Credentials successfully verified
    const user = storage.setCurrentUser(userId);
    authService.setAuthenticatedUser(user);
    setCurrentUser(user);
    setPendingSwitchUserId(null);

    // If switching from admin tab when new user lacks admin role, navigate to dashboard
    if (activeTab === 'admin' && !user.roles.includes('admin') && !user.roles.includes('superadmin')) {
      setActiveTab('dashboard');
    }

    return { success: true };
  };

  const handleRequestSwitchUser = (userId: number) => {
    if (userId === currentUser?.id) return;
    setPendingSwitchUserId(userId);
  };

  const handleLogout = () => {
    authService.logout();
    setCurrentUser(null);
    setIsAuthenticated(false);
    setActiveTab('dashboard');
    setInspectedStudentId(null);
  };

  const handleRefreshData = () => {
    setAllUsers(storage.getUsers());
    setCurrentUser(authService.getAuthenticatedUser());
    setClasses(storage.getClasses());
    setSubjects(storage.getSubjects());
  };

  const handleResetDemo = () => {
    if (
      confirm(
        'Kembalikan seluruh data demo ke kondisi awal (termasuk riwayat absensi & kegiatan nilai)?\n\n' +
        'Catatan Keamanan: File backup JSON otomatis akan diunduh terlebih dahulu dan salinan darurat disimpan agar data Anda tidak hilang.'
      )
    ) {
      const result = storage.resetToDefault();
      handleRefreshData();
      setActiveTab('dashboard');
      alert(
        result.downloadTriggered
          ? 'Data demo berhasil direset ke kondisi awal! Salinan cadangan (backup JSON) telah otomatis diunduh ke perangkat Anda.'
          : 'Data demo berhasil direset ke kondisi awal! Salinan darurat telah dicadangkan di memori sistem.'
      );
    }
  };

  const handleNavigateToStudent = (studentId: number) => {
    setInspectedStudentId(studentId);
    setActiveTab('students');
  };

  // 1. Delegated student mode (Ketua Kelas URL link ?token=...)
  if (delegatedToken) {
    return (
      <DelegatedStudentView
        token={delegatedToken}
        onExit={() => {
          setDelegatedToken(null);
          window.history.replaceState({}, '', window.location.pathname);
        }}
      />
    );
  }

  // 2. Authentication Gate: If not authenticated or no user session, render Login Gate View
  if (!isAuthenticated || !currentUser) {
    return (
      <LoginGateView
        onLoginSuccess={(user) => {
          setCurrentUser(user);
          setIsAuthenticated(true);
        }}
      />
    );
  }

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900 flex flex-col">
      {/* Top Navbar */}
      <Navbar
        currentUser={currentUser}
        onRequestSwitchUser={handleRequestSwitchUser}
        onLogout={handleLogout}
        allUsers={allUsers}
        activeTab={activeTab}
        onTabChange={(tab) => {
          setActiveTab(tab);
          if (tab !== 'students') {
            setInspectedStudentId(null);
          }
        }}
        onResetDemo={handleResetDemo}
        isDelegatedMode={false}
      />

      {/* Main Viewport Container */}
      <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {delegatedToken ? (
          <DelegatedStudentView
            token={delegatedToken}
            onExit={() => {
              setDelegatedToken(null);
              window.history.replaceState({}, '', window.location.pathname);
            }}
          />
        ) : (
          <>
            {activeTab === 'dashboard' && (
              <DashboardView
                currentUser={currentUser}
                classes={classes}
                subjects={subjects}
                onSelectStudent={handleNavigateToStudent}
              />
            )}

            {activeTab === 'attendance' && (
              <AttendanceView
                currentUser={currentUser}
                classes={classes}
                subjects={subjects}
                onOpenDelegationView={(token) => setDelegatedToken(token)}
              />
            )}

            {activeTab === 'grades' && (
              <GradesView
                currentUser={currentUser}
                classes={classes}
                subjects={subjects}
              />
            )}

            {activeTab === 'students' && (
              <Student360View
                initialStudentId={inspectedStudentId}
                classes={classes}
                onBack={() => setActiveTab('dashboard')}
              />
            )}

            {activeTab === 'bk' && (
              <BkView
                currentUser={currentUser}
                classes={classes}
                onSelectStudent={handleNavigateToStudent}
              />
            )}

            {activeTab === 'admin' && (
              <AdminPanelView
                currentUser={currentUser}
                classes={classes}
                subjects={subjects}
                onRefreshData={handleRefreshData}
              />
            )}
          </>
        )}
      </main>

      {/* Quiet Production Footer */}
      <footer className="border-t border-slate-200 bg-white py-4 mt-auto">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500">
          <div className="flex items-center gap-2">
            <span className="font-semibold text-slate-700">go_absen_siswa</span>
            <span aria-hidden="true">·</span>
            <span>SMK Negeri 1 Prestasi Bangsa</span>
            <span aria-hidden="true">·</span>
            <span>Tahun Ajaran 2024/2025</span>
          </div>

          <div className="flex items-center gap-4">
            <div className="flex items-center gap-1.5 text-slate-600">
              <Server className="w-3.5 h-3.5 text-emerald-600" />
              <span>Node.js / Express</span>
            </div>
            <div className="flex items-center gap-1.5 text-slate-600">
              <Database className="w-3.5 h-3.5 text-indigo-600" />
              <span>SQLite Relational Engine (Server Sekolah)</span>
            </div>
            {firestoreConnected && (
              <div className="flex items-center gap-1.5 text-slate-700">
                <Flame className="w-3.5 h-3.5 text-amber-500" />
                <span>Cloud Firestore Aktif</span>
              </div>
            )}

            {syncStatus === 'syncing' ? (
              <div className="flex items-center gap-1 text-indigo-600 font-medium">
                <RefreshCw className="w-3 h-3 animate-spin" />
                <span>Sinkronisasi...</span>
              </div>
            ) : syncStatus === 'synced' ? (
              <button
                type="button"
                onClick={() => {
                  runSqlSync();
                  if (firestoreConnected) runBidirectionalSync();
                }}
                title={`Sinkronisasi server sekolah aktif · Terakhir: ${lastSyncTime || 'Baru saja'}`}
                className="flex items-center gap-1 text-emerald-700 font-semibold hover:text-emerald-800 transition-colors"
              >
                <Check className="w-3.5 h-3.5 text-emerald-600" />
                <span>
                  Sinkron Server ({syncedCounts ? `${syncedCounts.students} Siswa, ${syncedCounts.attendance} Absensi` : lastSyncTime || 'Lengkap'})
                </span>
              </button>
            ) : (
              <button
                type="button"
                onClick={() => {
                  runSqlSync();
                  if (firestoreConnected) runBidirectionalSync();
                }}
                className="flex items-center gap-1 text-slate-600 hover:text-indigo-600 transition-colors"
              >
                <RefreshCw className="w-3.5 h-3.5 text-slate-400" />
                <span>Sinkronkan Sekarang</span>
              </button>
            )}
          </div>
        </div>
      </footer>

      {/* Switch User Credential Verification Modal */}
      {pendingSwitchUserId && (
        <SwitchUserModal
          isOpen={Boolean(pendingSwitchUserId)}
          targetUser={allUsers.find((u) => u.id === pendingSwitchUserId) || null}
          onClose={() => setPendingSwitchUserId(null)}
          onConfirmSwitch={(password) => handleSelectUser(pendingSwitchUserId!, password)}
        />
      )}
    </div>
  );
}
