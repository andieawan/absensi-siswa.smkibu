<?php

use App\Http\Controllers\Api\V1Controller;
use Illuminate\Support\Facades\Route;

// API v1 — hanya baca, autentikasi kunci API per aplikasi (lihat docs/API.md).
Route::prefix('v1')->group(function () {
    Route::get('/openapi.json', [V1Controller::class, 'openapi']);
    Route::get('/ping', [V1Controller::class, 'ping'])->middleware('api.key');

    Route::middleware('api.key:kelas:baca')->group(function () {
        Route::get('/kelas', [V1Controller::class, 'kelasIndex']);
        Route::get('/kelas/{id}', [V1Controller::class, 'kelasShow'])->whereNumber('id');
    });
    Route::get('/mapel', [V1Controller::class, 'mapelIndex'])->middleware('api.key:mapel:baca');
    Route::middleware('api.key:siswa:baca')->group(function () {
        Route::get('/siswa', [V1Controller::class, 'siswaIndex']);
        Route::get('/siswa/{id}', [V1Controller::class, 'siswaShow'])->whereNumber('id');
    });
    Route::middleware('api.key:kehadiran:baca')->group(function () {
        Route::get('/siswa/{id}/rekap', [V1Controller::class, 'rekap'])->whereNumber('id');
        Route::get('/kehadiran', [V1Controller::class, 'kehadiran']);
    });
});
