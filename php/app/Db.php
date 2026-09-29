<?php
declare(strict_types=1);

// ============================================================================
// Db: koneksi PDO (MySQL/MariaDB atau SQLite), skema tabel, dan helper query.
// Skema identik secara logis dengan server/db.ts versi Node.js, sehingga
// database MySQL yang sudah dipakai versi Node bisa langsung dipakai versi PHP.
// ============================================================================

final class Db
{
    private static ?PDO $pdo = null;

    public static function driver(): string
    {
        return strtolower((string) Config::get('db_driver')) === 'sqlite' ? 'sqlite' : 'mysql';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (self::driver() === 'sqlite') {
            $path = (string) Config::get('sqlite_path') ?: APP_DIR . '/storage/absensi.sqlite3';
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Config::get('mysql_host'), Config::get('mysql_port'), Config::get('mysql_database')
            );
            $opts = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ];
            if (Config::get('mysql_ssl')) {
                $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                if (Config::get('mysql_ssl_ca')) {
                    $opts[PDO::MYSQL_ATTR_SSL_CA] = (string) Config::get('mysql_ssl_ca');
                } else {
                    $opts[PDO::MYSQL_ATTR_SSL_CIPHER] = 'DEFAULT';
                }
            }
            $pdo = new PDO($dsn, (string) Config::get('mysql_user'), (string) Config::get('mysql_password'), $opts);
        }
        self::$pdo = $pdo;
        self::ensureSchema();
        return $pdo;
    }

    private static function bind(PDOStatement $st, array $params): void
    {
        foreach (array_values($params) as $i => $v) {
            $type = match (true) {
                $v === null => PDO::PARAM_NULL,
                is_int($v), is_bool($v) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($i + 1, is_bool($v) ? (int) $v : $v, $type);
        }
    }

    /** INSERT/UPDATE/DELETE. Mengembalikan jumlah baris terpengaruh. */
    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        self::bind($st, $params);
        $st->execute();
        return $st->rowCount();
    }

    public static function get(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        self::bind($st, $params);
        $st->execute();
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        self::bind($st, $params);
        $st->execute();
        return $st->fetchAll();
    }

    /** Jalankan $fn dalam transaksi; aman dipanggil bersarang (transaksi terluar yang commit). */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        if (self::driver() === 'sqlite') {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $pdo->beginTransaction();
        }
        try {
            $result = $fn();
            $pdo->inTransaction() && $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------------
    private static function ensureSchema(): void
    {
        // Tabel login_attempts adalah yang terbaru; kalau belum ada, jalankan
        // seluruh skema (CREATE TABLE IF NOT EXISTS, aman untuk DB yang sudah ada
        // dari versi Node.js).
        try {
            self::$pdo->query('SELECT 1 FROM login_attempts LIMIT 1');
            return;
        } catch (PDOException) {
            // lanjut buat skema
        }
        $schema = self::driver() === 'sqlite' ? self::SCHEMA_SQLITE : self::SCHEMA_MYSQL;
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            self::$pdo->exec($stmt);
        }
        self::bootstrapAdmin();
    }

    /** Buat akun Administrator pertama dari config (hanya jika tabel users masih kosong). */
    private static function bootstrapAdmin(): void
    {
        $count = (int) (self::get('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
        if ($count > 0) {
            return;
        }
        $username = strtolower(trim((string) Config::get('admin_username')));
        $password = (string) Config::get('admin_password');
        if ($username === '' || strlen($password) < 10) {
            error_log('[DB] Database kosong. Isi admin_username dan admin_password (min. 10 karakter) di app/config.php untuk membuat akun Administrator pertama.');
            return;
        }
        self::run(
            "INSERT INTO users (id, username, password_hash, nama, kelas_wali_id, foto_profil_url, is_active, created_at, roles, subjects, classes)
             VALUES (1, ?, ?, ?, NULL, NULL, 1, ?, ?, '[]', '[]')",
            [$username, Util::hashPassword($password), (string) Config::get('admin_nama'), Util::sqlNow(), json_encode(['superadmin', 'admin'])]
        );
        self::run("INSERT INTO sequences (entity, value) VALUES ('users', 10)");
        if (!self::get('SELECT id FROM school_settings WHERE id = 1')) {
            self::run(
                'INSERT INTO school_settings (id, school_name, logo_url, tahun_ajaran, semester, kepsek_nama, bk_nama, backup_retention_weeks) VALUES (1, ?, ?, ?, ?, ?, ?, ?)',
                [(string) Config::get('school_name'), '', '', 'Ganjil', '', '', 8]
            );
        }
        error_log("[DB] Akun Administrator pertama \"$username\" dibuat. Hapus admin_password dari config setelah login pertama.");
    }

    private const SCHEMA_SQLITE = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, password_hash TEXT, nama TEXT NOT NULL,
  kelas_wali_id INTEGER, foto_profil_url TEXT, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL,
  roles TEXT NOT NULL DEFAULT '[]', subjects TEXT NOT NULL DEFAULT '[]', classes TEXT NOT NULL DEFAULT '[]'
);
CREATE TABLE IF NOT EXISTS classes (
  id INTEGER PRIMARY KEY, name TEXT NOT NULL, jurusan TEXT, angkatan TEXT, tahun_ajaran TEXT, semester TEXT
);
CREATE TABLE IF NOT EXISTS subjects (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS students (
  id INTEGER PRIMARY KEY, nis TEXT NOT NULL, nama TEXT NOT NULL, jk TEXT NOT NULL, class_id INTEGER NOT NULL,
  status TEXT NOT NULL, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS attendance (
  id INTEGER PRIMARY KEY, student_id INTEGER NOT NULL, class_id INTEGER NOT NULL, subject_id INTEGER,
  tanggal TEXT NOT NULL, status TEXT NOT NULL, recorded_by INTEGER, recorded_via TEXT, notes TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL, created_at_millis INTEGER,
  UNIQUE(student_id, subject_id, tanggal)
);
CREATE TABLE IF NOT EXISTS grade_activities (
  id TEXT PRIMARY KEY, teacher_id INTEGER NOT NULL, subject_id INTEGER NOT NULL, class_id INTEGER NOT NULL,
  nama_kegiatan TEXT NOT NULL, tanggal_kegiatan TEXT, tipe_skala TEXT NOT NULL, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS grade_values (
  activity_id TEXT NOT NULL, student_id INTEGER NOT NULL, nilai TEXT NOT NULL, PRIMARY KEY (activity_id, student_id)
);
CREATE TABLE IF NOT EXISTS teacher_subject_class_pairing (
  user_id INTEGER NOT NULL, subject_id INTEGER NOT NULL, class_id INTEGER NOT NULL,
  PRIMARY KEY (user_id, subject_id, class_id)
);
CREATE TABLE IF NOT EXISTS ketua_kelas_tokens (
  token TEXT PRIMARY KEY, class_id INTEGER NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL,
  created_by INTEGER, expires_at TEXT, expires_at_millis INTEGER
);
CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT, timestamp TEXT NOT NULL, action TEXT NOT NULL, module TEXT NOT NULL,
  actor TEXT, details TEXT
);
CREATE TABLE IF NOT EXISTS sequences (entity TEXT PRIMARY KEY, value INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS school_settings (
  id INTEGER PRIMARY KEY CHECK (id = 1), school_name TEXT, logo_url TEXT, tahun_ajaran TEXT, semester TEXT,
  kepsek_nama TEXT, bk_nama TEXT, backup_retention_weeks INTEGER, last_backup_date TEXT, last_backup_status TEXT
);
CREATE TABLE IF NOT EXISTS sessions (
  token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, created_at TEXT NOT NULL, expires_at_millis INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS parent_access_tokens (
  token TEXT PRIMARY KEY, student_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'aktif',
  created_at TEXT NOT NULL, created_by INTEGER, revoked_at TEXT
);
CREATE TABLE IF NOT EXISTS login_attempts (k TEXT PRIMARY KEY, cnt INTEGER NOT NULL, first_at INTEGER NOT NULL)
SQL;

    private const SCHEMA_MYSQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY, username VARCHAR(191) UNIQUE NOT NULL, password_hash VARCHAR(255), nama VARCHAR(191) NOT NULL,
  kelas_wali_id INT, foto_profil_url VARCHAR(500), is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at VARCHAR(32) NOT NULL, roles TEXT NOT NULL, subjects TEXT NOT NULL, classes TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS classes (
  id INT PRIMARY KEY, name VARCHAR(191) NOT NULL, jurusan VARCHAR(191), angkatan VARCHAR(32),
  tahun_ajaran VARCHAR(32), semester VARCHAR(32)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS subjects (id INT PRIMARY KEY, name VARCHAR(191) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS students (
  id INT PRIMARY KEY, nis VARCHAR(64) NOT NULL, nama VARCHAR(191) NOT NULL, jk VARCHAR(4) NOT NULL,
  class_id INT NOT NULL, status VARCHAR(32) NOT NULL, created_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS attendance (
  id INT PRIMARY KEY, student_id INT NOT NULL, class_id INT NOT NULL, subject_id INT NULL,
  tanggal VARCHAR(10) NOT NULL, status VARCHAR(4) NOT NULL, recorded_by INT, recorded_via VARCHAR(64), notes TEXT,
  created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, created_at_millis BIGINT,
  UNIQUE KEY uniq_attendance (student_id, subject_id, tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS grade_activities (
  id VARCHAR(64) PRIMARY KEY, teacher_id INT NOT NULL, subject_id INT NOT NULL, class_id INT NOT NULL,
  nama_kegiatan VARCHAR(191) NOT NULL, tanggal_kegiatan VARCHAR(10), tipe_skala VARCHAR(16) NOT NULL,
  created_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS grade_values (
  activity_id VARCHAR(64) NOT NULL, student_id INT NOT NULL, nilai VARCHAR(16) NOT NULL,
  PRIMARY KEY (activity_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS teacher_subject_class_pairing (
  user_id INT NOT NULL, subject_id INT NOT NULL, class_id INT NOT NULL, PRIMARY KEY (user_id, subject_id, class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ketua_kelas_tokens (
  token VARCHAR(128) PRIMARY KEY, class_id INT NOT NULL, status VARCHAR(16) NOT NULL, created_at VARCHAR(32) NOT NULL,
  created_by INT, expires_at VARCHAR(32), expires_at_millis BIGINT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS audit_log (
  id INT PRIMARY KEY AUTO_INCREMENT, timestamp VARCHAR(32) NOT NULL, action VARCHAR(191) NOT NULL,
  module VARCHAR(64) NOT NULL, actor VARCHAR(191), details TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sequences (entity VARCHAR(64) PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS school_settings (
  id INT PRIMARY KEY, school_name VARCHAR(191), logo_url VARCHAR(500), tahun_ajaran VARCHAR(32), semester VARCHAR(32),
  kepsek_nama VARCHAR(191), bk_nama VARCHAR(191), backup_retention_weeks INT, last_backup_date VARCHAR(32),
  last_backup_status VARCHAR(16)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sessions (
  token VARCHAR(128) PRIMARY KEY, user_id INT NOT NULL, created_at VARCHAR(32) NOT NULL, expires_at_millis BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS parent_access_tokens (
  token VARCHAR(128) PRIMARY KEY, student_id INT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'aktif',
  created_at VARCHAR(32) NOT NULL, created_by INT, revoked_at VARCHAR(32)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS login_attempts (k VARCHAR(191) PRIMARY KEY, cnt INT NOT NULL, first_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
}
