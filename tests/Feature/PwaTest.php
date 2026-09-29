<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaTest extends TestCase
{
    public function test_manifest_dan_tag_pwa(): void
    {
        $this->get('/manifest.webmanifest')->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('short_name', 'Absensi')
            ->assertJsonCount(3, 'icons')
            ->assertJsonPath('icons.2.purpose', 'maskable');
        $this->get('/login')->assertSee('rel="manifest"', false)->assertSee('pwa-install', false)->assertSee('apple-touch-icon', false);
        foreach (['sw.js', 'offline.html', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/icon-maskable-512.png', 'icons/apple-touch-icon.png'] as $f) {
            $this->assertFileExists(public_path($f));
        }
    }
}
