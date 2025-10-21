<?php

use App\Http\Middleware\FirebaseAuthMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 'firebase.auth' => \App\Http\Middleware\FirebaseAuthMiddleware::class,
        $middleware->use([
            'firebase.auth' => FirebaseAuthMiddleware::class,
            // 'basic.auth' => \App\Http\Middleware\BasicAuth::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            '/login',
            '/payments/initiate',
        ]);

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
