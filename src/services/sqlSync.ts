import { storage } from './storage';
import { authHeaders } from './authToken';

/**
 * Sinkronisasi Dua Arah dengan Backend SQLite (server/db.ts, lewat REST API server.ts)
 *
 * Berbeda dengan Firestore (services/firestoreSync.ts) yang baru aktif setelah user
 * benar-benar Sign-In dengan akun Google Workspace, sinkronisasi SQL ini TIDAK butuh
 * login Google sama sekali — server SQLite adalah server sekolah sendiri, jadi berlaku
 * untuk semua user yang login lokal (username/PIN) di perangkat manapun. Inilah jalur
 * sinkronisasi lintas-perangkat utama untuk sebagian besar guru.
 *
 * Endpoint /api/sync/pull & /api/sync/push mensyaratkan token sesi server (Bearer
 * token) yang diterbitkan saat login (lihat services/auth.ts -> loginToServer).
 * Tanpa token yang valid, server menolak dengan 401/403 — fungsi di bawah ini
 * menangani penolakan itu dengan gagal secara halus (tidak melempar ke pemanggil).
 *
 * Pola: pull data terbaru dari server saat aplikasi dibuka (hidrasi), lalu push
 * data master lokal ke server agar server selalu punya salinan terbaru untuk
 * device lain. password_hash TIDAK PERNAH ikut dikirim di respons GET server,
 * dan saat push, field password_hash TETAP dikirim (server butuh hash untuk
 * verifikasi login) tapi tidak pernah ditampilkan balik ke client manapun.
 * Push data master (classes/subjects/students/users/pairings/settings) hanya
 * diterima server dari akun Administrator (lihat requireAdmin di server.ts).
 */

export async function testSqlConnection(): Promise<boolean> {
  try {
    const res = await fetch('/api/health');
    return res.ok;
  } catch {
    return false;
  }
}

/**
 * Pull: Server SQLite -> LocalStorage (hidrasi perangkat baru / refresh)
 */
