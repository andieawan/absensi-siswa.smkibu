import * as XLSX from 'xlsx';
import { Student, AttendanceRecord, GradeActivity, GradeValue } from '../types';

export interface HardcopyPreviewRow {
  nis: string;
  nama: string;
  isValidStudent: boolean;
  status: 'H' | 'I' | 'S' | 'A' | 'INVALID';
  rawStatus: string;
  notes?: string;
}

export interface HardcopyPreviewResult {
  tanggal: string;
  totalRows: number;
  validRows: number;
  invalidRows: number;
  warnings: string[];
  entries: HardcopyPreviewRow[];
}

export function exportAttendanceMatrixToExcel(params: {
  className: string;
  subjectName: string;
  students: Student[];
  records: AttendanceRecord[];
}): void {
  const { className, subjectName, students, records } = params;

  // Extract unique dates sorted
  const dates = Array.from(new Set(records.map((r) => r.tanggal))).sort();

  // Build matrix rows
  const rows: any[] = [];

  students.forEach((student, idx) => {
    const studentRecords = records.filter((r) => r.student_id === student.id);
    const dateMap = new Map(studentRecords.map((r) => [r.tanggal, r.status]));

    let hadirCount = 0;
    let izinCount = 0;
    let sakitCount = 0;
    let alpaCount = 0;

    const rowObj: Record<string, any> = {
      'No': idx + 1,
      'NIS': student.nis,
      'Nama Siswa': student.nama,
      'JK': student.jk,
    };

    dates.forEach((d) => {
      const status = dateMap.get(d) || '-';
      rowObj[d] = status;
      if (status === 'H') hadirCount++;
      else if (status === 'I') izinCount++;
      else if (status === 'S') sakitCount++;
      else if (status === 'A') alpaCount++;
    });

    const totalPertemuan = dates.length;
    const persentase = totalPertemuan > 0 ? Math.round((hadirCount / totalPertemuan) * 100) : 0;

    rowObj['Hadir (H)'] = hadirCount;
    rowObj['Izin (I)'] = izinCount;
    rowObj['Sakit (S)'] = sakitCount;
    rowObj['Alpa (A)'] = alpaCount;
    rowObj['% Kehadiran'] = `${persentase}%`;

    rows.push(rowObj);
  });

  const worksheet = XLSX.utils.json_to_sheet(rows);
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Rekap Absensi');

  const cleanSubject = subjectName.replace(/[^a-zA-Z0-9]/g, '_');
  const cleanClass = className.replace(/[^a-zA-Z0-9]/g, '_');
  const filename = `Rekap_Absensi_${cleanClass}_${cleanSubject}_${new Date().toISOString().substring(0, 10)}.xlsx`;

  XLSX.writeFile(workbook, filename);
}

export function exportGradeRecapToExcel(params: {
  className: string;
  subjectName: string;
  students: Student[];
  activities: GradeActivity[];
  grades: GradeValue[];
}): void {
  const { className, subjectName, students, activities, grades } = params;

  const rows: any[] = [];

  students.forEach((student, idx) => {
    const rowObj: Record<string, any> = {
      'No': idx + 1,
      'NIS': student.nis,
      'Nama Siswa': student.nama,
    };

    let numericSum = 0;
    let numericCount = 0;

    activities.forEach((act) => {
      const g = grades.find((v) => v.activity_id === act.id && v.student_id === student.id);
      const val = g ? g.nilai : '-';
      rowObj[act.nama_kegiatan] = val;

      if (act.tipe_skala === 'angka') {
        const num = parseFloat(val);
        if (!isNaN(num)) {
          numericSum += num;
          numericCount++;
        }
      }
    });

    if (numericCount > 0) {
      rowObj['Rata-Rata Angka'] = Math.round((numericSum / numericCount) * 10) / 10;
    }

    rows.push(rowObj);
  });

  const worksheet = XLSX.utils.json_to_sheet(rows);
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Rekap Nilai');

  const cleanSubject = subjectName.replace(/[^a-zA-Z0-9]/g, '_');
  const cleanClass = className.replace(/[^a-zA-Z0-9]/g, '_');
  const filename = `Rekap_Nilai_${cleanClass}_${cleanSubject}_${new Date().toISOString().substring(0, 10)}.xlsx`;

  XLSX.writeFile(workbook, filename);
}

// Generate Hardcopy Upload Template (PRD 6.7)
export function generateHardcopyTemplate(className: string, students: Student[], tanggal: string): void {
  const rows = students.map((s, idx) => ({
    'No': idx + 1,
    'NIS': s.nis,
    'Nama Siswa': s.nama,
    'JK': s.jk,
    'Status (H/I/S/A)': 'H',
    'Catatan (Opsional)': '',
  }));

  const worksheet = XLSX.utils.json_to_sheet(rows);
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Template Absen');

  const filename = `Template_Absen_${className.replace(/\s+/g, '_')}_${tanggal}.xlsx`;
  XLSX.writeFile(workbook, filename);
}

// Parse and Preview Hardcopy Upload (PRD 6.7)
export async function parseHardcopyUpload(
  file: File,
  validStudents: Student[],
  tanggalInput: string
): Promise<HardcopyPreviewResult> {
  const buffer = await file.arrayBuffer();
  const workbook = XLSX.read(buffer, { type: 'array' });
  const firstSheetName = workbook.SheetNames[0];
  const worksheet = workbook.Sheets[firstSheetName];
  const rawRows: any[] = XLSX.utils.sheet_to_json(worksheet);

  const warnings: string[] = [];
  const entries: HardcopyPreviewRow[] = [];
  const validNisSet = new Map(validStudents.map((s) => [s.nis.trim().toLowerCase(), s]));

  let validCount = 0;
  let invalidCount = 0;

  for (let i = 0; i < rawRows.length; i++) {
    const row = rawRows[i];
    // Find NIS column dynamically
    const rawNis = (row['NIS'] || row['nis'] || row['Nomor Induk'] || '').toString().trim();
    const rawNama = (row['Nama Siswa'] || row['Nama'] || row['nama'] || '').toString().trim();
    const rawStatus = (row['Status (H/I/S/A)'] || row['Status'] || row['status'] || 'H').toString().trim().toUpperCase();
    const rawNotes = (row['Catatan (Opsional)'] || row['Catatan'] || row['Keterangan'] || '').toString().trim();

    if (!rawNis) continue;

    const matchedStudent = validNisSet.get(rawNis.toLowerCase());
    const isValidStudent = Boolean(matchedStudent);

    let statusNormalized: 'H' | 'I' | 'S' | 'A' | 'INVALID' = 'INVALID';
    if (['H', 'I', 'S', 'A'].includes(rawStatus)) {
      statusNormalized = rawStatus as 'H' | 'I' | 'S' | 'A';
    } else {
      warnings.push(`Baris ${i + 2}: Status '${rawStatus}' tidak dikenal (harus H, I, S, atau A).`);
    }

    if (!isValidStudent) {
      warnings.push(`Baris ${i + 2}: NIS '${rawNis}' tidak terdaftar di kelas ini.`);
      invalidCount++;
    } else if (statusNormalized === 'INVALID') {
      invalidCount++;
    } else {
      validCount++;
    }

    entries.push({
      nis: rawNis,
      nama: matchedStudent ? matchedStudent.nama : rawNama || 'Tidak Dikenal',
      isValidStudent,
      status: statusNormalized,
      rawStatus,
      notes: rawNotes,
    });
  }

  return {
    tanggal: tanggalInput,
    totalRows: entries.length,
    validRows: validCount,
    invalidRows: invalidCount,
    warnings,
    entries,
  };
}
