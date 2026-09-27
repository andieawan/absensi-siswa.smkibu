import { Student, AttendanceRecord, GradeActivity, GradeValue } from '../types';

export interface SpreadsheetCreationResult {
  spreadsheetId: string;
  spreadsheetUrl: string;
  title: string;
}

export class GoogleSheetsService {
  /**
   * Helper to make authenticated requests to Google Sheets API
   */
  private static async fetchWithAuth(
    url: string,
    options: RequestInit,
    accessToken: string
  ): Promise<any> {
    const res = await fetch(url, {
      ...options,
      headers: {
        ...options.headers,
        Authorization: `Bearer ${accessToken}`,
        'Content-Type': 'application/json',
      },
    });

    if (!res.ok) {
      const errorData = await res.json().catch(() => ({}));
      throw new Error(
        errorData.error?.message || `Google Sheets API Error (${res.status}): ${res.statusText}`
      );
    }

    return await res.json();
  }

  /**
   * Create and populate a Google Sheet with attendance matrix
   */
  static async exportAttendanceToGoogleSheets(params: {
    className: string;
    subjectName: string;
    students: Student[];
    records: AttendanceRecord[];
    accessToken: string;
  }): Promise<SpreadsheetCreationResult> {
    const { className, subjectName, students, records, accessToken } = params;
    const title = `Rekap Presensi ${className} - ${subjectName} (${new Date().toISOString().substring(0, 10)})`;

    // 1. Create Spreadsheet
    const createRes = await this.fetchWithAuth(
      'https://sheets.googleapis.com/v4/spreadsheets',
      {
        method: 'POST',
        body: JSON.stringify({
          properties: {
            title,
          },
          sheets: [
            {
              properties: {
                title: 'Rekap Presensi',
                gridProperties: {
                  frozenRowCount: 4,
                  frozenColumnCount: 3,
                },
              },
            },
          ],
        }),
      },
      accessToken
    );

    const spreadsheetId = createRes.spreadsheetId;
    const spreadsheetUrl = createRes.spreadsheetUrl;

    // 2. Prepare Data Rows
    const dates = Array.from(new Set(records.map((r) => r.tanggal))).sort();

    const values: any[][] = [
      ['REKAP PRESENSI SISWA — SMK NEGERI 1 PRESTASI BANGSA'],
      [`Kelas: ${className} | Mata Pelajaran: ${subjectName} | Diekspor: ${new Date().toLocaleString('id-ID')}`],
      [''],
      ['No', 'NIS', 'Nama Siswa', 'JK', ...dates, 'Hadir (H)', 'Izin (I)', 'Sakit (S)', 'Alpa (A)', '% Kehadiran'],
    ];

    students.forEach((student, idx) => {
      const studentRecords = records.filter((r) => r.student_id === student.id);
      const dateMap = new Map(studentRecords.map((r) => [r.tanggal, r.status]));

      let hadir = 0, izin = 0, sakit = 0, alpa = 0;
      const dateValues: string[] = [];

      dates.forEach((d) => {
        const st = dateMap.get(d) || '-';
        dateValues.push(st);
        if (st === 'H') hadir++;
        else if (st === 'I') izin++;
        else if (st === 'S') sakit++;
        else if (st === 'A') alpa++;
      });

      const totalMeetings = dates.length;
      const pct = totalMeetings > 0 ? `${Math.round((hadir / totalMeetings) * 100)}%` : '0%';

      values.push([
        idx + 1,
        student.nis,
        student.nama,
        student.jk,
        ...dateValues,
        hadir,
        izin,
        sakit,
        alpa,
        pct,
      ]);
    });

    // 3. Write data into spreadsheet
    await this.fetchWithAuth(
      `https://sheets.googleapis.com/v4/spreadsheets/${spreadsheetId}/values/Rekap Presensi!A1?valueInputOption=USER_ENTERED`,
      {
        method: 'PUT',
        body: JSON.stringify({
          range: 'Rekap Presensi!A1',
          majorDimension: 'ROWS',
          values,
        }),
      },
      accessToken
    );

    return {
      spreadsheetId,
      spreadsheetUrl,
      title,
    };
  }

  /**
   * Create and populate a Google Sheet with grades matrix
   */
  static async exportGradesToGoogleSheets(params: {
    className: string;
    subjectName: string;
    students: Student[];
    activities: GradeActivity[];
    grades: GradeValue[];
    accessToken: string;
  }): Promise<SpreadsheetCreationResult> {
    const { className, subjectName, students, activities, grades, accessToken } = params;
    const title = `Rekap Nilai ${className} - ${subjectName} (${new Date().toISOString().substring(0, 10)})`;

    // 1. Create Spreadsheet
    const createRes = await this.fetchWithAuth(
      'https://sheets.googleapis.com/v4/spreadsheets',
      {
        method: 'POST',
        body: JSON.stringify({
          properties: {
            title,
          },
          sheets: [
            {
              properties: {
                title: 'Rekap Nilai',
                gridProperties: {
                  frozenRowCount: 4,
                  frozenColumnCount: 3,
                },
              },
            },
          ],
        }),
      },
      accessToken
    );

    const spreadsheetId = createRes.spreadsheetId;
    const spreadsheetUrl = createRes.spreadsheetUrl;

    // 2. Prepare Data
    const actHeaders = activities.map((a) => `${a.nama_kegiatan} (${a.tipe_skala})`);

    const values: any[][] = [
      ['REKAP NILAI SISWA — SMK NEGERI 1 PRESTASI BANGSA'],
      [`Kelas: ${className} | Mata Pelajaran: ${subjectName} | Tanggal Ekspor: ${new Date().toLocaleString('id-ID')}`],
      [''],
      ['No', 'NIS', 'Nama Siswa', ...actHeaders, 'Rata-Rata (Angka)'],
    ];

    students.forEach((student, idx) => {
      let numSum = 0;
      let numCount = 0;
      const actScores: string[] = [];

      activities.forEach((act) => {
        const g = grades.find((v) => v.activity_id === act.id && v.student_id === student.id);
        const val = g ? g.nilai : '-';
        actScores.push(val);

        if (act.tipe_skala === 'angka') {
          const num = parseFloat(val);
          if (!isNaN(num)) {
            numSum += num;
            numCount++;
          }
        }
      });

      const avg = numCount > 0 ? (Math.round((numSum / numCount) * 10) / 10).toString() : '-';

      values.push([
        idx + 1,
        student.nis,
        student.nama,
        ...actScores,
        avg,
      ]);
    });

    // 3. Write data into spreadsheet
    await this.fetchWithAuth(
      `https://sheets.googleapis.com/v4/spreadsheets/${spreadsheetId}/values/Rekap Nilai!A1?valueInputOption=USER_ENTERED`,
      {
        method: 'PUT',
        body: JSON.stringify({
          range: 'Rekap Nilai!A1',
          majorDimension: 'ROWS',
          values,
        }),
      },
      accessToken
    );

    return {
      spreadsheetId,
      spreadsheetUrl,
      title,
    };
  }

