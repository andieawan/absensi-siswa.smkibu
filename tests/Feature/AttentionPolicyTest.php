<?php

namespace Tests\Feature;

use App\Models\SchoolSetting;
use App\Services\Rules;
use App\Support\AttentionPolicy;
use Tests\TestCase;

class AttentionPolicyTest extends TestCase
{
    private function save(array $over = [])
    {
        return $this->actingAs($this->admin)->put('/admin/pengaturan', $over + [
            'school_name' => 'SMK', 'semester' => 'Ganjil', 'backup_retention_weeks' => 8, 'att_form' => 1,
            'att_alpa' => 2, 'att_sakit' => 2, 'att_izin' => 2, 'att_total' => 3,
        ]);
    }

    public function test_defaults_match_old_behaviour(): void
    {
        $this->assertSame(['alpa' => 2, 'sakit' => 2, 'izin' => 2, 'total' => 3], AttentionPolicy::current());
        $this->assertNull(AttentionPolicy::category(1, 1, 0));
        $this->assertSame('jarang_masuk_gabungan', AttentionPolicy::category(1, 1, 1));
        $this->assertSame('alpa_tinggi', AttentionPolicy::category(2, 5, 5));
        $this->assertSame('sakit_tinggi', AttentionPolicy::category(0, 2, 2));
        $this->assertSame('izin_tinggi', AttentionPolicy::category(0, 2, 1));
    }

    public function test_admin_changes_thresholds_and_rules_follow(): void
    {
        $this->mark(1, $this->daysAgo(3), 'A');
        $this->mark(1, $this->daysAgo(2), 'A');
        $this->mark(1, $this->daysAgo(1), 'S');
        $this->assertSame('alpa_tinggi', Rules::attentionCategory(1)['category']);

        $this->save(['att_alpa' => 3, 'att_total' => 5])->assertSessionHasNoErrors();
        SchoolSetting::forget();
        $this->assertSame(3, AttentionPolicy::current()['alpa']);
        $this->assertNull(Rules::attentionCategory(1)['category']); // alpa 2 <3, sakit 1, total 3 <5

        $this->save(['att_alpa' => 3, 'att_total' => 3]);
        SchoolSetting::forget();
        $this->assertSame('jarang_masuk_gabungan', Rules::attentionCategory(1)['category']);
    }

    public function test_dashboard_uses_policy(): void
    {
        $this->mark(1, $this->daysAgo(2), 'A');
        $this->mark(1, $this->daysAgo(1), 'A');
        $this->actingAs($this->admin)->get('/?v=sekolah&class=1')->assertOk()->assertSee('Semua (1)');
        $this->save(['att_alpa' => 5, 'att_total' => 9]);
        SchoolSetting::forget();
        $this->actingAs($this->admin)->get('/?v=sekolah&class=1')->assertOk()->assertSee('Semua (0)')->assertSee('Tidak ada siswa dalam kategori ini');
    }

    public function test_validation_and_form_visible(): void
    {
        $this->save(['att_alpa' => 0])->assertSessionHasErrors('att_alpa');
        $this->save(['att_total' => 101])->assertSessionHasErrors('att_total');
        $this->actingAs($this->admin)->get('/admin/pengaturan')->assertOk()->assertSee('Ambang “Perlu Perhatian”', false)->assertSee('att_total');
    }
}
