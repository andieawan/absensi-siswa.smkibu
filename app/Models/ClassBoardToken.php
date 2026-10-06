<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Token papan info kehadiran kelas untuk wali murid (baca-saja, tanpa masa berlaku, bisa dicabut). */
class ClassBoardToken extends Model
{
    protected $table = 'class_board_tokens';
    protected $primaryKey = 'token';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['class_id' => 'integer', 'created_by' => 'integer'];
}
