<?php

namespace Tests\Feature;

use Tests\TestCase;

class ResetPasswordButtonTest extends TestCase
{
    public function test_admin_punya_tombol_reset_password_dan_bisa_mereset_guru(): void
    {
        $this->actingAs($this->admin)->get('/admin/guru')->assertOk()->assertSee('data-reset-pw', false);

        $this->actingAs($this->admin)->post('/admin/guru/'.$this->guru->id.'/reset', ['password' => 'BaruAman123'])
            ->assertSessionHas('success');
        $this->assertTrue(\App\Support\Passwords::check('BaruAman123', $this->guru->fresh()->password_hash));
    }
}
