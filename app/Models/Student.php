<?php

namespace App\Models;

use App\Models\Concerns\UsesSequenceId;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use UsesSequenceId;

    public const STATUSES = ['aktif' => 'Aktif', 'lulus' => 'Lulus', 'pindah' => 'Pindah', 'berhenti' => 'Berhenti', 'nonaktif' => 'Nonaktif', 'keluar' => 'Keluar'];

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['id' => 'integer', 'class_id' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (Student $s) => $s->created_at ??= now('UTC')->format('Y-m-d H:i:s'));
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function attendance()
    {
        return $this->hasMany(Attendance::class);
    }

    public function scopeActive($q)
    {
        return $q->where('status', 'aktif');
    }
}
