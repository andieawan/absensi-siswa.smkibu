<?php

namespace App\Services;

use App\Models\AuditLog;

class Audit
{
    public static function log(string $action, string $module, ?string $actor, string $details = ''): void
    {
        AuditLog::create([
            'timestamp' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'), 'action' => $action, 'module' => $module,
            'actor' => $actor ?? (auth()->user()?->nama ?? 'Sistem'), 'details' => $details,
        ]);
        $keep = AuditLog::orderByDesc('id')->skip(499)->value('id');
        if ($keep) {
            AuditLog::where('id', '<', $keep)->delete(); // simpan 500 log terbaru
        }
    }
}
