<?php

declare(strict_types=1);

use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\EnsureAboGilt;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

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

            // Nach der Mandantenaufloesung: das Abo haengt am Mandanten.
            // Sperrt erst, wenn Stripe aufgegeben hat -- nicht bei der
            // ersten fehlgeschlagenen Abbuchung.
            EnsureAboGilt::class,

            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // **Der Mandant steht, bevor eine Routenbindung aufgeloest wird.**
        //
        // Die Reihenfolge oben allein genuegt nicht: Laravel sortiert die
        // Middleware einer Route nach einer Prioritaetsliste, und
        // SubstituteBindings steht darin. Alles, was nicht in der Liste
        // steht -- also diese drei --, landete dadurch **hinter** der
        // Bindungsaufloesung. Ein Route-Model-Binding auf ein Mandantenmodell
        // lief damit ohne Mandanten und warf TenantContextMissing, bevor die
        // Aufloesung ueberhaupt an der Reihe war.
        //
        // Eingehaengt wird vor SubstituteBindings und damit hinter
        // AuthenticatesRequests (der Benutzer steht) und vor Authorize (die
        // Gates sehen die Impersonation). Die Kette ist einzeln formuliert,
        // damit die Reihenfolge der drei untereinander nicht davon abhaengt,
        // in welcher Folge Laravel die Eintraege verarbeitet.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureAboGilt::class,
        );

        $middleware->prependToPriorityList(
            before: EnsureAboGilt::class,
            prepend: ApplyImpersonation::class,
        );

        $middleware->prependToPriorityList(
            before: ApplyImpersonation::class,
            prepend: ResolveTenant::class,
        );

        $middleware->prependToPriorityList(
            before: ResolveTenant::class,
            prepend: EnsureUserIsActive::class,
        );

        // Die Zustellungen der Kalenderanbieter bringen kein Sitzungsmerkmal
        // mit. Sie weisen sich ueber das Geheimnis aus, das wir selbst
        // vergeben haben (Entscheidung A14) -- geprueft im Controller,
        // zeitkonstant.
        // Das Backoffice (WP-34) haengt an einem Kennzeichen am Benutzer,
        // nicht an einer Rolle: der Betreiber gehoert zu keiner Praxis.
        $middleware->alias(['super-admin' => EnsureSuperAdmin::class]);

        // Der Zustand der Seitenleiste wird im Browser gesetzt
        // (SidebarProvider) und beim naechsten Aufruf serverseitig gelesen,
        // damit die Leiste nicht bei jedem Seitenwechsel sichtbar von auf
        // nach zu springt. Ein verschluesseltes Cookie kann JavaScript nicht
        // schreiben: Laravel wuerde es beim Lesen verwerfen, und der Wert
        // waere immer der Vorgabewert.
        //
        // Unbedenklich, weil darin kein Geheimnis steht -- nur auf oder zu.
        $middleware->encryptCookies(except: [
            'sidebar:state',
        ]);

        $middleware->validateCsrfTokens(except: [
            'kalender/google/zustellung',
            'kalender/microsoft/zustellung',
            'webhooks/meta',
            'webhooks/mail',
            'webhooks/stripe',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
