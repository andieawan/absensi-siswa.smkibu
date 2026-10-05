<?php

namespace Tests\Feature;

use App\Models\Pairing;
use App\Models\User;
use App\Services\Assignments;
use Tests\TestCase;

/** Pilihan mapel mengikuti kelas yang dipilih guru. */
class SubjectByClassTest extends TestCase
{
    public function test_pairing_gives_different_subjects_per_class(): void
    {
        // guru: mapel 2 di kelas 1 (pasangan dari TestCase) + mapel 1 di kelas 2
        Pairing::create(['user_id' => $this->guru->id, 'subject_id' => 1, 'class_id' => 2]);
        $this->assertSame([2], Assignments::subjectsForClass($this->guru, 1)->pluck('id')->all());
        $this->assertSame([1], Assignments::subjectsForClass($this->guru, 2)->pluck('id')->all());
        $this->assertSame([], Assignments::subjectsForClass($this->guru, 3)->pluck('id')->all());
    }

    public function test_several_subjects_in_one_class_via_account(): void
    {
        $u = User::find($this->wali->id);
        $u->update(['subjects' => [1, 2], 'classes' => [1]]);
        $this->assertEqualsCanonicalizing([1, 2], Assignments::subjectsForClass($u->fresh(), 1)->pluck('id')->all());
        $this->assertSame([], Assignments::subjectsForClass($u->fresh(), 2)->pluck('id')->all());
    }

    public function test_attendance_form_only_lists_subjects_of_chosen_class(): void
    {
        Pairing::create(['user_id' => $this->guru->id, 'subject_id' => 1, 'class_id' => 2]);
        $r = $this->actingAs($this->guru)->get('/absensi?mode=mapel&class=2')->assertOk();
        $html = $r->getContent();
        preg_match('/<select id="subject".*?<\/select>/s', $html, $m);
        $this->assertStringContainsString('value="1" selected', $m[0]);
        $this->assertStringNotContainsString('value="2"', $m[0]);
        $r->assertSee('data-subject-map', false);
    }

    public function test_invalid_subject_for_class_falls_back(): void
    {
        Pairing::create(['user_id' => $this->guru->id, 'subject_id' => 1, 'class_id' => 2]);
        $html = $this->actingAs($this->guru)->get('/absensi?mode=mapel&class=2&subject=2')->assertOk()->getContent();
        preg_match('/<select id="subject".*?<\/select>/s', $html, $m);
        $this->assertStringContainsString('value="1" selected', $m[0]);
    }

    public function test_grades_form_filters_too(): void
    {
        Pairing::create(['user_id' => $this->guru->id, 'subject_id' => 1, 'class_id' => 2]);
        $html = $this->actingAs($this->guru)->get('/nilai?class=1')->assertOk()->getContent();
        preg_match('/<select id="subject".*?<\/select>/s', $html, $m);
        $this->assertStringContainsString('value="2"', $m[0]);
        $this->assertStringNotContainsString('value="1"', $m[0]);
    }
}
