import { User } from '../types';
import { storage } from './storage';
import { getAuthToken, setAuthToken, clearAuthToken } from './authToken';

const AUTH_STORAGE_KEY = 'go_absen_auth_session_v1';

// Format hash tersimpan: "sha256:<saltHex>:<hashHex>"
// CATATAN: SHA-256+salt jauh lebih baik dari checksum sebelumnya, tapi tetap
// bukan pengganti verifikasi password di server. Ini adalah perbaikan sementara
// selagi migrasi ke backend sungguhan (lihat perintah migrasi database) belum dikerjakan.
async function sha256Hex(input: string): Promise<string> {
  const enc = new TextEncoder().encode(input);
  const digest = await crypto.subtle.digest('SHA-256', enc);
  return Array.from(new Uint8Array(digest))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

function randomSaltHex(byteLength = 16): string {
  const bytes = new Uint8Array(byteLength);
  crypto.getRandomValues(bytes);
  return Array.from(bytes)
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

export async function hashPassword(plain: string, existingSalt?: string): Promise<string> {
  const salt = existingSalt || randomSaltHex();
  const hash = await sha256Hex(`${salt}:${plain}`);
  return `sha256:${salt}:${hash}`;
}

export async function verifyPasswordAgainstHash(plain: string, stored: string | undefined | null): Promise<boolean> {
  if (!stored) return false;
  const parts = stored.split(':');
  if (parts.length !== 3 || parts[0] !== 'sha256') return false;
  const [, salt] = parts;
  const recomputed = await hashPassword(plain, salt);
  return recomputed === stored;
}

export interface AuthSession {
  userId: number;
  username: string;
  loggedInAt: string;
}

/**
 * Hasil login ke server:
 * - 'ok'       : kredensial valid, token sesi server tersimpan.
 * - 'rejected' : server menjawab tapi menolak (password salah, akun nonaktif,
 *                terlalu banyak percobaan). TIDAK boleh fallback ke verifikasi lokal.
 * - 'offline'  : server tidak bisa dihubungi (jaringan / dev tanpa server).
 */
type ServerLoginResult =
  | { status: 'ok'; user: User }
  | { status: 'rejected'; error: string }
  | { status: 'offline' };

/**
 * Login ke server (POST /api/auth/login) untuk memperoleh token sesi.
 * Server adalah sumber kebenaran akun; verifikasi lokal hanya dipakai saat offline.
 */
async function loginToServer(username: string, password: string): Promise<ServerLoginResult> {
  try {
    if (typeof fetch === 'undefined') return { status: 'offline' };
    const res = await fetch('/api/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
    const data = await res.json().catch(() => null);
    if (res.ok && data?.success && data.token && data.user) {
      setAuthToken(data.token);
      return { status: 'ok', user: data.user as User };
    }
    // Respons non-JSON / 5xx = server bermasalah, perlakukan seperti offline.
    if (!data || res.status >= 500) return { status: 'offline' };
    return { status: 'rejected', error: data.error || 'Username atau password/PIN salah.' };
  } catch (err) {
    console.warn('[Auth] Tidak bisa menghubungi server untuk login:', err);
    return { status: 'offline' };
  }
}

/**
 * Simpan akun hasil login server ke daftar user lokal (server tidak pernah
 * mengirim password_hash, jadi hash lokal dibuat dari password yang barusan
 * terverifikasi server — dipakai untuk login saat offline).
 */
async function upsertLocalUserFromServer(serverUser: User, password: string): Promise<User> {
  const localHash = await hashPassword(password);
  const merged: User = { ...serverUser, password_hash: localHash };
  const users = storage.getUsers();
  const idx = users.findIndex((u) => u.id === merged.id);
  // Akun lain dengan username sama tapi ID berbeda (mis. sisa data demo) dibuang.
  const others = users.filter((u, i) => i !== idx && u.username.toLowerCase() !== merged.username.toLowerCase());
  if (idx >= 0) {
    others.splice(Math.min(idx, others.length), 0, merged);
  } else {
    others.push(merged);
  }
  storage.saveUsers(others);
  return merged;
}

export const authService = {
  /**
   * Hashes a raw password
   */
  hashPassword,

  getAuthToken,
  clearAuthToken,
  loginToServer,

  /**
   * Verify credentials for a given user or user ID.
   * SATU-SATUNYA jalur valid: password cocok dengan hash tersimpan di user.password_hash.
   * Tidak ada PIN universal, password default per-peran, atau password=username.
   */
  async verifyCredentials(
    userOrId: User | number,
    passwordAttempt: string
  ): Promise<{ success: boolean; user?: User; error?: string }> {
    if (!passwordAttempt || !passwordAttempt.trim()) {
      return { success: false, error: 'Password / PIN tidak boleh kosong.' };
    }

    const trimmedPassword = passwordAttempt.trim();
    let user: User | undefined;

    if (typeof userOrId === 'number') {
      const users = storage.getUsers();
      user = users.find((u) => u.id === userOrId);
    } else {
      user = userOrId;
    }

    if (!user) {
      return { success: false, error: 'Pengguna tidak ditemukan.' };
    }

    if (!user.is_active) {
      return { success: false, error: 'Akun ini dalam status nonaktif. Hubungi Administrator.' };
    }

    if (!user.password_hash) {
      return { success: false, error: 'Akun ini belum memiliki password terdaftar. Hubungi Administrator.' };
    }

    const isHashMatched = await verifyPasswordAgainstHash(trimmedPassword, user.password_hash);
    if (!isHashMatched) {
      return { success: false, error: 'Password atau PIN yang dimasukkan tidak sesuai.' };
    }

    return { success: true, user };
  },

  /**
   * Performs login with username and password
   */
  async login(
    username: string,
    passwordAttempt: string
  ): Promise<{ success: boolean; user?: User; error?: string }> {
    if (!username || !username.trim()) {
      return { success: false, error: 'Username wajib diisi.' };
    }
    if (!passwordAttempt || !passwordAttempt.trim()) {
      return { success: false, error: 'Password / PIN wajib diisi.' };
    }

    const cleanUsername = username.trim().toLowerCase();
    const cleanPassword = passwordAttempt.trim();

    // 1. Server adalah sumber kebenaran akun. Kalau server menjawab, keputusannya final.
    const serverResult = await loginToServer(cleanUsername, cleanPassword);
    if (serverResult.status === 'rejected') {
      return { success: false, error: serverResult.error };
    }

    let user: User;
    if (serverResult.status === 'ok') {
      user = await upsertLocalUserFromServer(serverResult.user, cleanPassword);
    } else {
      // 2. Mode offline: verifikasi terhadap salinan akun lokal perangkat ini.
      const localUser = storage.getUsers().find((u) => u.username.toLowerCase() === cleanUsername);
      if (!localUser) {
        return { success: false, error: 'Server tidak dapat dihubungi dan akun ini belum pernah login di perangkat ini.' };
      }
      const verification = await this.verifyCredentials(localUser, cleanPassword);
      if (!verification.success) {
        return verification;
      }
      user = localUser;
    }

    // Save session
    this.setAuthenticatedUser(user);
    storage.addAuditLog('Login Sistem', 'Sistem', user.nama, `Pengguna ${user.username} berhasil login`);

    return { success: true, user };
  },

  /**
   * Store active session
   */
  setAuthenticatedUser(user: User): void {
    const session: AuthSession = {
      userId: user.id,
      username: user.username,
      loggedInAt: new Date().toISOString(),
    };
    try {
      localStorage.setItem(AUTH_STORAGE_KEY, JSON.stringify(session));
      storage.setCurrentUser(user.id);
    } catch (e) {
      console.error('Failed to save session', e);
    }
  },

  /**
   * Get current authenticated user
   */
  getAuthenticatedUser(): User | null {
    try {
      const sessionStr = localStorage.getItem(AUTH_STORAGE_KEY);
      if (!sessionStr) return null;

      const session: AuthSession = JSON.parse(sessionStr);
      if (!session?.userId) return null;

      const users = storage.getUsers();
      const user = users.find((u) => u.id === session.userId && u.is_active);
      return user || null;
    } catch {
      return null;
    }
  },

  /**
   * Check if a valid user session is currently active
   */
  isAuthenticated(): boolean {
    return Boolean(this.getAuthenticatedUser());
  },

  /**
   * Clears the authentication session (Logout)
   */
  logout(): void {
    const currUser = this.getAuthenticatedUser();
    if (currUser) {
      storage.addAuditLog('Logout Sistem', 'Sistem', currUser.nama, `Pengguna ${currUser.username} keluar dari sistem`);
    }
    const token = getAuthToken();
    if (token && typeof fetch !== 'undefined') {
      fetch('/api/auth/logout', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
      }).catch(() => {
        // Best-effort: token server akan kedaluwarsa sendiri walau request ini gagal.
      });
    }
    try {
      localStorage.removeItem(AUTH_STORAGE_KEY);
      clearAuthToken();
    } catch (e) {
      console.error('Failed to clear session', e);
    }
  },
};
