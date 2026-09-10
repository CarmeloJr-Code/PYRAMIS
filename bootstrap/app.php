<?php

use App\Http\Middleware\SecurityHeaders;
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
        // Render reaches the container through Cloudflare and its own load
        // balancer, so every request arrives from a proxy address and over
        // plain HTTP. Left untrusted, request()->ip() is the balancer rather
        // than the customer — enough on its own to make any limiter keyed by
        // address count the whole internet as a single caller.
        //
        // Trusting every address is what the platform asks for: Render
        // publishes no stable inbound range, and Laravel already does the same
        // by default for its own Cloud, Forge and Vapor hosts. What makes it
        // safe rather than merely convenient is that Render sets the first
        // entry of X-Forwarded-For to the real client itself instead of
        // appending to whatever arrived, and Symfony reads exactly that entry
        // when the whole chain is trusted. The address a limiter counts is
        // therefore not one the caller gets to choose.
        //
        // The forwarded host is deliberately left out. It is client-settable in
        // the general case — which is why Laravel strips it on Forge and Vapor
        // — and a trusted one rewrites every generated link. Nothing here needs
        // it: Render routes on the Host header, which already gives the right
        // answer. Proto and port are needed, or the app behind the terminating
        // proxy believes it is serving plain HTTP and writes http:// into every
        // URL and secure cookie.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Appended rather than prepended, so the headers are set on whatever
        // response comes back — the error pages the exception handler renders
        // included, which are the ones most likely to be framed.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
