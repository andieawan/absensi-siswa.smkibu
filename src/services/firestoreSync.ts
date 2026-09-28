import {
  doc,
  getDoc,
  getDocFromServer,
  setDoc,
  deleteDoc,
  getDocs,
  collection,
  runTransaction,
} from 'firebase/firestore';
import { db, auth } from '../lib/firebase';
import { storage } from './storage';
import {
  AttendanceRecord,
  Student,
  User,
  ClassItem,
  Subject,
  TeacherPairing,
  GradeActivity,
  GradeValue,
  SchoolSettings,
  KetuaKelasToken,
} from '../types';

/**
 * Cek koneksi Cloud Firestore.
 * SENGAJA TIDAK melakukan signInAnonymously — sesi anonim membuat semua
 * Firestore Rules yang mensyaratkan "isSignedIn()" lolos untuk siapa pun yang
 * sekadar membuka aplikasi, tanpa login sama sekali. Sinkronisasi Firestore
 * hanya akan berjalan untuk user yang benar-benar sudah sign-in (misalnya lewat
 * Google Sign-In / Workspace) — untuk user yang hanya login lokal (username/PIN),
 * sinkronisasi Firestore tetap nonaktif sampai autentikasi sungguhan di backend tersedia.
 */
export async function testFirestoreConnection(): Promise<boolean> {
  try {
    if (!auth.currentUser) {
      console.info('[Firebase] Tidak ada sesi Firebase Auth yang sah — Firestore tidak diakses.');
      return false;
    }
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

/**
 * Sinkronisasi Penuh dari LocalStorage -> Cloud Firestore (Full Push)
 * Mensinkronkan SELURUH data siswa dan absensi tanpa batasan slice.
 */
export async function syncStorageToFirestore(): Promise<void> {
  try {
    // 1. Sinkronkan Pengaturan Sekolah
    const settings = storage.getSettings();
    await setDoc(doc(db, 'settings', 'general'), {
      ...settings,
      syncedAt: new Date().toISOString(),
    });

    // 2. Sinkronkan SELURUH Data Siswa (Semua siswa dari seluruh kelas)
    const students = storage.getStudents();
    for (const student of students) {
      await setDoc(doc(db, 'students', String(student.id)), student);
    }

    // 3. Sinkronkan SELURUH Data Absensi (Dengan created_at_millis untuk aturan retensi 7 hari)
    const attendance = storage.getAttendance();
    for (const att of attendance) {
      await setDoc(doc(db, 'attendance', String(att.id)), {
        ...att,
        created_at_millis: att.created_at_millis || new Date(att.tanggal).getTime(),
      });
    }

    // 4. Sinkronkan Master Kelas
    const classes = storage.getClasses();
    for (const c of classes) {
      await setDoc(doc(db, 'classes', String(c.id)), c);
    }

    // 5. Sinkronkan Master Mata Pelajaran
    const subjects = storage.getSubjects();
    for (const s of subjects) {
      await setDoc(doc(db, 'subjects', String(s.id)), s);
    }

    // 6. Sinkronkan Akun Pengguna / Guru
    const users = storage.getUsers();
    for (const u of users) {
      await setDoc(doc(db, 'users', String(u.id)), {
        id: u.id,
        username: u.username,
        nama: u.nama,
        kelas_wali_id: u.kelas_wali_id,
        classes: u.classes || [],
        roles: u.roles,
        is_active: u.is_active,
      });
    }

    // 7. Sinkronkan Penugasan Guru (Pairings)
    const pairings = storage.getPairings();
    for (const p of pairings) {
      const pairingKey = `${p.user_id}_${p.subject_id}_${p.class_id}`;
      await setDoc(doc(db, 'pairings', pairingKey), {
        ...p,
        synced_at: new Date().toISOString(),
      });
    }

    // 8. Sinkronkan Token Delegasi Ketua Kelas
    const tokens = storage.getDelegationTokens();
    for (const tok of tokens) {
      await setDoc(doc(db, 'tokens', tok.token), tok);
    }

    // 9. Sinkronkan Penilaian (Grade Activities & Values)
    const gradeActivities = storage.getGradeActivities();
    for (const act of gradeActivities) {
      await setDoc(doc(db, 'grade_activities', act.id), act);
    }

    const gradeValues = storage.getGradeValues();
    for (const val of gradeValues) {
      const valKey = `${val.activity_id}_${val.student_id}`;
      await setDoc(doc(db, 'grade_values', valKey), val);
    }

    console.log(
      `[Firebase] Sinkronisasi Lengkap Sukses: ${students.length} Siswa, ${attendance.length} Absensi, ${classes.length} Kelas, ${tokens.length} Token telah tersimpan di Cloud Firestore.`
    );
  } catch (err) {
    console.warn('[Firebase] Kendala sinkronisasi Storage -> Firestore:', err);
  }
}

/**
 * Mekanisme Pembacaan Balik dari Cloud Firestore -> LocalStorage (Hydration Device Baru)
 * Mengambil seluruh data dari cloud ketika aplikasi dibuka di perangkat baru
 */
export async function syncFirestoreToStorage(): Promise<{
  success: boolean;
  pulled: boolean;
  counts: { students: number; attendance: number; users: number; classes: number; tokens: number };
}> {
  try {
    const [
      studentsSnap,
      attendanceSnap,
      classesSnap,
      subjectsSnap,
      usersSnap,
      pairingsSnap,
      tokensSnap,
      gradeActsSnap,
      gradeValsSnap,
    ] = await Promise.all([
      getDocs(collection(db, 'students')),
      getDocs(collection(db, 'attendance')),
      getDocs(collection(db, 'classes')),
      getDocs(collection(db, 'subjects')),
      getDocs(collection(db, 'users')),
      getDocs(collection(db, 'pairings')),
      getDocs(collection(db, 'tokens')),
      getDocs(collection(db, 'grade_activities')),
      getDocs(collection(db, 'grade_values')),
    ]);

    let hasRemoteData = false;

    // 1. Siswa
    if (!studentsSnap.empty) {
      hasRemoteData = true;
      const remoteStudents = studentsSnap.docs.map((d) => d.data() as Student);
      if (remoteStudents.length > 0) {
        storage.saveStudents(remoteStudents);
      }
    }

    // 2. Absensi
    if (!attendanceSnap.empty) {
      hasRemoteData = true;
      const remoteAttendance = attendanceSnap.docs.map((d) => d.data() as AttendanceRecord);
      if (remoteAttendance.length > 0) {
        storage.saveAttendance(remoteAttendance);
      }
    }

    // 3. Kelas
    if (!classesSnap.empty) {
      hasRemoteData = true;
      const remoteClasses = classesSnap.docs.map((d) => d.data() as ClassItem);
      if (remoteClasses.length > 0) {
        storage.saveClasses(remoteClasses);
      }
    }

    // 4. Mata Pelajaran
    if (!subjectsSnap.empty) {
      hasRemoteData = true;
      const remoteSubjects = subjectsSnap.docs.map((d) => d.data() as Subject);
      if (remoteSubjects.length > 0) {
        storage.saveSubjects(remoteSubjects);
      }
    }

    // 5. Pengguna (Preserve local password jika cloud tidak menyimpan password hash)
    if (!usersSnap.empty) {
      hasRemoteData = true;
      const remoteUsers = usersSnap.docs.map((d) => d.data() as User);
      if (remoteUsers.length > 0) {
        const currentUsers = storage.getUsers();
        const mergedUsers = remoteUsers.map((ru) => {
          const localUser = currentUsers.find((cu) => cu.id === ru.id);
          // password_hash TIDAK PERNAH disimpan/dibaca dari Firestore (lihat fungsi push di atas) —
          // selalu pakai hash lokal milik device ini agar tidak ada fallback plaintext yang bisa menimpa akun.
          return {
            ...ru,
            password_hash: localUser?.password_hash,
          };
        });
        storage.saveUsers(mergedUsers);
      }
    }

    // 6. Penugasan Guru (Pairings)
    if (!pairingsSnap.empty) {
      hasRemoteData = true;
      const remotePairings = pairingsSnap.docs.map((d) => d.data() as TeacherPairing);
      if (remotePairings.length > 0) {
        storage.savePairings(remotePairings);
      }
    }

    // 7. Token Delegasi
    if (!tokensSnap.empty) {
      hasRemoteData = true;
      const remoteTokens = tokensSnap.docs.map((d) => d.data() as KetuaKelasToken);
      if (remoteTokens.length > 0) {
        storage.saveDelegationTokens(remoteTokens);
      }
    }

    // 8. Penilaian
    if (!gradeActsSnap.empty) {
      const remoteActs = gradeActsSnap.docs.map((d) => d.data() as GradeActivity);
      storage.saveGradeActivities(remoteActs);
    }
    if (!gradeValsSnap.empty) {
      const remoteVals = gradeValsSnap.docs.map((d) => d.data() as GradeValue);
      storage.saveGradeValues(remoteVals);
    }

    const counts = {
      students: storage.getStudents().length,
      attendance: storage.getAttendance().length,
      users: storage.getUsers().length,
      classes: storage.getClasses().length,
      tokens: storage.getDelegationTokens().length,
    };

    console.log('[Firebase] Pembacaan balik Cloud Firestore -> LocalStorage sukses:', counts);
    return { success: true, pulled: hasRemoteData, counts };
  } catch (err) {
    console.warn('[Firebase] Kendala pembacaan balik Firestore -> Storage:', err);
    return {
      success: false,
      pulled: false,
      counts: {
        students: storage.getStudents().length,
        attendance: storage.getAttendance().length,
        users: storage.getUsers().length,
        classes: storage.getClasses().length,
        tokens: storage.getDelegationTokens().length,
      },
    };
  }
}

/**
 * Sinkronisasi Dua Arah Komprehensif (Bidirectional Sync)
 * 1. Menarik data cloud terlebih dahulu jika ada (hydrate device baru).
 * 2. Mendorong kelengkapan data lokal ke cloud jika cloud masih kosong/parsial.
 */
export async function initializeBidirectionalSync(): Promise<{
  success: boolean;
  mode: 'hydrated_from_cloud' | 'seeded_to_cloud' | 'bidirectional';
  counts: { students: number; attendance: number };
}> {
  try {
    // Langkah 1: Coba baca dari Firestore (Kasus Device Baru / Refresh)
    const pullResult = await syncFirestoreToStorage();

    // Langkah 2: Jika data di Firestore belum ada atau masih 0, dorong seluruh data lokal
    if (!pullResult.pulled || pullResult.counts.students === 0 || pullResult.counts.attendance === 0) {
      console.log('[Firebase] Memulai push data awal lengkap dari LocalStorage ke Cloud Firestore...');
      await syncStorageToFirestore();
      return {
        success: true,
        mode: 'seeded_to_cloud',
        counts: {
          students: storage.getStudents().length,
          attendance: storage.getAttendance().length,
        },
      };
    }

    // Langkah 3: Jika cloud sudah berisi data, jalankan update sinkronisasi untuk rekonsiliasi
    await syncStorageToFirestore();

    return {
      success: true,
      mode: 'bidirectional',
      counts: {
        students: storage.getStudents().length,
        attendance: storage.getAttendance().length,
      },
    };
  } catch (error) {
    console.warn('[Firebase] Kendala inisialisasi sinkronisasi dua arah:', error);
    return {
      success: false,
      mode: 'bidirectional',
      counts: {
        students: storage.getStudents().length,
        attendance: storage.getAttendance().length,
      },
    };
  }
}

/**
 * Simpan / Perbarui Token Delegasi ke Cloud Firestore
 */
export async function saveTokenToFirestore(token: KetuaKelasToken): Promise<void> {
  try {
    await setDoc(doc(db, 'tokens', token.token), {
      ...token,
      updated_at: new Date().toISOString(),
    });
    console.log(`[Firebase] Token ${token.token} berhasil disimpan ke Firestore.`);
  } catch (err) {
    console.warn(`[Firebase] Gagal menyimpan token ${token.token} ke Firestore:`, err);
  }
}

/**
 * Ambil data token langsung dari Cloud Firestore (Lintas-Device)
 */
export async function fetchTokenFromFirestore(tokenStr: string): Promise<KetuaKelasToken | null> {
  try {
    const snap = await getDoc(doc(db, 'tokens', tokenStr));
    if (snap.exists()) {
      return snap.data() as KetuaKelasToken;
    }
    return null;
  } catch (err) {
    console.warn(`[Firebase] Gagal mengambil token ${tokenStr} dari Firestore:`, err);
    return null;
  }
}

/**
 * Penghapusan dokumen absensi langsung di Firestore (Ditegakkan oleh Security Rules)
 */
export async function deleteAttendanceFromFirestore(recordId: number): Promise<boolean> {
  try {
    await deleteDoc(doc(db, 'attendance', String(recordId)));
    return true;
  } catch (err) {
    console.error(`[Firebase] Firestore delete rejected by Security Rules for record #${recordId}:`, err);
    throw err;
  }
}

/**
 * Alokasi ID Atomik Terkoordinasi via Firestore Transaction (Single Source of Truth)
 * Menjamin tidak ada collision antar device jika bertransaksi langsung dengan Cloud Firestore.
 */
export async function allocateFirestoreSequence(entity: string, count = 1): Promise<number[]> {
  try {
    const seqRef = doc(db, 'sequences', entity);
    const allocated = await runTransaction(db, async (transaction) => {
      const seqDoc = await transaction.get(seqRef);
      let current = 1000;
      if (seqDoc.exists()) {
        const data = seqDoc.data();
        current = Number(data.current_id) || 1000;
      }
      const safeCount = Math.max(1, count);
      const newCurrent = current + safeCount;
      transaction.set(seqRef, {
        entity,
        current_id: newCurrent,
        updated_at: new Date().toISOString(),
      });
      const ids: number[] = [];
      for (let i = current + 1; i <= newCurrent; i++) {
        ids.push(i);
      }
      return ids;
    });
    return allocated;
  } catch (err) {
    console.warn(`[Firebase] Gagal alokasi sekuens untuk entity ${entity} via Firestore transaction:`, err);
    return [];
  }
}

