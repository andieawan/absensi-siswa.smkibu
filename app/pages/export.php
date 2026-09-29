<?php
declare(strict_types=1);

// Unduhan: Excel (absensi, nilai, template hardcopy, rekap BK) dan dump data (admin).
$user = Web::requireLogin();
$type = (string) ($_GET['type'] ?? '');
$classId = (int) ($_GET['class'] ?? 0);
$subjectRaw = $_GET['subject'] ?? '';
$subjectId = $subjectRaw === '' || $subjectRaw === null ? null : (int) $subjectRaw;
$class = $classId ? Repo::classById($classId) : null;
$clean = fn(string $s) => preg_replace('/[^A-Za-z0-9]+/', '_', $s);
$stamp = Web::today();

$fail = function (string $msg): never {
    Web::flash('error', $msg);
    Web::redirect($_SERVER['HTTP_REFERER'] ?? Web::url('dashboard'));
};
$mayView = function () use ($user, $classId, $subjectId): bool {
    if (Util::hasAdminRole($user['roles']) || in_array('kepsek', $user['roles'], true) || in_array('bk', $user['roles'], true)) return true;
    return Rules::teacherAuthorization($user['id'], $subjectId, $classId, $user['roles'])['allowed'];
};

if (in_array($type, ['absensi', 'nilai', 'template', 'bk'], true)) {
    if (!$class) $fail('Kelas tidak valid.');
    $students = array_values(array_filter(Repo::studentsAll($classId), fn($s) => $type === 'nilai' || $type === 'template' ? $s['status'] === 'aktif' : true));
    usort($students, fn($a, $b) => strcasecmp($a['nama'], $b['nama']));
}

