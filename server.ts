import express, { Request, Response } from 'express';
import { createServer as createViteServer } from 'vite';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

async function startServer() {
  const app = express();
  const PORT = Number(process.env.PORT) || 3000;

  app.use(express.json({ limit: '10mb' }));
  app.use(express.urlencoded({ extended: true }));

  // REST API Endpoints according to PRD Section 8
  app.get('/api/health', (req: Request, res: Response) => {
    res.json({
      status: 'ok',
      service: 'go_absen_siswa_api',
      engine: 'Node.js Express + MySQL Relational Architecture',
      timestamp: new Date().toISOString(),
      uptime: process.uptime(),
    });
  });

  // Database Schema & Model Definition endpoint (Documentation / Verification)
  app.get('/api/schema', (req: Request, res: Response) => {
    res.json({
      message: 'Skema Relasional MySQL Terverifikasi',
      tables: [
        'users',
        'roles',
        'user_roles',
        'subjects',
        'classes',
        'user_subjects',
        'user_classes',
        'students',
        'attendance',
        'grade_activities',
        'grade_values',
        'teacher_subject_class_pairing',
        'ketua_kelas_tokens',
        'upload_absen_links',
        'audit_log',
        'school_settings',
      ],
      constraints: {
        attendance_unique: '(student_id, subject_id, tanggal)',
        grade_values_primary: '(activity_id, student_id)',
        pairing_primary: '(user_id, subject_id, class_id)',
      },
    });
  });

  // Mount Vite middleware in development
  const isProd = process.env.NODE_ENV === 'production';
  if (!isProd) {
    const vite = await createViteServer({
      server: { middlewareMode: true },
      appType: 'spa',
    });
    app.use(vite.middlewares);
  } else {
    // Serve static files in production
    app.use(express.static(path.resolve(__dirname, 'dist')));
    app.get('*', (req: Request, res: Response) => {
      res.sendFile(path.resolve(__dirname, 'dist', 'index.html'));
    });
  }

  app.listen(PORT, '0.0.0.0', () => {
    console.log(`[go_absen_siswa] Server running on http://0.0.0.0:${PORT}`);
  });
}

startServer().catch((err) => {
  console.error('Failed to start server:', err);
  process.exit(1);
});
