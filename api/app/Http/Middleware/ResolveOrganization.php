<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Établit le contexte d'organisation.                                   [D-03]
 *
 * L'organisation est choisie par l'en-tête X-Organization, mais elle n'est
 * JAMAIS acceptée telle quelle : elle doit correspondre à un membership actif
 * de l'utilisateur authentifié. Une organisation à laquelle il n'appartient
 * pas renvoie 404, jamais 403 — un 403 confirmerait son existence.
 *
 * Ce middleware tourne AVANT le binding de route, pour que la résolution de
 * modèle soit elle-même filtrée : un identifiant étranger 404 au lieu de
 * charger la ligne puis de la refuser.
 */
final class ResolveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('UNAUTHENTICATED', 'Authentification requise.', 401);
        }

        $requested = $request->header('X-Organization');

        $query = Membership::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->with(['organization', 'user', 'role']);

        $membership = $requested !== null
            ? $query->where('organization_id', $requested)->first()
            : $query->oldest('created_at')->first();

        if ($membership === null) {
            return ApiResponse::error(
                'ORGANIZATION_NOT_FOUND',
                'Organisation introuvable.',
                404,
            );
        }

        if ($membership->organization->status !== 'ACTIVE') {
            return ApiResponse::error(
                'ORGANIZATION_SUSPENDED',
                'Cette organisation est suspendue.',
                403,
            );
        }

        app(OrgContext::class)->bind($membership);

        return $next($request);
    }
}
