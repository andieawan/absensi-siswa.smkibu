<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TuSurat extends Model
{
    protected $table = 'tu_surat';
    protected $guarded = [];
    protected $casts = ['urut' => 'integer', 'tahun' => 'integer', 'student_id' => 'integer', 'extra' => 'array'];

    public function student()
    {
        return $this->belongsTo(Student::class);
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
