import { initializeApp } from 'firebase/app';
import { getAuth } from 'firebase/auth';
import { getFirestore } from 'firebase/firestore';
import firebaseConfig from '../../firebase-applet-config.json';

const app = initializeApp(firebaseConfig);
const dbId = (firebaseConfig as any).firestoreDatabaseId;
export const db = dbId ? getFirestore(app, dbId) : getFirestore(app);
export const auth = getAuth();

export enum OperationType {
  CREATE = 'create',
  UPDATE = 'update',
  DELETE = 'delete',
  LIST = 'list',
  GET = 'get',
  WRITE = 'write',
}

export interface FirestoreErrorInfo {
  error: string;
  operationType: OperationType;
  path: string | null;
  authInfo?: {
    isAuthenticated: boolean;
    isAnonymous?: boolean;
  };
}

/**
 * Struktur log internal hanya dalam memori privat (tidak diekspos ke client, window, atau tools pihak ketiga)
 */
interface InternalDiagnosticEntry {
  timestamp: string;
  operationType: OperationType;
  path: string | null;
  errorMessage: string;
  hasAuth: boolean;
  isAnonymous: boolean;
}

// Buffer diagnostik internal privat di memori (dibatasi 50 entri)
const _internalDiagnosticBuffer: InternalDiagnosticEntry[] = [];

/**
 * Mengambil ringkasan diagnostik internal secara aman tanpa data pribadi/PII
 */
export function getInternalDiagnostics(): readonly InternalDiagnosticEntry[] {
  return _internalDiagnosticBuffer;
}

/**
 * Menangani error Firestore dengan aman:
 * - Menghilangkan data pribadi (uid, email, data provider) dari objek error dan console.error
 * - Mengembalikan pesan error yang bersih, informatif, dan aman ke UI
 */
export function handleFirestoreError(
  error: unknown,
  operationType: OperationType,
  path: string | null
): never {
  const rawMessage = error instanceof Error ? error.message : String(error);

  // 1. Simpan konteks teknis ke buffer diagnostik internal dalam memori (tanpa PII seperti email atau uid)
  _internalDiagnosticBuffer.push({
    timestamp: new Date().toISOString(),
    operationType,
    path,
    errorMessage: rawMessage,
    hasAuth: Boolean(auth.currentUser),
    isAnonymous: Boolean(auth.currentUser?.isAnonymous),
  });
  if (_internalDiagnosticBuffer.length > 50) {
    _internalDiagnosticBuffer.shift();
  }

  // 2. Sanitasi pesan untuk mencegah kebocoran informasi teknis sensitif ke UI & monitoring eksternal
  const isPermissionDenied =
    rawMessage.includes('permission-denied') ||
    rawMessage.includes('Missing or insufficient permissions');

  const sanitizedMessage = isPermissionDenied
    ? 'Akses ditolak: izin tidak mencukupi untuk melakukan tindakan ini.'
    : rawMessage;

  // 3. Log ke console dengan format aman (tanpa PII user sehingga aman jika ditangkap monitoring pihak ketiga)
  console.error(`[Firestore Error] Operasi '${operationType}' pada '${path || 'dokumen'}' gagal: ${sanitizedMessage}`);

  // 4. Throw Error bersih ke UI tanpa menyertakan uid, email, token, atau informasi pribadi
  throw new Error(`Operasi database (${operationType}) gagal: ${sanitizedMessage}`);
}