  /**
   * Create full backup in Google Sheets (Multi-tab)
   */
  static async exportFullBackupToGoogleSheets(params: {
    students: Student[];
    classes: any[];
    subjects: any[];
    attendances: AttendanceRecord[];
    activities: GradeActivity[];
    grades: GradeValue[];
    accessToken: string;
  }): Promise<SpreadsheetCreationResult> {
    const { students, classes, subjects, attendances, activities, grades, accessToken } = params;
    const title = `Backup Database go_absen_siswa (${new Date().toISOString().substring(0, 10)})`;

    // 1. Create Spreadsheet with multiple tabs
    const createRes = await this.fetchWithAuth(
      'https://sheets.googleapis.com/v4/spreadsheets',
      {
        method: 'POST',
        body: JSON.stringify({
          properties: {
            title,
          },
          sheets: [
            { properties: { title: 'Master Siswa' } },
            { properties: { title: 'Master Kelas & Mapel' } },
            { properties: { title: 'Log Absensi' } },
            { properties: { title: 'Kegiatan Nilai' } },
          ],
        }),
      },
      accessToken
    );

    const spreadsheetId = createRes.spreadsheetId;
    const spreadsheetUrl = createRes.spreadsheetUrl;

    // Sheet 1: Master Siswa
    const studentRows = [
      ['ID', 'NIS', 'Nama Siswa', 'JK', 'Class ID', 'Status', 'Tanggal Daftar'],
      ...students.map((s) => [s.id, s.nis, s.nama, s.jk, s.class_id, s.status, s.created_at]),
    ];

    // Sheet 2: Master Kelas & Mapel
    const classRows = [
      ['ID Kelas', 'Nama Kelas', 'Jurusan', 'Angkatan', 'Tahun Ajaran'],
      ...classes.map((c) => [c.id, c.name, c.jurusan, c.angkatan, c.tahun_ajaran]),
      [''],
      ['ID Mapel', 'Nama Mata Pelajaran'],
      ...subjects.map((sub) => [sub.id, sub.name]),
    ];

    // Sheet 3: Log Absensi
    const attRows = [
      ['ID', 'Student ID', 'Class ID', 'Subject ID', 'Tanggal', 'Status', 'Pencatat ID', 'Metode Catat', 'Catatan'],
      ...attendances.map((a) => [
        a.id,
        a.student_id,
        a.class_id,
        a.subject_id ?? 'Harian',
        a.tanggal,
        a.status,
        a.recorded_by,
        a.recorded_via,
        a.notes || '',
      ]),
    ];

    // Sheet 4: Kegiatan Nilai
    const actRows = [
      ['ID Kegiatan', 'Nama Kegiatan', 'Teacher ID', 'Class ID', 'Subject ID', 'Tanggal', 'Skala'],
      ...activities.map((act) => [
        act.id,
        act.nama_kegiatan,
        act.teacher_id,
        act.class_id,
        act.subject_id,
        act.tanggal_kegiatan,
        act.tipe_skala,
      ]),
    ];

    // Batch update values
    await this.fetchWithAuth(
      `https://sheets.googleapis.com/v4/spreadsheets/${spreadsheetId}/values:batchUpdate`,
      {
        method: 'POST',
        body: JSON.stringify({
          valueInputOption: 'USER_ENTERED',
          data: [
            { range: 'Master Siswa!A1', values: studentRows },
            { range: 'Master Kelas & Mapel!A1', values: classRows },
            { range: 'Log Absensi!A1', values: attRows },
            { range: 'Kegiatan Nilai!A1', values: actRows },
          ],
        }),
      },
      accessToken
    );

    return {
      spreadsheetId,
      spreadsheetUrl,
      title,
    };
  }

  /**
   * Read rows from a user-supplied Google Sheet (for preview/importing)
   */
  static async readSheetValues(
    spreadsheetId: string,
    range: string,
    accessToken: string
  ): Promise<any[][]> {
    const data = await this.fetchWithAuth(
      `https://sheets.googleapis.com/v4/spreadsheets/${spreadsheetId}/values/${encodeURIComponent(range)}`,
      { method: 'GET' },
      accessToken
    );
    return data.values || [];
  }
}
