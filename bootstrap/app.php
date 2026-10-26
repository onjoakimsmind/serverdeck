<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\UpdateLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            UpdateLastSeen::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response) {
            $request = request();
            $status = $response->getStatusCode();

            if (
                $request->isMethod('GET')
                && ! $request->expectsJson()
                && ! $request->is('up', 'api/*')
                && in_array($status, [403, 404, 405, 419, 429, 500, 503], true)
            ) {
                $props = ['status' => $status];

                if ($request->hasSession()) {
                    $props = [
                        ...app(HandleInertiaRequests::class)->share($request),
                        ...$props,
                    ];
                }

                return Inertia::render('Error', $props)
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
