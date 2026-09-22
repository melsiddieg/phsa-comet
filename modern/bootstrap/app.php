<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // In production a proxy (Caddy, Traefik, ...) terminates TLS and
        // forwards to the app over plain HTTP. Trust its X-Forwarded-*
        // headers so Laravel builds https:// URLs and logs the real client
        // IP. Trusting any source is safe because the app port is published
        // on 127.0.0.1 only and is reached through the proxy. X-Forwarded-Host
        // is not trusted: the proxies pass the original Host header through,
        // so it is not needed, and trusting it would allow host spoofing.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
