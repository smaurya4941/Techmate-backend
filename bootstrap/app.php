<?php

use App\Http\Middleware\AssignRequestId;
use App\Support\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only trust forwarded headers from proxies listed in TRUSTED_PROXIES
        // (comma-separated IPs/CIDRs, or "*" when a trusted load balancer
        // strips client-supplied values). Unset = no proxy is trusted, so
        // X-Forwarded-For cannot be spoofed to dodge rate limits.
        $proxies = env('TRUSTED_PROXIES');
        if ($proxies) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        $middleware->api(prepend: [AssignRequestId::class]);

        // There is no public /login route; staff sign in through the Filament panel.
        $middleware->redirectGuestsTo(fn () => '/admin/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
