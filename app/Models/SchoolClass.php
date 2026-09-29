<?php

namespace App\Models;

use App\Models\Concerns\UsesSequenceId;
use Illuminate\Database\Eloquent\Model;

/** Kelas (tabel `classes`; nama "Class" adalah kata kunci PHP). */
class SchoolClass extends Model
{
    use UsesSequenceId;

    protected $table = 'classes';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['id' => 'integer'];

    public function students()
    {
        return $this->hasMany(Student::class, 'class_id');
    }
}
