/**
 * Penyimpanan token sesi server (Bearer token) — modul kecil terpisah supaya
 * bisa dipakai baik dari services/auth.ts maupun services/storage.ts tanpa
 * circular import (auth.ts sudah meng-import storage.ts).
 */

const AUTH_TOKEN_KEY = 'go_absen_auth_token_v1';

export function getAuthToken(): string | null {
  try {
    return localStorage.getItem(AUTH_TOKEN_KEY);
  } catch {
    return null;
  }
}

export function setAuthToken(token: string): void {
  try {
    localStorage.setItem(AUTH_TOKEN_KEY, token);
  } catch (e) {
    console.error('Failed to store auth token', e);
  }
}

export function clearAuthToken(): void {
  try {
    localStorage.removeItem(AUTH_TOKEN_KEY);
  } catch {
    // ignore
  }
}

/** Header Authorization siap pakai untuk fetch() ke endpoint yang butuh sesi. */
export function authHeaders(extra?: Record<string, string>): Record<string, string> {
  const token = getAuthToken();
  return {
    ...(extra || {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
}

/**
 * fetch() ke endpoint server dengan header Authorization. Untuk body JSON,
 * gunakan `json` agar Content-Type terisi otomatis.
 */
export function apiFetch(
  url: string,
  init: { method?: string; json?: unknown; headers?: Record<string, string> } = {}
): Promise<Response> {
  const { method, json, headers } = init;
  return fetch(url, {
    method: method || (json !== undefined ? 'POST' : 'GET'),
    headers: authHeaders({
      ...(json !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(headers || {}),
    }),
    body: json !== undefined ? JSON.stringify(json) : undefined,
  });
}
