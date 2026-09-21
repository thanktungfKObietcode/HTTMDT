<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\BackofficeMiddleware;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['thanh-toan/momo/ipn']);
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustedPaymentProxies::class);
        $middleware->append(\App\Http\Middleware\PaymentSecurityHeaders::class);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => AdminMiddleware::class,
            'backoffice' => BackofficeMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['signature', 'vnp_SecureHash', 'vnp_SecureHashType']);
        $exceptions->report(function (\Throwable $exception) {
            if (app()->bound('request') && \App\Support\PaymentError::isPaymentRequest(request())) {
                \App\Support\PaymentError::message($exception);
                return false; // Prevent default logs from including SQL bindings, payload or credentials.
            }
        });
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (\App\Support\PaymentError::isPaymentRequest($request)
                && ! $exception instanceof \Illuminate\Validation\ValidationException
                && ! $exception instanceof \Illuminate\Auth\AuthenticationException
                && ! $exception instanceof \Illuminate\Auth\Access\AuthorizationException
                && (! $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                    || $exception->getStatusCode() >= 500)) {
                return response()->json(['message' => \App\Support\PaymentError::MESSAGE], 500)
                    ->header('Cache-Control', 'no-store, private');
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
