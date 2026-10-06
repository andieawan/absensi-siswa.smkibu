<?php

namespace Tests\Feature;

use App\Models\SchoolSetting;
use App\Support\PasswordPolicy;
use Tests\TestCase;

/** Aturan password yang bisa diatur Admin (bebas / panjang minimal / huruf / angka / simbol). */
class PasswordPolicyTest extends TestCase
{
    private function policy(array $v): void
    {
        SchoolSetting::put($v);
    }

    public function test_default_is_min_8_without_composition(): void
    {
        $this->assertSame(['mode' => 'aturan', 'min' => 8, 'huruf' => false, 'angka' => false, 'simbol' => false], PasswordPolicy::current());
        $this->assertSame([], PasswordPolicy::problems('abcdefgh'));
        $this->assertNotEmpty(PasswordPolicy::problems('abc'));
    }

    public function test_rules_letters_numbers_symbols(): void
    {
        $this->policy(['pw_mode' => 'aturan', 'pw_min' => 10, 'pw_huruf' => true, 'pw_angka' => true, 'pw_simbol' => true]);
        $this->assertSame('min. 10 karakter, wajib huruf & angka & simbol', PasswordPolicy::hint());
        $this->assertSame([], PasswordPolicy::problems('Sekolah123!'));
        $this->assertCount(1, PasswordPolicy::problems('Sekolah1234'));   // tanpa simbol
        $this->assertCount(1, PasswordPolicy::problems('Sekolah!!!!'));   // tanpa angka
        $this->assertCount(1, PasswordPolicy::problems('1234567890!'));   // tanpa huruf
        $this->assertCount(1, PasswordPolicy::problems('Ab1!'));          // terlalu pendek
        $this->assertCount(4, PasswordPolicy::problems('   '));           // spasi bukan huruf/angka/simbol
    }

    public function test_free_mode_accepts_anything_but_empty(): void
    {
        $this->policy(['pw_mode' => 'bebas']);
        $this->assertSame('bebas, asal tidak kosong', PasswordPolicy::hint());
        $this->assertSame([], PasswordPolicy::problems('1'));
        $this->assertSame(['tidak boleh kosong'], PasswordPolicy::problems(''));
    }

    public function test_generated_passwords_satisfy_the_policy(): void
    {
        $this->policy(['pw_mode' => 'aturan', 'pw_min' => 14, 'pw_huruf' => true, 'pw_angka' => true, 'pw_simbol' => true]);
        for ($i = 0; $i < 30; $i++) {
            $pw = PasswordPolicy::generate();
            $this->assertSame([], PasswordPolicy::problems($pw), $pw);
            $this->assertGreaterThanOrEqual(14, strlen($pw));
        }
    }

    public function test_admin_can_save_the_policy_from_settings(): void
    {
        $base = ['school_name' => 'S', 'semester' => 'Ganjil', 'backup_retention_weeks' => 8, 'pw_form' => 1];
        $this->actingAs($this->admin)->put('/admin/pengaturan', $base + ['pw_mode' => 'aturan', 'pw_min' => 6, 'pw_angka' => 1])->assertSessionHas('success');
        $this->assertSame(['mode' => 'aturan', 'min' => 6, 'huruf' => false, 'angka' => true, 'simbol' => false], PasswordPolicy::current());
        $this->actingAs($this->admin)->put('/admin/pengaturan', $base + ['pw_mode' => 'bebas'])->assertSessionHas('success');
        $this->assertSame('bebas', PasswordPolicy::current()['mode']);
        // formulir tanpa pw_form tidak menimpa aturan
        $this->actingAs($this->admin)->put('/admin/pengaturan', ['school_name' => 'S', 'semester' => 'Ganjil', 'backup_retention_weeks' => 8])->assertSessionHas('success');
        $this->assertSame('bebas', PasswordPolicy::current()['mode']);
        $this->actingAs($this->admin)->get('/admin/pengaturan')->assertOk()->assertSee('Aturan Password Pengguna');
    }

    public function test_policy_is_enforced_on_create_reset_change_and_import(): void
    {
        $this->policy(['pw_mode' => 'aturan', 'pw_min' => 8, 'pw_huruf' => true, 'pw_angka' => true, 'pw_simbol' => false]);
        $form = ['nama' => 'Baru', 'roles' => ['guru'], 'username' => 'guru.uji'];
        $this->actingAs($this->admin)->post('/admin/guru', $form + ['password' => 'hanyahuruf'])->assertSessionHasErrors('password');
        $this->actingAs($this->admin)->post('/admin/guru', $form + ['password' => 'Kuat12345'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/admin/guru/{$this->guru->id}/reset", ['password' => '12345678'])->assertSessionHasErrors('password');
        $this->actingAs($this->admin)->post("/admin/guru/{$this->guru->id}/reset", ['password' => 'Reset12345'])->assertSessionHasNoErrors();
        $this->actingAs($this->wali)->post('/password', ['old' => 'password123', 'new' => 'abcdefgh', 'new_confirmation' => 'abcdefgh'])->assertSessionHasErrors('new');
        $this->actingAs($this->wali)->post('/password', ['old' => 'password123', 'new' => 'abcd1234', 'new_confirmation' => 'abcd1234'])->assertSessionHasNoErrors();
        // impor: password terisi yang lemah dilewati, yang kosong dibuatkan sesuai aturan
        $csv = "Username,Nama,Peran,Password\nimp.lemah,A,guru,hurufsaja\nimp.otomatis,B,guru,\n";
        $this->actingAs($this->admin)->post('/admin/impor/guru', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('g.csv', $csv)])
            ->assertSessionHas('import_result', fn ($r) => $r['ok'] === 1 && count($r['skip']) === 1 && PasswordPolicy::problems($r['passwords']['imp.otomatis']) === []);
    }

    public function test_pages_show_the_policy_hint(): void
    {
        $this->policy(['pw_mode' => 'aturan', 'pw_min' => 12, 'pw_simbol' => true]);
        $this->actingAs($this->wali)->get('/password')->assertOk()->assertSee('min. 12 karakter, wajib simbol');
        $this->actingAs($this->admin)->get('/admin/guru')->assertOk()->assertSee('min. 12 karakter, wajib simbol');
    }
}
