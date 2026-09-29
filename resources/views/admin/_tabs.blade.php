<div class="head"><div><h1>Admin Panel</h1><small>Kelola akun, data master, otorisasi, impor, dan backup.</small></div>
    <div class="seg seg-scroll" role="navigation" aria-label="Bagian Admin Panel">
        @foreach(['admin.teachers' => 'Akun Guru', 'admin.students' => 'Data Siswa', 'admin.master' => 'Kelas & Mapel', 'admin.pairings' => 'Pasangan Mapel', 'admin.hardcopy' => 'Upload Hardcopy', 'admin.logs' => 'Log Aktivitas', 'admin.settings' => 'Pengaturan & Backup'] as $r => $l)
            <a href="{{ route($r) }}" @class(['on' => request()->routeIs($r.'*')])>{{ $l }}</a>
        @endforeach
    </div>
</div>
