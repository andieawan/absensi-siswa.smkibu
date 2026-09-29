<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeActivity extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['teacher_id' => 'integer', 'subject_id' => 'integer', 'class_id' => 'integer'];

    public function values()
    {
        return $this->hasMany(GradeValue::class, 'activity_id');
    }
}
