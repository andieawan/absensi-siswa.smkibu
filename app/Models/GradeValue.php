<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nilai per siswa (kunci gabungan activity_id + student_id; hanya dibaca/diisi massal). */
class GradeValue extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;
    protected $guarded = [];
    protected $casts = ['student_id' => 'integer'];

    public function isLow(): bool
    {
        return (is_numeric($this->nilai) && (float) $this->nilai < 70) || in_array($this->nilai, ['D', 'E'], true);
    }
}
