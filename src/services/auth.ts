import { User } from '../types';
import { storage } from './storage';

const AUTH_STORAGE_KEY = 'go_absen_auth_session_v1';

export function hashPassword(plain: string): string {
  // Deterministic salt & hash simulation matching standard bcrypt format $2b$12$...
  let hash = 5381;
  for (let i = 0; i < plain.length; i++) {
    hash = (hash * 33) ^ plain.charCodeAt(i);
  }
  const hex = Math.abs(hash).toString(16).padStart(8, '0');
  return `$2b$12$eX4mple.${hex}.${plain.length}`;
}

export interface AuthSession {
  userId: number;
  username: string;
  loggedInAt: string;
}

export const authService = {
  /**
   * Hashes a raw password
   */
  hashPassword,

  /**
   * Verify credentials for a given user or user ID
   */
  verifyCredentials(
    userOrId: User | number,
    passwordAttempt: string
  ): { success: boolean; user?: User; error?: string } {
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

    // Verification Rules:
    // 1. Universal demo master PIN: '123456'
    // 2. Generic demo teacher password: 'guru123'
    // 3. Admin specific default: 'admin123' (if admin/superadmin)
    // 4. Matches calculated hash from user.password_hash
    // 5. Matches raw password_hash (if stored as plain text)
    // 6. Matches user username
    const calculatedHash = hashPassword(trimmedPassword);
    const isMasterPin = trimmedPassword === '123456';
    const isGuruDefault = trimmedPassword === 'guru123';
    const isAdminDefault = trimmedPassword === 'admin123' && (user.roles.includes('admin') || user.roles.includes('superadmin'));
    const isKepsekDefault = trimmedPassword === 'kepsek123' && user.roles.includes('kepsek');
    const isBkDefault = trimmedPassword === 'bk123' && user.roles.includes('bk');
    const isUsername = trimmedPassword.toLowerCase() === user.username.toLowerCase();
    const isHashMatched = Boolean(user.password_hash && (user.password_hash === calculatedHash || user.password_hash === trimmedPassword));

    if (isMasterPin || isGuruDefault || isAdminDefault || isKepsekDefault || isBkDefault || isUsername || isHashMatched) {
      return { success: true, user };
    }

    return { success: false, error: 'Password atau PIN yang dimasukkan tidak sesuai.' };
  },

  /**
   * Performs login with username and password
   */
  login(
    username: string,
    passwordAttempt: string
  ): { success: boolean; user?: User; error?: string } {
    if (!username || !username.trim()) {
      return { success: false, error: 'Username wajib diisi.' };
    }
    if (!passwordAttempt || !passwordAttempt.trim()) {
      return { success: false, error: 'Password / PIN wajib diisi.' };
    }

    const cleanUsername = username.trim().toLowerCase();
    const users = storage.getUsers();
    const user = users.find((u) => u.username.toLowerCase() === cleanUsername);

    if (!user) {
      return { success: false, error: `Akun dengan username "${username}" tidak ditemukan.` };
    }

    const verification = this.verifyCredentials(user, passwordAttempt);
    if (!verification.success) {
      return verification;
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
    try {
      localStorage.removeItem(AUTH_STORAGE_KEY);
    } catch (e) {
      console.error('Failed to clear session', e);
    }
  },
};