try {
    switch ($type) {
        case 'absensi':
            if (!$mayView()) $fail('Anda tidak berwenang mengunduh data kelas/mapel ini.');
            $records = Repo::attendanceQuery($classId, $subjectId);
            $dates = array_values(array_unique(array_column($records, 'tanggal')));
            sort($dates);
            $map = [];
            foreach ($records as $r) $map[$r['student_id']][$r['tanggal']] = $r['status'];
            $subj = $subjectId ? (Db::get('SELECT name FROM subjects WHERE id = ?', [$subjectId])['name'] ?? 'Mapel') : 'Harian';
            $rows = [array_merge(['No', 'NIS', 'Nama Siswa', 'JK'], $dates, ['Hadir (H)', 'Izin (I)', 'Sakit (S)', 'Alpa (A)', '% Kehadiran'])];
            foreach ($students as $i => $s) {
                $cnt = ['H' => 0, 'I' => 0, 'S' => 0, 'A' => 0];
                $line = [$i + 1, $s['nis'], $s['nama'], $s['jk']];
                foreach ($dates as $d) {
                    $st = $map[$s['id']][$d] ?? '-';
                    if (isset($cnt[$st])) $cnt[$st]++;
                    $line[] = $st;
                }
                $pct = $dates ? (int) round($cnt['H'] / count($dates) * 100) : 0;
                array_push($line, $cnt['H'], $cnt['I'], $cnt['S'], $cnt['A'], $pct . '%');
                $rows[] = $line;
            }
            Xlsx::download("Rekap_Absensi_{$clean($class['name'])}_{$clean($subj)}_$stamp.xlsx", ['Rekap Absensi' => $rows]);

        case 'nilai':
            if ($subjectId === null || !$mayView()) $fail('Anda tidak berwenang mengunduh nilai ini.');
            $acts = array_values(array_filter(Repo::gradeActivitiesAll(), fn($a) => $a['class_id'] === $classId && $a['subject_id'] === $subjectId));
            usort($acts, fn($a, $b) => strcmp($a['tanggal_kegiatan'], $b['tanggal_kegiatan']));
            $vals = [];
            foreach (Repo::gradeValuesAll() as $v) $vals[$v['activity_id']][$v['student_id']] = $v['nilai'];
            $subj = Db::get('SELECT name FROM subjects WHERE id = ?', [$subjectId])['name'] ?? 'Mapel';
            $head = ['No', 'NIS', 'Nama Siswa'];
            foreach ($acts as $a) $head[] = $a['nama_kegiatan'];
            $head[] = 'Rata-Rata Angka';
            $rows = [$head];
            foreach ($students as $i => $s) {
                $line = [$i + 1, $s['nis'], $s['nama']];
                $sum = 0.0; $n = 0;
                foreach ($acts as $a) {
                    $v = $vals[$a['id']][$s['id']] ?? '-';
                    $line[] = $v;
                    if ($a['tipe_skala'] === 'angka' && is_numeric($v)) { $sum += (float) $v; $n++; }
                }
                $line[] = $n ? round($sum / $n, 1) : '';
                $rows[] = $line;
            }
            Xlsx::download("Rekap_Nilai_{$clean($class['name'])}_{$clean($subj)}_$stamp.xlsx", ['Rekap Nilai' => $rows]);

        case 'template':
            Web::requireAdmin();
            $tgl = (string) ($_GET['date'] ?? $stamp);
            $rows = [['No', 'NIS', 'Nama Siswa', 'JK', 'Status (H/I/S/A)', 'Catatan (Opsional)']];
            foreach ($students as $i => $s) $rows[] = [$i + 1, $s['nis'], $s['nama'], $s['jk'], 'H', ''];
            Xlsx::download("Template_Absen_{$clean($class['name'])}_$tgl.xlsx", ['Template Absen' => $rows]);

        case 'bk':
            if (!Web::isAdmin() && !Web::has('bk')) $fail('Khusus Guru BK / Administrator.');
            $rows = [['No', 'NIS', 'Nama Siswa', 'Hadir', 'Izin', 'Sakit', 'Alpa', 'Total Absen', '% Kehadiran']];
            foreach ($students as $i => $s) {
                $st = Rules::attendanceStats($s['id']);
                $rows[] = [$i + 1, $s['nis'], $s['nama'], $st['hadir'], $st['izin'], $st['sakit'], $st['alpa'], $st['izin'] + $st['sakit'] + $st['alpa'], $st['rate'] . '%'];
            }
            Xlsx::download("Rekap_BK_{$clean($class['name'])}_$stamp.xlsx", ['Rekap BK' => $rows]);

        case 'backup':
            Web::requireAdmin();
            $file = basename((string) ($_GET['file'] ?? ''));
            $path = Backup::dir() . '/' . $file;
            if (!preg_match('/^absensi_[0-9T-]+\.(sql|sqlite3)$/', $file) || !is_file($path)) $fail('Berkas backup tidak ditemukan.');
            Repo::audit('Unduh Backup', 'Sistem', $user['nama'], $file);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $file . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;

        case 'json':
            Web::requireAdmin();
            $dump = [
                'exported_at' => Util::iso(), 'classes' => Repo::classesAll(), 'subjects' => Repo::subjectsAll(), 'students' => Repo::studentsAll(),
                'users' => array_map([Api::class, 'stripHash'], Repo::usersAll()), 'pairings' => Repo::pairingsAll(), 'settings' => Repo::settingsGet(),
                'attendance' => Repo::attendanceQuery(), 'gradeActivities' => Repo::gradeActivitiesAll(), 'gradeValues' => Repo::gradeValuesAll(),
            ];
            Repo::audit('Unduh Data JSON', 'Sistem', $user['nama'], 'Ekspor seluruh data (tanpa password)');
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="absensi_' . $stamp . '.json"');
            echo json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
    }
} catch (RuntimeException $e) {
    if ($e instanceof UserError || str_contains($e->getMessage(), 'zip')) $fail($e->getMessage());
    throw $e;
}
http_response_code(404);
echo 'Jenis unduhan tidak dikenal.';
