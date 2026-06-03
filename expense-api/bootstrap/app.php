<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CompanyScope;
use App\Http\Middleware\ForceJsonResponse;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Force Accept: application/json on every API request so clients
        // always receive JSON (never an HTML error page).
        $middleware->appendToGroup('api', ForceJsonResponse::class);

        $middleware->alias([
            'role'          => CheckRole::class,
            'company.scope' => CompanyScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always render JSON for api/* routes, regardless of Accept header.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ValidationException $e) {
            return ApiResponse::error('Validation failed', $e->errors(), 422);
        });

        $exceptions->render(function (AuthenticationException $e) {
            return ApiResponse::error('Unauthenticated', [], 401);
        });

        // AuthorizationException is converted to AccessDeniedHttpException
        // before renderers run — register both to cover either path.
        $exceptions->render(function (AuthorizationException $e) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        });

        $exceptions->render(function (AccessDeniedHttpException $e) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        });

        // ModelNotFoundException is converted to NotFoundHttpException before
        // renderers run — register both: one for missing models, one for
        // undefined API routes (different messages for easier debugging).
        $exceptions->render(function (ModelNotFoundException $e) {
            return ApiResponse::error('Resource not found', [], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e) {
            return ApiResponse::error('Endpoint not found', [], 404);
        });

        // Generic HTTP exceptions (throttle 429, method-not-allowed 405, etc.)
        $exceptions->render(function (HttpException $e) {
            return ApiResponse::error($e->getMessage() ?: 'Error', [], $e->getStatusCode());
        });

        // Sanitize unexpected 500s in production so internals are never exposed.
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->is('api/*') && ! config('app.debug')) {
                return ApiResponse::error('Server error', [], 500);
            }
        });
    })->create();
