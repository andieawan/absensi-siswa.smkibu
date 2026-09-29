<?php

namespace App\Models;

use App\Models\Concerns\UsesSequenceId;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use UsesSequenceId;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['id' => 'integer'];

    /** Mapel reguler (tanpa Bimbingan Konseling). */
    public function scopeRegular($q)
    {
        return $q->where('id', '!=', config('absensi.bk_subject_id'));
    }
}
