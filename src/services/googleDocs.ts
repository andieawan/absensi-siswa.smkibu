import { Student, AttendanceRecord, GradeActivity, GradeValue, SchoolSettings } from '../types';

export interface DocumentCreationResult {
  documentId: string;
  documentUrl: string;
  title: string;
}

export class GoogleDocsService {
  private static async fetchWithAuth(url: string, options: RequestInit, accessToken: string): Promise<any> {
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
        errorData.error?.message || `Google Docs API Error (${res.status}): ${res.statusText}`
      );
    }

    return await res.json();
  }

  /**
   * Helper to create an empty Google Doc and populate text
   */
  private static async createAndPopulateDoc(
    title: string,
    content: string,
    accessToken: string
  ): Promise<DocumentCreationResult> {
    // 1. Create document
    const createRes = await this.fetchWithAuth(
      'https://docs.googleapis.com/v1/documents',
      {
        method: 'POST',
        body: JSON.stringify({ title }),
      },
      accessToken
    );

    const documentId = createRes.documentId;
    const documentUrl = `https://docs.google.com/document/d/${documentId}/edit`;

    // 2. Insert content via batchUpdate
    await this.fetchWithAuth(
      `https://docs.googleapis.com/v1/documents/${documentId}:batchUpdate`,
      {
        method: 'POST',
        body: JSON.stringify({
          requests: [
            {
              insertText: {
                text: content,
                location: { index: 1 },
              },
            },
          ],
        }),
      },
      accessToken
    );

    return {
      documentId,
      documentUrl,
      title,
    };
  }

  /**
   * 1. Surat Peringatan & Laporan Kehadiran Khusus Siswa (Official Warning / Counseling Letter)
   */
  static async generateStudentWarningLetter(params: {
    student: Student;
    className: string;
    records: AttendanceRecord[];
    settings: SchoolSettings;
    accessToken: string;
  }): Promise<DocumentCreationResult> {
    const { student, className, records, settings, accessToken } = params;
    const studentRecords = records.filter((r) => r.student_id === student.id);
    const alpaList = studentRecords.filter((r) => r.status === 'A');
    const izinList = studentRecords.filter((r) => r.status === 'I');
    const sakitList = studentRecords.filter((r) => r.status === 'S');

    const title = `Surat Peringatan Presensi - ${student.nama} (${student.nis})`;

    let doc = '';
    doc += `PEMERINTAH DAERAH PROVINSI / DINAS PENDIDIKAN\n`;
    doc += `${settings.school_name.toUpperCase()}\n`;
    doc += `Alamat: Jl. Pendidikan No. 45, Kota Prestasi | Telp: (021) 88997700\n`;
    doc += `================================================================================\n\n`;

    doc += `Nomor       : 421.5 / SP-BK / ${new Date().getFullYear()}\n`;
    doc += `Lampiran    : 1 (Satu) Berkas Riwayat Kehadiran\n`;
    doc += `Perihal     : Peringatan Ketidakhadiran & Bimbingan Konseling Siswa\n\n`;

    doc += `Kepada Yth.\n`;
    doc += `Bapak/Ibu Orang Tua / Wali dari Ananda:\n\n`;

    doc += `Nama Siswa         : ${student.nama}\n`;
    doc += `Nomor Induk (NIS)  : ${student.nis}\n`;
    doc += `Kelas / Program    : ${className}\n`;
    doc += `Tahun Pelajaran    : ${settings.tahun_ajaran} (${settings.semester})\n\n`;

    doc += `Dengan hormat,\n`;
    doc += `Berdasarkan rekapitulasi data presensi elektronik pada sistem informasi sekolah go_absen_siswa,\n`;
    doc += `dengan ini kami memberitahukan bahwa putra/putri Bapak/Ibu tercatat mengalami ketidakhadiran dengan rincian sbb:\n\n`;

    doc += `   1. Alpa (Tanpa Keterangan) : ${alpaList.length} kali pertemuan\n`;
    doc += `   2. Izin Resmi              : ${izinList.length} kali pertemuan\n`;
    doc += `   3. Sakit                   : ${sakitList.length} kali pertemuan\n`;
    doc += `   Total Ketidakhadiran       : ${alpaList.length + izinList.length + sakitList.length} kali pertemuan\n\n`;

    if (alpaList.length > 0) {
      doc += `Rincian Tanggal Alpa:\n`;
      alpaList.forEach((a, i) => {
        doc += `   - Tanggal: ${a.tanggal} | Catatan: ${a.notes || 'Tanpa keterangan resmi'}\n`;
      });
      doc += `\n`;
    }

    doc += `Mengingat pentingnya pemenuhan syarat minimal kehadiran (85%) untuk evaluasi kenaikan kelas dan kelulusan,\n`;
    doc += `kami mengharapkan perhatian dan kerja sama Bapak/Ibu dalam mengawasi serta membimbing Ananda.\n\n`;

    doc += `Demikian surat pemberitahuan ini kami sampaikan, atas perhatian dan kerja sama yang baik kami ucapkan terima kasih.\n\n\n`;

    doc += `Kota Prestasi, ${new Date().toLocaleDateString('id-ID', { dateStyle: 'long' })}\n\n`;
    doc += `Mengetahui,\n`;
    doc += `Kepala Sekolah,                                   Guru Bimbingan Konseling (BK),\n\n\n\n\n`;
    doc += `${settings.kepsek_nama}                     ${settings.bk_nama}\n`;
    doc += `NIP. 19750815 199903 2 001                        NIP. 19880412 201101 2 004\n`;

    return this.createAndPopulateDoc(title, doc, accessToken);
  }

  /**
   * 2. Laporan Rekapitulasi Presensi & Nilai Semester (Formal Academic Summary Report)
   */
  static async generateSemesterReportDoc(params: {
    className: string;
    subjectName: string;
    students: Student[];
    records: AttendanceRecord[];
    activities: GradeActivity[];
    grades: GradeValue[];
    settings: SchoolSettings;
    accessToken: string;
  }): Promise<DocumentCreationResult> {
    const { className, subjectName, students, records, activities, grades, settings, accessToken } = params;

    const title = `Laporan Resmi Akademik & Presensi ${className} - ${subjectName}`;

    let doc = '';
    doc += `LAPORAN EVALUASI PRESENSI & CAPAIAN PEMBELAJARAN SISWA\n`;
    doc += `${settings.school_name.toUpperCase()}\n`;
    doc += `Tahun Ajaran ${settings.tahun_ajaran} - Semester ${settings.semester}\n`;
    doc += `================================================================================\n\n`;

    doc += `Mata Pelajaran    : ${subjectName}\n`;
    doc += `Kelas             : ${className}\n`;
    doc += `Waktu Cetak       : ${new Date().toLocaleString('id-ID')}\n\n`;

    // Attendance stats
    const totalEntries = records.length;
    const hadir = records.filter((r) => r.status === 'H').length;
    const pct = totalEntries > 0 ? ((hadir / totalEntries) * 100).toFixed(1) : '100';

    doc += `I. RINGKASAN KEHADIRAN KELAS\n`;
    doc += `   - Rata-rata Kehadiran Kelas : ${pct}%\n`;
    doc += `   - Total Siswa Aktif         : ${students.length} orang\n`;
    doc += `   - Jumlah Sesi Pertemuan     : ${new Set(records.map((r) => r.tanggal)).size} sesi\n\n`;

    doc += `II. DAFTAR PERINGKAT CAPAIAN SISWA\n`;
    doc += `No.  NIS             NAMA SISWA                  KEHADIRAN   NILAI RATA-RATA\n`;
    doc += `--------------------------------------------------------------------------------\n`;

    students.forEach((s, idx) => {
      const sRecords = records.filter((r) => r.student_id === s.id);
      const hCount = sRecords.filter((r) => r.status === 'H').length;
      const attRate = sRecords.length > 0 ? Math.round((hCount / sRecords.length) * 100) : 100;

      // Avg grade
      let sum = 0;
      let count = 0;
      activities.forEach((act) => {
        if (act.tipe_skala === 'angka') {
          const v = grades.find((g) => g.activity_id === act.id && g.student_id === s.id);
          if (v) {
            const num = parseFloat(v.nilai);
            if (!isNaN(num)) {
              sum += num;
              count++;
            }
          }
        }
      });
      const avg = count > 0 ? (sum / count).toFixed(1) : '-';

      const noStr = (idx + 1).toString().padEnd(4, ' ');
      const nisStr = s.nis.padEnd(16, ' ');
      const namaStr = s.nama.substring(0, 26).padEnd(28, ' ');
      const attStr = `${attRate}%`.padEnd(12, ' ');
      const avgStr = avg;

      doc += `${noStr} ${nisStr} ${namaStr} ${attStr} ${avgStr}\n`;
    });

    doc += `--------------------------------------------------------------------------------\n\n`;

    doc += `III. CATATAN & REKOMENDASI PEMBELAJARAN\n`;
    doc += `Peserta didik dengan tingkat kehadiran di bawah 85% atau capaian nilai belum tuntas\n`;
    doc += `dijadwalkan mengikuti program remedial serta sesi pendampingan konseling.\n\n\n`;

    doc += `Guru Pengampu Mata Pelajaran,\n\n\n\n`;
    doc += `(......................................................)\n`;
    doc += `NIP. \n`;

    return this.createAndPopulateDoc(title, doc, accessToken);
  }

  /**
   * 3. Surat Undangan Panggilan Orang Tua Siswa (Parent Summons Official Letter)
   */
  static async generateParentSummonsLetter(params: {
    student: Student;
    className: string;
    settings: SchoolSettings;
    tanggalPertemuan: string;
    jamPertemuan: string;
    ruangan: string;
    alasan: string;
    accessToken: string;
  }): Promise<DocumentCreationResult> {
    const { student, className, settings, tanggalPertemuan, jamPertemuan, ruangan, alasan, accessToken } = params;

    const title = `Surat Panggilan Orang Tua - ${student.nama}`;

    let doc = '';
    doc += `PEMERINTAH DAERAH PROVINSI / DINAS PENDIDIKAN\n`;
    doc += `${settings.school_name.toUpperCase()}\n`;
    doc += `LAYANAN BIMBINGAN DAN KONSELING (BK)\n`;
    doc += `Jl. Pendidikan No. 45 Kota Prestasi | Telp: (021) 88997700\n`;
    doc += `================================================================================\n\n`;

    doc += `SURAT PANGGILAN ORANG TUA / WALI SISWA\n`;
    doc += `Nomor: 421.7 / BK-PANGGILAN / ${new Date().getFullYear()}\n\n`;

    doc += `Kepada Yth.\n`;
    doc += `Bapak / Ibu Orang Tua / Wali dari:\n`;
    doc += `Nama Siswa   : ${student.nama}\n`;
    doc += `NIS          : ${student.nis}\n`;
    doc += `Kelas        : ${className}\n\n`;

    doc += `Dengan hormat,\n`;
    doc += `Sehubungan dengan adanya hal penting terkait evaluasi kedisiplinan dan capaian belajar siswa (${alasan}),\n`;
    doc += `maka melalui surat ini kami mengharapkan kehadiran Bapak/Ibu pada:\n\n`;

    doc += `   Hari / Tanggal : ${tanggalPertemuan}\n`;
    doc += `   Waktu          : Pukul ${jamPertemuan} WIB\n`;
    doc += `   Tempat         : Ruang ${ruangan}\n`;
    doc += `   Bertemu Dengan : Guru Bimbingan Konseling & Wali Kelas\n\n`;

    doc += `Mengingat sangat pentingnya koordinasi ini demi masa depan belajar putra/putri Bapak/Ibu,\n`;
    doc += `kami sangat mengharapkan kehadiran Bapak/Ibu tepat pada waktu yang telah ditentukan.\n\n`;

    doc += `Atas perhatian dan kerja samanya, kami ucapkan terima kasih.\n\n\n`;

    doc += `Kota Prestasi, ${new Date().toLocaleDateString('id-ID', { dateStyle: 'long' })}\n\n`;
    doc += `Mengetahui,\n`;
    doc += `Kepala Sekolah,                                   Guru Bimbingan Konseling,\n\n\n\n\n`;
    doc += `${settings.kepsek_nama}                     ${settings.bk_nama}\n`;
    doc += `NIP. 19750815 199903 2 001                        NIP. 19880412 201101 2 004\n`;

    return this.createAndPopulateDoc(title, doc, accessToken);
  }
}
