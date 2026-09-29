<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $mod = (string) $request->query('modul');
        $q = trim((string) $request->query('q'));

        return view('admin.logs', [
            'mod' => $mod, 'q' => $q, 'modules' => AuditLog::distinct()->orderBy('module')->pluck('module'),
            'logs' => AuditLog::when($mod !== '', fn ($x) => $x->where('module', $mod))
                ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('action', 'like', "%$q%")->orWhere('actor', 'like', "%$q%")->orWhere('details', 'like', "%$q%")))
                ->orderByDesc('id')->paginate(100)->withQueryString(),
        ]);
    }
}
