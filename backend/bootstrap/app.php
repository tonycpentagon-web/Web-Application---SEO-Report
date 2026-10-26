<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
        $middleware->prepend(SecurityHeaders::class);
        $middleware->alias(['active' => EnsureAccountIsActive::class]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            return response()->json(['message' => 'A record with these details already exists. Reload and try again.'], 409);
        });
        $exceptions->report(function (Throwable $error) {
            if (config('app.env') !== 'production') {
                return null;
            }
            Log::error('Application operation failed.', ['exception_class' => get_class($error), 'code' => (string) $error->getCode()]);

            return false; // No raw SQL bindings, request headers, paths or exception traces in production logs.
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (config('app.env') === 'production' && (! ($error instanceof HttpExceptionInterface) || $error->getStatusCode() >= 500) && ! ($error instanceof ValidationException) && ! ($error instanceof AuthenticationException) && ! ($error instanceof AuthorizationException)) {
                return response()->json(['message' => 'The server could not complete this request.'], 500);
            }
        });
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());
    })->create();
