<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\TokenService;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateToken
{
    public function __construct(private readonly TokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null) {
            return ApiResponse::error('UNAUTHENTICATED', 'Jeton absent.', 401);
        }

        $token = $this->tokens->find($bearer);

        if ($token === null) {
            return ApiResponse::error('UNAUTHENTICATED', 'Jeton invalide ou expiré.', 401);
        }

        $user = $token->user;

        if (! $user->isActive()) {
            return ApiResponse::error('ACCOUNT_SUSPENDED', 'Ce compte est suspendu.', 403);
        }

        $request->attributes->set('access_token', $token);
        $request->setUserResolver(fn () => $user);

        // Écriture asynchrone volontairement absente : une colonne de dernière
        // utilisation ne vaut pas un UPDATE sur le chemin de chaque requête.
        if ($token->last_used_at === null || $token->last_used_at->diffInMinutes(now()) >= 5) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
