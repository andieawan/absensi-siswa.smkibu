// ============================================================================
// Worker thread: menjalankan query MySQL (mysql2/promise) secara ASINKRON,
// tapi dipanggil dari thread utama secara SINKRON lewat `synckit`.
// ============================================================================
// `node:sqlite` (DatabaseSync) bersifat sinkron, sedangkan semua driver MySQL
// untuk Node.js (termasuk mysql2) bersifat asinkron murni — tidak ada driver
// MySQL sinkron resmi. Supaya `server/db.ts` & seluruh `server.ts` TIDAK perlu
// diubah jadi async di 80+ titik pemanggilan, worker ini menjalankan mysql2
// di thread terpisah, dan `synckit.createSyncFn()` di db.ts memblokir thread
// utama (lewat Atomics.wait) sampai hasil query ini kembali. Perilakunya sama
// seperti panggilan sinkron biasa dari sudut pandang server.ts.
import { runAsWorker } from 'synckit';
import mysql from 'mysql2/promise';

let poolPromise: Promise<mysql.Pool> | null = null;

function getPool(): Promise<mysql.Pool> {
  if (!poolPromise) {
    poolPromise = Promise.resolve(
      mysql.createPool({
        host: process.env.MYSQL_HOST || '127.0.0.1',
        port: Number(process.env.MYSQL_PORT || 3306),
        user: process.env.MYSQL_USER || 'root',
        password: process.env.MYSQL_PASSWORD || '',
        database: process.env.MYSQL_DATABASE || 'absensi_siswa',
        waitForConnections: true,
        connectionLimit: Number(process.env.MYSQL_POOL_SIZE || 10),
        namedPlaceholders: false,
        dateStrings: true,
        ssl: process.env.MYSQL_SSL === 'true' ? { rejectUnauthorized: false } : undefined,
      })
    );
  }
  return poolPromise;
}

type WorkerOp = 'run' | 'get' | 'all' | 'exec';

interface WorkerResult {
  rows?: any[];
  insertId?: number;
  affectedRows?: number;
}

async function handleQuery(op: WorkerOp, sql: string, params: any[] = []): Promise<WorkerResult> {
  const pool = await getPool();

  if (op === 'exec') {
    // DDL / statement tunggal tanpa parameter (dipanggil per-statement oleh db.ts,
    // bukan mengandalkan multipleStatements pada koneksi demi keamanan).
    await pool.query(sql);
    return {};
  }

  const [result] = await pool.execute(sql, params);

  if (op === 'run') {
    const header = result as mysql.ResultSetHeader;
    return { insertId: Number(header.insertId || 0), affectedRows: Number(header.affectedRows || 0) };
  }

  // op === 'get' | 'all'
  return { rows: result as any[] };
}

runAsWorker(async (op: WorkerOp, sql: string, params: any[]) => {
  return handleQuery(op, sql, params);
});
