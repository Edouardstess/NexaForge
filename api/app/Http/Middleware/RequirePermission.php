<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garde par permission, jamais par rôle.                                [D-03]
 *
 * `->middleware('can.do:order.refund')` — un rôle n'apparaît nulle part dans
 * le code de contrôle, seulement dans le seeder qui en fait un modèle.
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $context = app(OrgContext::class);

        foreach ($permissions as $permission) {
            if (! $context->can($permission)) {
                return ApiResponse::error(
                    'FORBIDDEN',
                    "Permission requise : {$permission}.",
                    403,
                );
            }
        }

        return $next($request);
    }
}
