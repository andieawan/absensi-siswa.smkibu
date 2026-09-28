/**
 * Autentikasi Google Workspace (Drive/Sheets/Docs) TANPA Firebase.
 *
 * Sebelumnya modul ini memakai Firebase Auth (signInWithPopup + GoogleAuthProvider)
 * hanya sebagai pembungkus untuk mendapatkan OAuth access token dengan scope
 * Workspace — bukan untuk sistem login utama aplikasi (login utama tetap
 * username/PIN via authService, lihat src/services/auth.ts). Karena kebutuhannya
 * cuma access token OAuth, kita pakai Google Identity Services (GIS) langsung
 * dari Google (https://accounts.google.com/gsi/client), tanpa dependency Firebase
 * dan tanpa mengirim data apa pun ke project Firebase.
 */
import googleOAuthConfig from '../../google-oauth-config.json';

export const WORKSPACE_SCOPES = [
  'https://www.googleapis.com/auth/drive',
  'https://www.googleapis.com/auth/drive.file',
  'https://www.googleapis.com/auth/drive.readonly',
  'https://www.googleapis.com/auth/spreadsheets',
  'https://www.googleapis.com/auth/spreadsheets.readonly',
  'https://www.googleapis.com/auth/documents',
  'https://www.googleapis.com/auth/documents.readonly',
  'https://www.googleapis.com/auth/userinfo.email',
];

const CLIENT_ID = (googleOAuthConfig as { clientId: string }).clientId;
const GIS_SCRIPT_SRC = 'https://accounts.google.com/gsi/client';

// Representasi minimal user Google (tanpa Firebase User object) — hanya
// field yang benar-benar dipakai di UI (GoogleAuthButton menampilkan email).
export interface GoogleUserInfo {
  email: string | null;
}

interface TokenResponse {
  access_token?: string;
  error?: string;
  error_description?: string;
}

interface TokenClient {
  requestAccessToken: (opts?: { prompt?: string }) => void;
}

interface GoogleAccountsOAuth2 {
  initTokenClient: (config: {
    client_id: string;
    scope: string;
    prompt?: string;
    callback: (resp: TokenResponse) => void;
    error_callback?: (err: { type?: string; message?: string }) => void;
  }) => TokenClient;
  revoke: (accessToken: string, callback?: () => void) => void;
}

declare global {
  interface Window {
    google?: {
      accounts?: {
        oauth2?: GoogleAccountsOAuth2;
      };
    };
  }
}

// In-memory token cache (NEVER persisted ke localStorage/sessionStorage) —
// setiap reload halaman wajib login ulang, sama seperti perilaku sebelumnya.
let cachedAccessToken: string | null = null;
let cachedUser: GoogleUserInfo | null = null;
let gisLoadPromise: Promise<void> | null = null;

function loadGisScript(): Promise<void> {
  if (window.google?.accounts?.oauth2) {
    return Promise.resolve();
  }
  if (gisLoadPromise) return gisLoadPromise;

  gisLoadPromise = new Promise((resolve, reject) => {
    const existing = document.querySelector(`script[src="${GIS_SCRIPT_SRC}"]`);
    if (existing) {
      existing.addEventListener('load', () => resolve());
      existing.addEventListener('error', () => reject(new Error('Gagal memuat Google Identity Services.')));
      return;
    }
    const script = document.createElement('script');
    script.src = GIS_SCRIPT_SRC;
    script.async = true;
    script.defer = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('Gagal memuat Google Identity Services.'));
    document.head.appendChild(script);
  });
  return gisLoadPromise;
}

/**
 * Tidak ada sesi Google yang persisten lintas-reload (token hanya di memori),
 * jadi setiap kali aplikasi dibuka statusnya selalu "belum terhubung" sampai
 * user menekan tombol Google Workspace — persis seperti perilaku modul lama.
 * Fungsi ini dipertahankan agar signature-nya kompatibel dengan pemanggilnya.
 */
export const initGoogleAuth = (
  _onAuthSuccess?: (user: GoogleUserInfo, token: string) => void,
  onAuthFailure?: () => void
): (() => void) => {
  if (onAuthFailure) onAuthFailure();
  return () => {};
};

async function fetchUserEmail(accessToken: string): Promise<string | null> {
  try {
    const res = await fetch('https://www.googleapis.com/oauth2/v3/userinfo', {
      headers: { Authorization: `Bearer ${accessToken}` },
    });
    if (!res.ok) return null;
    const data = await res.json();
    return data?.email || null;
  } catch {
    return null;
  }
}

function requestAccessTokenAsync(): Promise<string> {
  return new Promise((resolve, reject) => {
    if (!window.google?.accounts?.oauth2) {
      reject(new Error('Google Identity Services belum siap dimuat.'));
      return;
    }
    // Setiap login dibuatkan token client baru dengan callback khusus permintaan
    // ini — GIS tidak menyediakan cara aman menunggu hasil dari client yang
    // dipakai ulang lintas-permintaan tanpa risiko callback saling menimpa.
    const client = window.google.accounts.oauth2.initTokenClient({
      client_id: CLIENT_ID,
      scope: WORKSPACE_SCOPES.join(' '),
      prompt: 'consent',
      callback: (resp) => {
        if (resp.error || !resp.access_token) {
          reject(new Error(resp.error_description || resp.error || 'Gagal mendapatkan token akses Google.'));
          return;
        }
        resolve(resp.access_token);
      },
      error_callback: (err) => {
        reject(new Error(err?.message || 'Login Google Workspace dibatalkan atau gagal.'));
      },
    });
    client.requestAccessToken({ prompt: 'consent' });
  });
}

export const googleSignIn = async (): Promise<{ user: GoogleUserInfo; accessToken: string } | null> => {
  await loadGisScript();
  const accessToken = await requestAccessTokenAsync();

  cachedAccessToken = accessToken;
  const email = await fetchUserEmail(accessToken);
  cachedUser = { email };

  return { user: cachedUser, accessToken };
};

export const getGoogleAccessToken = (): string | null => {
  return cachedAccessToken;
};

export const googleSignOut = async (): Promise<void> => {
  const token = cachedAccessToken;
  cachedAccessToken = null;
  cachedUser = null;
  if (token && window.google?.accounts?.oauth2) {
    window.google.accounts.oauth2.revoke(token);
  }
};
