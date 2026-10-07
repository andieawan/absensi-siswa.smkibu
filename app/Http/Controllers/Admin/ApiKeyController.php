<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\Audit;
use App\Support\ApiScopes;
use App\Support\DbUpdate;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function index()
    {
        $ready = DbUpdate::apiReady();

        return view('admin.api', [
            'ready' => $ready,
            'keys' => $ready ? ApiKey::orderByDesc('is_active')->orderByDesc('id')->get() : collect(),
            'scopes' => ApiScopes::ALL,
            'base' => url('/api/v1'),
        ]);
    }

    public function store(Request $request)
    {
        if (! DbUpdate::apiReady()) {
            return back()->with('error', 'Fitur ini butuh pembaruan database: Admin → Pengaturan → Perbarui Database Sekarang.');
        }
        $data = $request->validate([
            'name' => 'required|string|max:100', 'scopes' => 'required|array|min:1', 'scopes.*' => 'string',
            'rate_limit' => 'required|integer|min:1|max:6000',
        ], ['scopes.required' => 'Pilih minimal satu izin.', 'scopes.min' => 'Pilih minimal satu izin.']);
        $scopes = ApiScopes::valid($data['scopes']);
        if (! $scopes) {
            return back()->withInput()->with('error', 'Izin tidak valid.');
        }
        $plain = 'ak_'.bin2hex(random_bytes(20));
        ApiKey::create([
            'name' => trim($data['name']), 'key_prefix' => substr($plain, 0, 11), 'key_hash' => ApiKey::hashOf($plain),
            'scopes' => $scopes, 'is_active' => true, 'rate_limit' => (int) $data['rate_limit'],
            'created_by' => $this->me()->id, 'created_at' => now('UTC')->format('Y-m-d H:i:s'),
        ]);
        Audit::log('Buat Kunci API', 'Integrasi API', $this->me()->nama, trim($data['name']).' — '.implode(', ', $scopes));

        return back()->with('success', 'Kunci API dibuat. Salin sekarang — kunci hanya ditampilkan sekali.')->with('new_api_key', $plain)->with('new_api_name', trim($data['name']));
    }

    public function revoke(ApiKey $key)
    {
        if ($key->is_active) {
            $key->update(['is_active' => false, 'revoked_at' => now('UTC')->format('Y-m-d H:i:s')]);
            Audit::log('Cabut Kunci API', 'Integrasi API', $this->me()->nama, $key->name);
        }

        return back()->with('success', "Kunci \"{$key->name}\" dicabut. Aplikasi tersebut tidak bisa mengakses API lagi.");
    }

    public function destroy(ApiKey $key)
    {
        if ($key->is_active) {
            return back()->with('error', 'Cabut kunci dulu sebelum menghapus.');
        }
        $key->delete();
        Audit::log('Hapus Kunci API', 'Integrasi API', $this->me()->nama, $key->name);

        return back()->with('success', 'Kunci yang sudah dicabut dihapus dari daftar.');
    }
}
