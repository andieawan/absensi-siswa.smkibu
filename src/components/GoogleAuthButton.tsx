import React, { useState, useEffect } from 'react';
import { googleSignIn, googleSignOut, initGoogleAuth, getGoogleAccessToken } from '../services/googleAuth';
import { User as FirebaseUser } from 'firebase/auth';
import { CheckCircle2, LogOut, FileSpreadsheet } from 'lucide-react';

interface GoogleAuthButtonProps {
  onTokenChange?: (token: string | null) => void;
}

export const GoogleAuthButton: React.FC<GoogleAuthButtonProps> = ({ onTokenChange }) => {
  const [googleUser, setGoogleUser] = useState<FirebaseUser | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [hasToken, setHasToken] = useState<boolean>(false);

  useEffect(() => {
    const unsubscribe = initGoogleAuth(
      (user, token) => {
        setGoogleUser(user);
        setHasToken(Boolean(token));
        onTokenChange?.(token);
      },
      () => {
        setGoogleUser(null);
        setHasToken(false);
        onTokenChange?.(null);
      }
    );
    return () => unsubscribe();
  }, [onTokenChange]);

  const handleSignIn = async () => {
    setIsLoading(true);
    try {
      const res = await googleSignIn();
      if (res) {
        setGoogleUser(res.user);
        setHasToken(true);
        onTokenChange?.(res.accessToken);
      }
    } catch (err: any) {
      console.error('Google Sign In failed:', err);
      alert('Gagal menghubungkan Google Workspace: ' + (err.message || 'Izin ditolak'));
    } finally {
      setIsLoading(false);
    }
  };

  const handleSignOut = async () => {
    await googleSignOut();
    setGoogleUser(null);
    setHasToken(false);
    onTokenChange?.(null);
  };

  if (googleUser && hasToken) {
    return (
      <div className="flex items-center gap-2 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-md text-xs text-emerald-800">
        <FileSpreadsheet className="w-3.5 h-3.5 text-emerald-600" />
        <span className="font-medium hidden sm:inline">{googleUser.email}</span>
        <span className="text-[10px] text-emerald-600 font-semibold">(Docs & Sheets Aktif)</span>
        <button
          onClick={handleSignOut}
          title="Putuskan Hubungan Google Workspace"
          className="text-emerald-700 hover:text-emerald-900 p-0.5 rounded hover:bg-emerald-100"
        >
          <LogOut className="w-3 h-3" />
        </button>
      </div>
    );
  }

  return (
    <button
      onClick={handleSignIn}
      disabled={isLoading}
      className="flex items-center gap-2 px-3 py-1.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-medium rounded-md shadow-xs transition-colors"
      title="Hubungkan Google Drive, Google Docs & Google Sheets"
    >
      <svg className="w-3.5 h-3.5" viewBox="0 0 48 48">
        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
      </svg>
      <span>{isLoading ? 'Menghubungkan...' : 'Google Workspace'}</span>
    </button>
  );
};
