<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catatan BK (Pelanggaran, Buku Kasus, Prestasi, Home Visit, Riwayat Surat). Definisi tiap jenis: App\Support\BkModules. */
class BkRecord extends Model
{
    protected $guarded = [];
    protected $casts = ['student_id' => 'integer', 'class_id' => 'integer', 'dicatat_oleh' => 'integer', 'rahasia' => 'boolean', 'extra' => 'array'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    public function x(string $key): string
    {
        return (string) ($this->extra[$key] ?? '');
    }
}
