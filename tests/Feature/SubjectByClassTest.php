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

    public function test_classes_for_subject_mirror_the_assignments(): void
    {
        Pairing::create(['user_id' => $this->guru->id, 'subject_id' => 1, 'class_id' => 2]);
        $this->assertSame([1], Assignments::classesForSubject($this->guru, 2)->pluck('id')->all());
        $this->assertSame([2], Assignments::classesForSubject($this->guru, 1)->pluck('id')->all());
        $this->assertSame([], Assignments::classesForSubject($this->guru, 3)->pluck('id')->all());
        // satu mapel di banyak kelas lewat akun (silang)
        $u = $this->wali->fresh();
        $u->update(['subjects' => [1], 'classes' => [1, 2]]);
        $this->assertEqualsCanonicalizing([1, 2], Assignments::classesForSubject($u->fresh(), 1)->pluck('id')->all());
    }

    public function test_attendance_form_lists_own_subjects_then_classes_of_chosen_subject(): void
    {
        Pairing::create(['user_id' => $this->guru->id, 'subject_id' => 1, 'class_id' => 2]);
        $html = $this->actingAs($this->guru)->get('/absensi?mode=mapel&subject=1')->assertOk()->assertSee('data-class-map', false)->getContent();
        preg_match('/<select id="subject".*?<\/select>/s', $html, $s);
        preg_match('/<select id="class".*?<\/select>/s', $html, $c);
        $this->assertStringContainsString('value="1" selected', $s[0]);
        $this->assertStringContainsString('value="2" selected', $c[0]); // mapel 1 hanya di kelas 2
        $this->assertStringNotContainsString('value="1"', $c[0]);
        $this->assertLessThan(strpos($html, 'id="class"'), strpos($html, 'id="subject"')); // mapel tampil lebih dulu
    }

    public function test_invalid_class_for_subject_falls_back_on_view_only(): void
    {
        Pairing::create(['user_id' => $this->guru->id, 'subject_id' => 1, 'class_id' => 2]);
        $html = $this->actingAs($this->guru)->get('/absensi?mode=mapel&subject=1&class=1')->assertOk()->getContent();
        preg_match('/<select id="class".*?<\/select>/s', $html, $c);
        $this->assertStringContainsString('value="2" selected', $c[0]);
    }

    public function test_grades_form_filters_classes_by_subject(): void
    {
        Pairing::create(['user_id' => $this->guru->id, 'subject_id' => 1, 'class_id' => 2]);
        $html = $this->actingAs($this->guru)->get('/nilai?subject=2')->assertOk()->getContent();
        preg_match('/<select id="class".*?<\/select>/s', $html, $c);
        $this->assertStringContainsString('value="1"', $c[0]);
        $this->assertStringNotContainsString('value="2"', $c[0]);
    }
}
