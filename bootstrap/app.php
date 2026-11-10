<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UseClientAddressHeader;
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
        // by default for its own Cloud, Forge and Vapor hosts. It is not, on
        // its own, safe for the address: neither Cloudflare nor Render clears
        // an X-Forwarded-For the caller sent, so its first entry — the one
        // Symfony reads when the whole chain is trusted — is the caller's to
        // choose. UseClientAddressHeader, below, is what closes that: where
        // the edge's own header is configured it becomes the address and the
        // chain is dropped. The chain stays trusted only as the fallback for
        // a deployment with no such header.
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

        // Ahead of everything, the trusted-proxy middleware included, so the
        // address is settled before anything reads it.
        $middleware->prepend(UseClientAddressHeader::class);

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