export async function syncSqlToStorage(): Promise<{
  success: boolean;
  pulled: boolean;
  counts: { students: number; attendance: number; users: number; classes: number; tokens: number };
}> {
  try {
    const res = await fetch('/api/sync/pull', { headers: authHeaders() });
    if (!res.ok) throw new Error(`Server merespons status ${res.status}`);
    const body = await res.json();
    if (!body.success || !body.data) throw new Error('Payload sinkronisasi tidak valid.');
    const remote = body.data;

    let hasRemoteData = false;

    if (Array.isArray(remote.classes) && remote.classes.length > 0) {
      hasRemoteData = true;
      storage.saveClasses(remote.classes);
    }
    if (Array.isArray(remote.subjects) && remote.subjects.length > 0) {
      hasRemoteData = true;
      storage.saveSubjects(remote.subjects);
    }
    if (Array.isArray(remote.students) && remote.students.length > 0) {
      hasRemoteData = true;
      storage.saveStudents(remote.students);
    }
    if (Array.isArray(remote.users) && remote.users.length > 0) {
      hasRemoteData = true;
      // Server tidak pernah mengirim password_hash — pertahankan hash lokal per akun
      // agar tidak ada device yang tiba-tiba kehilangan kemampuan verifikasi password.
      const currentUsers = storage.getUsers();
      const mergedUsers = remote.users.map((ru: any) => {
        const localUser = currentUsers.find((cu) => cu.id === ru.id);
        return { ...ru, password_hash: localUser?.password_hash };
      });
      storage.saveUsers(mergedUsers);
    }
    if (Array.isArray(remote.pairings) && remote.pairings.length > 0) {
      hasRemoteData = true;
      storage.savePairings(remote.pairings);
    }
    if (remote.settings) {
      hasRemoteData = true;
      storage.updateSettings(remote.settings);
    }
    if (Array.isArray(remote.attendance) && remote.attendance.length > 0) {
      hasRemoteData = true;
      storage.saveAttendance(remote.attendance);
    }
    if (Array.isArray(remote.tokens) && remote.tokens.length > 0) {
      hasRemoteData = true;
      storage.saveDelegationTokens(remote.tokens);
    }
    if (Array.isArray(remote.gradeActivities) && remote.gradeActivities.length > 0) {
      storage.saveGradeActivities(remote.gradeActivities);
    }
    if (Array.isArray(remote.gradeValues) && remote.gradeValues.length > 0) {
      storage.saveGradeValues(remote.gradeValues);
    }

    const counts = {
      students: storage.getStudents().length,
      attendance: storage.getAttendance().length,
      users: storage.getUsers().length,
      classes: storage.getClasses().length,
      tokens: storage.getDelegationTokens().length,
    };
    console.log('[SQL] Pembacaan balik Server SQLite -> LocalStorage sukses:', counts);
    return { success: true, pulled: hasRemoteData, counts };
  } catch (err) {
    console.warn('[SQL] Kendala pembacaan balik Server -> Storage:', err);
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
 * Push: LocalStorage -> Server SQLite (rekonsiliasi data master)
 * Absensi, token, dan nilai TIDAK dikirim di sini karena sudah punya jalur
 * sinkronisasi tersendiri (submit/delete/register langsung ke server saat terjadi).
 *
 * Server HANYA menerima push ini dari akun Administrator/Superadmin (lihat
 * requireAdmin di server.ts) — mengubah data master (termasuk roster akun &
 * roles) bukan aksi yang boleh dilakukan sembarang device. Untuk user biasa
 * (guru non-admin), fungsi ini sengaja dilewati di sisi client (lihat cek
 * role sebelum memanggil ini di initializeSqlBidirectionalSync) supaya tidak
 * memicu request yang pasti ditolak 403 setiap kali sinkronisasi berjalan.
 */
export async function syncStorageToSql(): Promise<boolean> {
  try {
    const payload = {
      classes: storage.getClasses(),
      subjects: storage.getSubjects(),
      students: storage.getStudents(),
      users: storage.getUsers(),
      pairings: storage.getPairings(),
      settings: storage.getSettings(),
    };
    const res = await fetch('/api/sync/push', {
      method: 'POST',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify(payload),
    });
    if (!res.ok) throw new Error(`Server merespons status ${res.status}`);
    const body = await res.json().catch(() => null);
    if (body?.warnings?.length) {
      console.warn('[SQL] Server memberi peringatan saat sinkronisasi:', body.warnings);
    }
    console.log('[SQL] Sinkronisasi data master LocalStorage -> Server SQLite sukses.');
    return true;
  } catch (err) {
    console.warn('[SQL] Kendala sinkronisasi Storage -> Server SQLite:', err);
    return false;
  }
}

function isCurrentUserAdmin(): boolean {
  const current = storage.getCurrentUser();
  const roles = current?.roles || [];
  return roles.includes('admin') || roles.includes('superadmin');
}

/**
 * Sinkronisasi dua arah komprehensif dengan server sekolah sendiri (SQLite).
 * 1. Tarik data server dulu (hidrasi perangkat baru / device lain sudah menulis data).
 * 2. Dorong balik data master lokal supaya server tetap jadi salinan terbaru & lengkap.
 */
export async function initializeSqlBidirectionalSync(): Promise<{
  success: boolean;
  mode: 'hydrated_from_server' | 'seeded_to_server' | 'bidirectional';
  counts: { students: number; attendance: number };
}> {
  try {
    const pullResult = await syncSqlToStorage();

    // Push data master hanya boleh dilakukan Administrator/Superadmin — server
    // akan menolak (403) push dari akun lain, jadi jangan repot memanggilnya.
    const canPush = isCurrentUserAdmin();

    if (!pullResult.pulled || pullResult.counts.students === 0) {
      if (canPush) await syncStorageToSql();
      return {
        success: true,
        mode: 'seeded_to_server',
        counts: {
          students: storage.getStudents().length,
          attendance: storage.getAttendance().length,
        },
      };
    }

    if (canPush) await syncStorageToSql();

    return {
      success: true,
      mode: 'bidirectional',
      counts: {
        students: storage.getStudents().length,
        attendance: storage.getAttendance().length,
      },
    };
  } catch (err) {
    console.warn('[SQL] Kendala inisialisasi sinkronisasi dua arah:', err);
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
