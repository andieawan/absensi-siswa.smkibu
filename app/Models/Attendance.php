<?php

namespace App\Models;

use App\Models\Concerns\UsesSequenceId;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use UsesSequenceId;

    public const STATUS = ['H' => 'Hadir', 'I' => 'Izin', 'S' => 'Sakit', 'A' => 'Alpa'];
    public const VIA = ['guru' => 'Guru', 'wali' => 'Wali Kelas', 'ketua_kelas_delegasi' => 'Ketua Kelas', 'bk_manual' => 'BK Manual', 'upload_hardcopy' => 'Upload Hardcopy'];

    protected $table = 'attendance';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'id' => 'integer', 'student_id' => 'integer', 'class_id' => 'integer', 'subject_id' => 'integer',
        'recorded_by' => 'integer', 'created_at_millis' => 'integer',
    ];

    protected static function sequenceEntity(): string
    {
        return 'attendance';
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /** $subject: false = semua, null = hanya absen harian, int = mapel tertentu. */
    public function scopeScope($q, ?int $classId, int|null|false $subject)
    {
        if ($classId) {
            $q->where('class_id', $classId);
        }
        if ($subject === null) {
            $q->whereNull('subject_id');
        } elseif ($subject !== false) {
            $q->where('subject_id', $subject);
        }

        return $q;
    }
}
