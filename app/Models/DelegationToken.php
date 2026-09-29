<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Token delegasi presensi Ketua Kelas (maks. 24 jam). */
class DelegationToken extends Model
{
    protected $table = 'ketua_kelas_tokens';
    protected $primaryKey = 'token';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['class_id' => 'integer', 'created_by' => 'integer', 'expires_at_millis' => 'integer'];

    public function expiryMillis(): ?int
    {
        return $this->expires_at_millis ?: ($this->expires_at ? strtotime($this->expires_at) * 1000 : null);
    }

    public function isUsable(): bool
    {
        $exp = $this->expiryMillis();

        return $this->status === 'aktif' && (! $exp || $exp > (int) floor(microtime(true) * 1000));
    }
}
