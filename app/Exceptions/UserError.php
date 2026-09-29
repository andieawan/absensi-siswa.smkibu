<?php

namespace App\Exceptions;

use RuntimeException;

/** Pelanggaran aturan bisnis yang pesannya aman ditampilkan ke pengguna (dirender sebagai flash error). */
class UserError extends RuntimeException
{
}
