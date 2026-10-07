<?php

use App\Exceptions\UserError;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SecurityHeaders::class, EnsureActiveUser::class]);
        $middleware->api(append: [SecurityHeaders::class]);
        // Di belakang proxy HTTPS (Cloudflare Tunnel, Nginx Proxy Manager, dll.): isi TRUSTED_PROXIES di .env ('*' atau daftar IP dipisah koma).
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->alias(['api.key' => \App\Http\Middleware\AuthenticateApiKey::class]);
        $middleware->prepend(\App\Http\Middleware\LegacyLinks::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, \Throwable $e) => $request->is('api/*') || $request->expectsJson());
        // Pelanggaran aturan bisnis → kembali ke halaman sebelumnya dengan pesan error.
        $exceptions->render(function (UserError $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->withInput($request->except(['password', 'old', 'new', 'new_confirmation', 'file']))->with('error', $e->getMessage());
        });
        $exceptions->dontReport(UserError::class);
    })->create();
