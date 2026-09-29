<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

class InstallTest extends BaseTestCase
{
    use RefreshDatabase;

    public function test_instalasi_lewat_browser_sekali_saja(): void
    {
        config(['absensi.admin.username' => 'kepala', 'absensi.admin.password' => 'short']);
        $this->get('/pasang')->assertOk()->assertSee('minimal 10 karakter');
        $this->post('/pasang')->assertSessionHas('error');

        config(['absensi.admin.password' => 'PanjangSekali1']);
        $this->post('/pasang')->assertRedirect('/login');
        $u = User::where('username', 'kepala')->firstOrFail();
        $this->assertTrue($u->isAdmin());
        $this->get('/pasang')->assertNotFound();
        $this->post('/login', ['username' => 'kepala', 'password' => 'PanjangSekali1'])->assertRedirect('/');
    }
}
