<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateToken;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveOrganization;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: null,
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            AssignRequestId::class,
        ]);

        $middleware->alias([
            'auth.token' => AuthenticateToken::class,
            'organization' => ResolveOrganization::class,
            'can.do' => RequirePermission::class,
            'idempotent' => EnsureIdempotency::class,
        ]);

        // ResolveOrganization doit tourner AVANT le binding de route, pour que
        // la résolution de modèle soit elle-même filtrée par l'organisation :
        // un identifiant étranger 404 au lieu d'être chargé puis refusé.
        $middleware->priority([
            AssignRequestId::class,
            AuthenticateToken::class,
            ResolveOrganization::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            RequirePermission::class,
            EnsureIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toute réponse d'erreur passe par la même enveloppe que les succès.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'VALIDATION_FAILED',
                'Les données envoyées sont invalides.',
                422,
                $e->errors(),
            );
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('NOT_FOUND', 'Ressource introuvable.', 404);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('FORBIDDEN', $e->getMessage() ?: 'Action non autorisée.', 403);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                match ($e->getStatusCode()) {
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    409 => 'CONFLICT',
                    429 => 'TOO_MANY_REQUESTS',
                    default => 'HTTP_ERROR',
                },
                $e->getMessage() ?: 'Requête refusée.',
                $e->getStatusCode(),
            );
        });
    })->create();
