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
}
