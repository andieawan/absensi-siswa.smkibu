<?php

namespace App\Http\Controllers\Tu;

use App\Exceptions\UserError;
use App\Http\Controllers\Controller;
use App\Support\DbUpdate;

/** Dasar semua halaman TU: bila tabel TU belum dibuat (belum "Perbarui Database"), tampilkan petunjuk. */
abstract class TuController extends Controller
{
    public function __construct()
    {
        if (! DbUpdate::tuReady()) {
            throw new UserError('Modul TU belum aktif: Admin perlu membuka Pengaturan lalu klik "Perbarui Database Sekarang".');
        }
    }
}
