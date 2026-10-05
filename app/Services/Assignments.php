<?php

namespace App\Services;

use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;

/** Kelas & mapel yang boleh dipilih seorang guru pada form absensi/nilai. */
class Assignments
{
    public static function classes(User $u, bool $waliOnly): Collection
    {
        $all = SchoolClass::orderBy('name')->get();
        if ($u->isAdmin()) {
            return $all;
        }
        if ($waliOnly) {
            return $all->where('id', $u->kelas_wali_id)->values();
        }
        $ids = array_merge(array_map('intval', $u->classes ?? []), Pairing::where('user_id', $u->id)->pluck('class_id')->all());

        return $all->whereIn('id', $ids)->values();
    }

    public static function subjects(User $u): Collection
    {
        $all = Subject::regular()->orderBy('name')->get();
        if ($u->isAdmin()) {
            return $all;
        }
        $ids = array_merge(array_map('intval', $u->subjects ?? []), Pairing::where('user_id', $u->id)->pluck('subject_id')->all());

        return $all->whereIn('id', $ids)->values();
    }

    /**
     * Mapel yang diajar guru pada SATU kelas — cerminan Rules::teacherAuthorization:
     * pasangan (mapel+kelas) eksak, ditambah semua mapel akun bila kelas ada di daftar kelas akun.
     */
    public static function subjectsForClass(User $u, int $classId): Collection
    {
        $all = Subject::regular()->orderBy('name')->get();
        if ($u->isAdmin()) {
            return $all;
        }
        $ids = Pairing::where('user_id', $u->id)->where('class_id', $classId)->pluck('subject_id')->map(fn ($v) => (int) $v)->all();
        if (in_array($classId, array_map('intval', $u->classes ?? []), true)) {
            $ids = array_merge($ids, array_map('intval', $u->subjects ?? []));
        }

        return $all->whereIn('id', $ids)->values();
    }

    /** Peta kelas => [[id, nama], ...] untuk filter mapel di sisi klien. */
    public static function subjectMap(User $u, Collection $classes): array
    {
        $map = [];
        foreach ($classes as $c) {
            $map[$c->id] = self::subjectsForClass($u, $c->id)->map(fn ($s) => [$s->id, $s->name])->all();
        }

        return $map;
    }
}
