<?php

namespace App\Http\Controllers;

use App\Models\User;

abstract class Controller
{
    protected function me(): User
    {
        return auth()->user();
    }
}
