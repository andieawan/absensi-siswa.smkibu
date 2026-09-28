import React, { useState } from 'react';
import { GoogleDocsService, DocumentCreationResult } from '../services/googleDocs';
import { getGoogleAccessToken, googleSignIn } from '../services/googleAuth';
import { Student, AttendanceRecord, GradeActivity, GradeValue, SchoolSettings } from '../types';
import { FileText, ExternalLink, CheckCircle2, AlertCircle, Loader2 } from 'lucide-react';

interface GoogleDocsModalProps {
  isOpen: boolean;
  onClose: () => void;
  student?: Student | null;
  className?: string;
  records?: AttendanceRecord[];
  activities?: GradeActivity[];
  grades?: GradeValue[];
  settings: SchoolSettings;
  defaultMode?: 'warning_letter' | 'parent_summons' | 'semester_report';
}

export const GoogleDocsModal: React.FC<GoogleDocsModalProps> = ({
  isOpen,
  onClose,
  student,
  className = 'Kelas',
  records = [],
  activities = [],
  grades = [],
  settings,
  defaultMode = 'warning_letter',
}) => {
  const [docType, setDocType] = useState<'warning_letter' | 'parent_summons' | 'semester_report'>(
    defaultMode
  );

  // Form state for parent summons
  const [tanggalPertemuan, setTanggalPertemuan] = useState<string>(() => {
    const d = new Date();
    d.setDate(d.getDate() + 3);
    return d.toISOString().substring(0, 10);
  });
  const [jamPertemuan, setJamPertemuan] = useState<string>('09:00');
  const [ruangan, setRuangan] = useState<string>('Ruang Bimbingan Konseling (BK)');
  const [alasan, setAlasan] = useState<string>(
    'Akumulasi ketidakhadiran tanpa keterangan (Alpa) dan evaluasi capaian belajar'
  );

  const [isExporting, setIsExporting] = useState<boolean>(false);
  const [result, setResult] = useState<DocumentCreationResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  if (!isOpen) return null;

  const handleGenerateDoc = async () => {
    setIsExporting(true);
    setError(null);

    try {
      let token = getGoogleAccessToken();
      if (!token) {
        const signRes = await googleSignIn();
        token = signRes?.accessToken || null;
      }

      if (!token) {
        throw new Error('Diperlukan izin Google Workspace. Silakan hubungkan akun Google Anda.');
      }

      let res: DocumentCreationResult;

      if (docType === 'warning_letter') {
        if (!student) throw new Error('Pilih siswa untuk membuat surat peringatan.');
        res = await GoogleDocsService.generateStudentWarningLetter({
          student,
          className,
          records,
          settings,
          accessToken: token,
        });
      } else if (docType === 'parent_summons') {
        if (!student) throw new Error('Pilih siswa untuk membuat surat panggilan.');
        res = await GoogleDocsService.generateParentSummonsLetter({
          student,
          className,
          settings,
          tanggalPertemuan,
          jamPertemuan,
          ruangan,
          alasan,
          accessToken: token,
        });
      } else {
        res = await GoogleDocsService.generateSemesterReportDoc({
          className,
          subjectName: 'Semua Mata Pelajaran',
          students: student ? [student] : [],
          records,
          activities,
          grades,
          settings,
          accessToken: token,
        });
      }

      setResult(res);
    } catch (err: any) {
      console.error('Google Docs generation error:', err);
      setError(err.message || 'Gagal membuat dokumen di Google Docs.');
    } finally {
      setIsExporting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="bg-white rounded-xl shadow-xl max-w-lg w-full p-5 space-y-4 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between border-b border-slate-200 pb-3">
          <div className="flex items-center gap-2 text-slate-900 font-bold text-sm">
            <FileText className="w-4 h-4 text-blue-600" />
            <span>Generate Dokumen Resmi Google Docs</span>
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
          <div className="p-5 bg-blue-50/60 border border-blue-200 rounded-xl text-center space-y-3">
            <CheckCircle2 className="w-10 h-10 text-blue-600 mx-auto" />
            <div className="text-sm font-bold text-slate-900">
              Dokumen Resmi Berhasil Dibuat di Google Docs!
            </div>
            <p className="text-xs text-slate-600">
              Judul: <strong>{result.title}</strong>
            </p>
            <div className="pt-2">
              <a
                href={result.documentUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow-xs transition-colors"
              >
                <span>Buka di Google Docs</span>
                <ExternalLink className="w-3.5 h-3.5" />
              </a>
            </div>
          </div>
        ) : (
          <div className="space-y-4">
            {/* Document Type Selector */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                Jenis Dokumen yang Ingin Dibuat:
              </label>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <button
                  type="button"
                  onClick={() => setDocType('warning_letter')}
                  className={`p-2.5 rounded-lg border text-left text-xs transition-all ${
                    docType === 'warning_letter'
                      ? 'border-blue-500 bg-blue-50 text-blue-900 font-semibold'
                      : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <div className="font-bold">Surat Peringatan</div>
                  <div className="text-[10px] text-slate-500 mt-0.5">
                    Evaluasi ketidakhadiran siswa
                  </div>
                </button>

                <button
                  type="button"
                  onClick={() => setDocType('parent_summons')}
                  className={`p-2.5 rounded-lg border text-left text-xs transition-all ${
                    docType === 'parent_summons'
                      ? 'border-blue-500 bg-blue-50 text-blue-900 font-semibold'
                      : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <div className="font-bold">Panggilan Ortu</div>
                  <div className="text-[10px] text-slate-500 mt-0.5">
                    Undangan konsultasi BK
                  </div>
                </button>

                <button
                  type="button"
                  onClick={() => setDocType('semester_report')}
                  className={`p-2.5 rounded-lg border text-left text-xs transition-all ${
                    docType === 'semester_report'
                      ? 'border-blue-500 bg-blue-50 text-blue-900 font-semibold'
                      : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <div className="font-bold">Laporan Evaluasi</div>
                  <div className="text-[10px] text-slate-500 mt-0.5">
                    Rekapitulasi resmi kelas
                  </div>
                </button>
              </div>
            </div>

            {/* Target Student Preview */}
            {student && (
              <div className="p-3 bg-slate-50 border border-slate-200 rounded-lg text-xs space-y-1">
                <div className="font-semibold text-slate-800">
                  Target Siswa: {student.nama} ({student.nis})
                </div>
                <div className="text-slate-500 text-[11px]">
                  Kelas: {className} · Sekolah: {settings.school_name}
                </div>
              </div>
            )}

            {/* Additional Fields for Parent Summons */}
            {docType === 'parent_summons' && (
              <div className="space-y-2 p-3 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <label className="block text-[11px] font-semibold text-slate-600 mb-1">
                      Hari / Tanggal Pertemuan
                    </label>
                    <input
                      type="date"
                      value={tanggalPertemuan}
                      onChange={(e) => setTanggalPertemuan(e.target.value)}
                      className="w-full p-1.5 bg-white border border-slate-200 rounded text-xs"
                    />
                  </div>
                  <div>
                    <label className="block text-[11px] font-semibold text-slate-600 mb-1">
                      Jam Pertemuan
                    </label>
                    <input
                      type="time"
                      value={jamPertemuan}
                      onChange={(e) => setJamPertemuan(e.target.value)}
                      className="w-full p-1.5 bg-white border border-slate-200 rounded text-xs"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-[11px] font-semibold text-slate-600 mb-1">
                    Ruang Pertemuan
                  </label>
                  <input
                    type="text"
                    value={ruangan}
                    onChange={(e) => setRuangan(e.target.value)}
                    className="w-full p-1.5 bg-white border border-slate-200 rounded text-xs"
                  />
                </div>

                <div>
                  <label className="block text-[11px] font-semibold text-slate-600 mb-1">
                    Alasan / Pokok Masalah
                  </label>
                  <textarea
                    rows={2}
                    value={alasan}
                    onChange={(e) => setAlasan(e.target.value)}
                    className="w-full p-1.5 bg-white border border-slate-200 rounded text-xs"
                  />
                </div>
              </div>
            )}

            <div className="pt-2 flex items-center justify-end gap-2 border-t border-slate-100">
              <button
                type="button"
                onClick={onClose}
                disabled={isExporting}
                className="px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 rounded-lg"
              >
                Batal
              </button>
              <button
                type="button"
                onClick={handleGenerateDoc}
                disabled={isExporting}
                className="flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors shadow-xs"
              >
                {isExporting ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    <span>Menyusun Dokumen...</span>
                  </>
                ) : (
                  <>
                    <FileText className="w-3.5 h-3.5" />
                    <span>Buat di Google Docs</span>
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
