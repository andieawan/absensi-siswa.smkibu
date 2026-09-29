<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pasangan guru – mapel – kelas (kunci gabungan). */
class Pairing extends Model
{
    protected $table = 'teacher_subject_class_pairing';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;
    protected $guarded = [];
    protected $casts = ['user_id' => 'integer', 'subject_id' => 'integer', 'class_id' => 'integer'];
}
