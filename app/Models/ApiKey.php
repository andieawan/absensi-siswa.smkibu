<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kunci API satu aplikasi pemanggil; hak akses dibatasi oleh scopes. */
class ApiKey extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['key_hash'];
    protected $casts = ['scopes' => 'array', 'is_active' => 'boolean', 'rate_limit' => 'integer', 'created_by' => 'integer'];

    public static function hashOf(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
