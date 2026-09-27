import React, { useState } from 'react';
import { GoogleSheetsService, SpreadsheetCreationResult } from '../services/googleSheets';
import { getGoogleAccessToken, googleSignIn } from '../services/googleAuth';
import { FileSpreadsheet, ExternalLink, CheckCircle2, AlertCircle, Loader2 } from 'lucide-react';

interface GoogleSheetsModalProps {
  isOpen: boolean;
  onClose: () => void;
  mode: 'attendance' | 'grades' | 'backup';
  payload: any;
}

export const GoogleSheetsModal: React.FC<GoogleSheetsModalProps> = ({
  isOpen,
  onClose,
  mode,
  payload,
}) => {
  const [isExporting, setIsExporting] = useState<boolean>(false);
  const [result, setResult] = useState<SpreadsheetCreationResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  if (!isOpen) return null;

  const currentToken = getGoogleAccessToken();

  const handleExecuteExport = async () => {
    setIsExporting(true);
    setError(null);

    try {
      let token = getGoogleAccessToken();
      if (!token) {
        const signRes = await googleSignIn();
        token = signRes?.accessToken || null;
      }

      if (!token) {
        throw new Error('Diperlukan izin Google Workspace. Silakan login kembali.');
      }

      let res: SpreadsheetCreationResult;

      if (mode === 'attendance') {
        res = await GoogleSheetsService.exportAttendanceToGoogleSheets({
          className: payload.className,
          subjectName: payload.subjectName,
          students: payload.students,
          records: payload.records,
          accessToken: token,
        });
      } else if (mode === 'grades') {
        res = await GoogleSheetsService.exportGradesToGoogleSheets({
          className: payload.className,
          subjectName: payload.subjectName,
          students: payload.students,
          activities: payload.activities,
          grades: payload.grades,
          accessToken: token,
        });
      } else {
        res = await GoogleSheetsService.exportFullBackupToGoogleSheets({
          students: payload.students,
          classes: payload.classes,
          subjects: payload.subjects,
          attendances: payload.attendances,
          activities: payload.activities,
          grades: payload.grades,
          accessToken: token,
        });
      }

      setResult(res);
    } catch (err: any) {
      console.error('Google Sheets export failed:', err);
      setError(err.message || 'Gagal mengekspor data ke Google Sheets.');
    } finally {
      setIsExporting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="bg-white rounded-xl shadow-xl max-w-md w-full p-5 space-y-4">
        <div className="flex items-center justify-between border-b border-slate-200 pb-3">
          <div className="flex items-center gap-2 text-slate-900 font-bold text-sm">
            <FileSpreadsheet className="w-4 h-4 text-emerald-600" />
            <span>
              {mode === 'attendance'
                ? 'Ekspor Presensi ke Google Sheets'
                : mode === 'grades'
                ? 'Ekspor Nilai ke Google Sheets'
                : 'Cadangkan Database ke Google Sheets'}
            </span>
          </div>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-slate-700 text-lg font-bold"
          >
            &times;
          </button>
        </div>

        {error && (
          <div className="p-3 bg-rose-50 border border-rose-200 rounded-lg text-xs text-rose-800 flex items-start gap-2">
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
            <span>{error}</span>
          </div>
        )}

        {result ? (
          <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-xl text-center space-y-3">
            <CheckCircle2 className="w-10 h-10 text-emerald-600 mx-auto" />
            <div className="text-sm font-bold text-slate-900">
              Spreadsheet Berhasil Dibuat di Google Drive!
            </div>
            <p className="text-xs text-slate-600">
              Dokumen: <strong>{result.title}</strong>
            </p>
            <div className="pt-2">
              <a
                href={result.spreadsheetUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg shadow-xs transition-colors"
              >
                <span>Buka di Google Sheets</span>
                <ExternalLink className="w-3.5 h-3.5" />
              </a>
            </div>
          </div>
        ) : (
          <div className="space-y-3">
            <p className="text-xs text-slate-600 leading-relaxed">
              Sistem akan membuat file spreadsheet baru secara otomatis di Google Drive akun Anda dengan format tabel resmi terstandar.
            </p>

            <div className="p-3 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-700 space-y-1">
              <div>
                <strong>Target File:</strong>{' '}
                {mode === 'attendance'
                  ? `Rekap Presensi ${payload.className}`
                  : mode === 'grades'
                  ? `Rekap Nilai ${payload.className}`
                  : 'Backup Master & Absensi (Multi-Tab)'}
              </div>
              <div className="text-[11px] text-slate-500">
                Izin: Google Sheets & Google Drive (File Baru)
              </div>
            </div>

            <div className="pt-2 flex items-center justify-end gap-2">
              <button
                onClick={onClose}
                disabled={isExporting}
                className="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg"
              >
                Batal
              </button>
              <button
                onClick={handleExecuteExport}
                disabled={isExporting}
                className="flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold bg-slate-900 hover:bg-slate-800 text-white rounded-lg transition-colors shadow-xs"
              >
                {isExporting ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    <span>Memproses di Google Drive...</span>
                  </>
                ) : (
                  <>
                    <FileSpreadsheet className="w-3.5 h-3.5 text-emerald-400" />
                    <span>Buat Spreadsheet Sekarang</span>
                  </>
                )}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};
