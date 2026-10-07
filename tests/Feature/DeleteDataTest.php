<?php

namespace Tests\Feature;

use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Tests\TestCase;

/** Hapus data master (salah input): hanya yang belum punya riwayat. */
class DeleteDataTest extends TestCase
{
    public function test_delete_student_without_history_and_refuse_with_history(): void
    {
        $this->mark(1, $this->daysAgo(1), 'H');
        $this->actingAs($this->admin)->delete('/admin/siswa/2')->assertRedirect();
        $this->assertNull(Student::find(2));
        $this->actingAs($this->admin)->delete('/admin/siswa/1')->assertRedirect()->assertSessionHas('error');
        $this->assertNotNull(Student::find(1));
    }

    public function test_bulk_delete_by_checkbox_and_whole_class_skips_students_with_history(): void
    {
        $this->mark(3, $this->daysAgo(1), 'S');
        $this->actingAs($this->admin)->post('/admin/siswa/hapus-massal', ['ids' => [4, 5]])->assertSessionHas('success');
        $this->assertNull(Student::find(4));
        $this->assertNull(Student::find(5));
        $this->actingAs($this->admin)->post('/admin/siswa/hapus-massal', ['class_id' => 1])->assertSessionHas('warning');
        $this->assertNotNull(Student::find(3));          // punya presensi → tetap
        $this->assertNull(Student::find(1));
        $this->assertNull(Student::find(2));
        $this->actingAs($this->admin)->post('/admin/siswa/hapus-massal', [])->assertSessionHas('error');
    }

    public function test_only_admin_can_delete(): void
    {
        $this->actingAs($this->guru)->delete('/admin/siswa/2')->assertForbidden();
        $this->actingAs($this->guru)->post('/admin/siswa/hapus-massal', ['ids' => [2]])->assertForbidden();
        $this->actingAs($this->guru)->delete('/admin/kelas/2')->assertForbidden();
        $this->assertNotNull(Student::find(2));
    }

    public function test_delete_class_only_when_empty_and_cleans_references(): void
    {
        $this->actingAs($this->admin)->delete('/admin/kelas/2')->assertSessionHas('error');   // masih ada siswa
        Student::where('class_id', 2)->delete();
        $u = User::create(['username' => 'x1', 'nama' => 'X', 'password_hash' => 'h', 'roles' => ['guru'], 'is_active' => true, 'kelas_wali_id' => 2, 'classes' => [1, 2]]);
        Pairing::create(['user_id' => $u->id, 'subject_id' => 1, 'class_id' => 2]);
        $this->actingAs($this->admin)->delete('/admin/kelas/2')->assertSessionHas('success');
        $this->assertNull(SchoolClass::find(2));
        $u->refresh();
        $this->assertNull($u->kelas_wali_id);
        $this->assertSame([1], array_values($u->classes));
        $this->assertSame(0, Pairing::where('class_id', 2)->count());
    }

    public function test_delete_class_with_attendance_is_refused(): void
    {
        Student::where('class_id', 1)->get()->each(fn ($s) => $s->id === 1 ?: $s->delete());
        $this->mark(1, $this->daysAgo(1), 'H');
        Student::whereKey(1)->update(['class_id' => 2]);   // kelas 1 kosong tapi presensi lama masih tercatat di kelas 1
        $this->actingAs($this->admin)->delete('/admin/kelas/1')->assertSessionHas('error');
        $this->assertNotNull(SchoolClass::find(1));
    }

    public function test_delete_subject_rules(): void
    {
        $s = Subject::create(['id' => 9, 'name' => 'Salah Ketik']);
        $u = User::create(['username' => 'x2', 'nama' => 'X2', 'password_hash' => 'h', 'roles' => ['guru'], 'is_active' => true, 'subjects' => [9, 2]]);
        Pairing::create(['user_id' => $u->id, 'subject_id' => 9, 'class_id' => 1]);
        $this->actingAs($this->admin)->delete('/admin/mapel/9')->assertSessionHas('success');
        $this->assertNull(Subject::find(9));
        $this->assertSame([2], array_values($u->refresh()->subjects));
        $this->assertSame(0, Pairing::where('subject_id', 9)->count());
        $this->mark(1, $this->daysAgo(1), 'H', 1);
        $this->actingAs($this->admin)->delete('/admin/mapel/1')->assertSessionHas('error');         // dipakai presensi
        $this->actingAs($this->admin)->delete('/admin/mapel/5')->assertSessionHas('error');         // mapel BK = sistem
        $this->assertNotNull(Subject::find(1));
        $this->assertNotNull(Subject::find(5));
    }

    public function test_delete_teacher_rules(): void
    {
        $this->actingAs($this->admin)->delete('/admin/guru/'.$this->admin->id)->assertSessionHas('error');   // diri sendiri
        $this->actingAs($this->admin)->delete('/admin/guru/'.$this->guru->id)->assertSessionHas('success');
        $this->assertNull(User::find($this->guru->id));
        $this->assertSame(0, Pairing::where('user_id', $this->guru->id)->count());
        \App\Models\Attendance::query()->delete();
        \Illuminate\Support\Facades\DB::table('attendance')->insert(['id' => 9001, 'student_id' => 1, 'class_id' => 1, 'tanggal' => $this->daysAgo(1), 'status' => 'H', 'recorded_by' => $this->bk->id, 'created_at' => 'x', 'updated_at' => 'x']);
        $this->actingAs($this->admin)->delete('/admin/guru/'.$this->bk->id)->assertSessionHas('error');       // punya riwayat → nonaktifkan saja
        $this->assertNotNull(User::find($this->bk->id));
    }

    public function test_delete_buttons_shown_to_admin(): void
    {
        $this->actingAs($this->admin)->get('/admin/siswa')->assertOk()->assertSee('Hapus yang dicentang')->assertSee('Hapus siswa');
        $this->actingAs($this->admin)->get('/admin/kelas')->assertOk()->assertSee('Hapus kelas');
        $this->actingAs($this->admin)->get('/admin/guru')->assertOk()->assertSee('Hapus akun');
    }
}
