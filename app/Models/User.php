<?php

namespace App\Models;

use App\Models\Concerns\UsesSequenceId;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use UsesSequenceId;

    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['password_hash'];
    protected $rememberTokenName = ''; // fitur "ingat saya" tidak dipakai

    protected function casts(): array
    {
        return [
            'id' => 'integer', 'kelas_wali_id' => 'integer', 'is_active' => 'boolean',
            'roles' => 'array', 'subjects' => 'array', 'classes' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $u) {
            $u->created_at ??= now('UTC')->format('Y-m-d H:i:s');
            $u->roles ??= ['guru'];
            $u->subjects ??= [];
            $u->classes ??= [];
        });
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function hasRole(string ...$roles): bool
    {
        return (bool) array_intersect($roles, $this->roles ?? []);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin', 'superadmin');
    }

    public function isWaliOf(int $classId): bool
    {
        return $this->kelas_wali_id === $classId;
    }

    public function roleLabel(): string
    {
        $map = ['superadmin' => 'Superadmin', 'admin' => 'Admin', 'kepsek' => 'Kepala Sekolah', 'bk' => 'Guru BK', 'guru' => 'Guru'];

        return implode(' · ', array_map(fn ($r) => $map[$r] ?? $r, $this->roles ?? []));
    }

    public function waliClass()
    {
        return $this->belongsTo(SchoolClass::class, 'kelas_wali_id');
    }
}
