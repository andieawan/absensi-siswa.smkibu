<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Token Portal Wali Murid (baca-saja, per siswa). */
class ParentToken extends Model
{
    protected $table = 'parent_access_tokens';
    protected $primaryKey = 'token';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['student_id' => 'integer', 'created_by' => 'integer'];
}
