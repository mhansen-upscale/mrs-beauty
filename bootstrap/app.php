<?php

declare(strict_types=1);

use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // Zuerst: eine deaktivierte Person soll nicht einmal bis zur
            // Mandantenaufloesung kommen.
            EnsureUserIsActive::class,

            // Muss vor HandleInertiaRequests laufen: das Teilen von
            // auth.user liest bereits Mandantendaten.
            ResolveTenant::class,

            // Nach ResolveTenant: ein Super-Admin hat keine eigene
            // Organisation, und eine Impersonation soll die eigene
            // ueberschreiben koennen.
            ApplyImpersonation::class,

            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
