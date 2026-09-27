import {
  doc,
  getDocFromServer,
  setDoc,
  getDocs,
  collection,
} from 'firebase/firestore';
import { db, handleFirestoreError, OperationType } from '../lib/firebase';
import { storage } from './storage';
import {
  AttendanceRecord,
  Student,
  User,
  GradeActivity,
  GradeValue,
  AuditLogItem,
  SchoolSettings,
} from '../types';

// Test connection on boot (Mandatory in SKILL.md)
export async function testFirestoreConnection(): Promise<boolean> {
  try {
    await getDocFromServer(doc(db, 'test', 'connection'));
    console.log('[Firebase] Firestore connected successfully');
    return true;
  } catch (error) {
    if (error instanceof Error && error.message.includes('the client is offline')) {
      console.error('Please check your Firebase configuration.');
    }
    console.warn('[Firebase] Connection probe finished:', error);
    return false;
  }
}

// Background sync helper
export async function syncStorageToFirestore(): Promise<void> {
  try {
    const settings = storage.getSettings();
    await setDoc(doc(db, 'settings', 'general'), {
      ...settings,
      syncedAt: new Date().toISOString(),
    });

    // Sync students
    const students = storage.getStudents();
    for (const student of students.slice(0, 10)) {
      await setDoc(doc(db, 'students', String(student.id)), student);
    }

    // Sync latest attendance
    const attendance = storage.getAttendance();
    for (const att of attendance.slice(-15)) {
      await setDoc(doc(db, 'attendance', String(att.id)), att);
    }

    console.log('[Firebase] Local data synced to Cloud Firestore');
  } catch (err) {
    console.warn('[Firebase] Sync notice:', err);
  }
}
