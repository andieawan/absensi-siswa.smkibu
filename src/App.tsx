/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React, { useState, useEffect } from 'react';
import { storage } from './services/storage';
import { User, ClassItem, Subject } from './types';
import { Navbar } from './components/Navbar';
import { DashboardView } from './components/DashboardView';
import { AttendanceView } from './components/AttendanceView';
import { GradesView } from './components/GradesView';
import { Student360View } from './components/Student360View';
import { AdminPanelView } from './components/AdminPanelView';
import { BkView } from './components/BkView';
import { DelegatedStudentView } from './components/DelegatedStudentView';
import { testFirestoreConnection, syncStorageToFirestore } from './services/firestoreSync';
import { ShieldCheck, Server, Database, Check, Flame } from 'lucide-react';

export default function App() {
  const [currentUser, setCurrentUser] = useState<User>(() => storage.getCurrentUser());
  const [allUsers, setAllUsers] = useState<User[]>(() => storage.getUsers());
  const [classes, setClasses] = useState<ClassItem[]>(() => storage.getClasses());
  const [subjects, setSubjects] = useState<Subject[]>(() => storage.getSubjects());
  const [firestoreConnected, setFirestoreConnected] = useState<boolean>(false);

  // Active navigation tab
  const [activeTab, setActiveTab] = useState<string>('dashboard');

  // Selected student for deep dive in Student360View
  const [inspectedStudentId, setInspectedStudentId] = useState<number | null>(null);

  // Delegated mode (Ketua Kelas token)
  const [delegatedToken, setDelegatedToken] = useState<string | null>(null);

  // Initialize and validate Firestore connection on mount
  useEffect(() => {
    testFirestoreConnection().then((connected) => {
      setFirestoreConnected(connected);
      if (connected) {
        syncStorageToFirestore();
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

  const handleSelectUser = (userId: number) => {
    const user = storage.setCurrentUser(userId);
    setCurrentUser(user);
    // If switching from admin tab when new user lacks admin role, navigate to dashboard
    if (activeTab === 'admin' && !user.roles.includes('admin') && !user.roles.includes('superadmin')) {
      setActiveTab('dashboard');
    }
  };

  const handleRefreshData = () => {
    setAllUsers(storage.getUsers());
    setCurrentUser(storage.getCurrentUser());
    setClasses(storage.getClasses());
    setSubjects(storage.getSubjects());
  };

  const handleResetDemo = () => {
    if (confirm('Kembalikan seluruh data demo ke kondisi awal (termasuk riwayat absensi & kegiatan nilai)?')) {
      storage.resetToDefault();
      handleRefreshData();
      setActiveTab('dashboard');
      alert('Data demo berhasil direset ke kondisi awal!');
    }
  };

  const handleNavigateToStudent = (studentId: number) => {
    setInspectedStudentId(studentId);
    setActiveTab('students');
  };

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900 flex flex-col">
      {/* Top Navbar */}
      <Navbar
        currentUser={currentUser}
        onSelectUser={handleSelectUser}
        allUsers={allUsers}
        activeTab={activeTab}
        onTabChange={(tab) => {
          setActiveTab(tab);
          if (tab !== 'students') {
            setInspectedStudentId(null);
          }
        }}
        onResetDemo={handleResetDemo}
        isDelegatedMode={Boolean(delegatedToken)}
        onExitDelegation={() => {
          setDelegatedToken(null);
          window.history.replaceState({}, '', window.location.pathname);
        }}
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
              <span>MySQL Relational Engine</span>
            </div>
            <div className="flex items-center gap-1.5 text-slate-600">
              <Flame className="w-3.5 h-3.5 text-amber-500" />
              <span>Cloud Firestore (asia-southeast1)</span>
            </div>
            <div className="flex items-center gap-1 text-emerald-700 font-semibold">
              <Check className="w-3.5 h-3.5" />
              <span>Sistem Aktif</span>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
}
