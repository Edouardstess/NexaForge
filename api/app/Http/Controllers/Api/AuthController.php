<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\LoginThrottle;
use App\Domain\Identity\TokenService;
use App\Models\Location;
use App\Models\Membership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthController
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly LoginThrottle $throttle,
    ) {}

    /**
     * L'identifiant est l'email OU le téléphone : en Haïti beaucoup de
     * commerçants n'ont pas d'adresse email.
     */
    public function login(Request $request): JsonResponse
    {
        $input = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $identifier = strtolower(trim($input['identifier']));
        $ip = $request->ip() ?? '0.0.0.0';

        if ($this->throttle->tooManyAttempts($identifier, $ip)) {
            return ApiResponse::error(
                'TOO_MANY_ATTEMPTS',
                'Trop de tentatives. Réessayez plus tard.',
                429,
                ['retry_after' => $this->throttle->secondsUntilRetry($identifier, $ip)],
            );
        }

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        // Le hachage tourne même sans utilisateur : sans cela, le temps de
        // réponse dirait à un attaquant quels identifiants existent.
        $valid = $user !== null
            ? Hash::check($input['password'], $user->password_hash)
            : Hash::check($input['password'], '$2y$12$'.str_repeat('0', 53));

        if (! $valid || $user === null) {
            $this->throttle->record($identifier, $ip, false);

            throw ValidationException::withMessages([
                'identifier' => ['Identifiant ou mot de passe incorrect.'],
            ]);
        }

        if (! $user->isActive()) {
            $this->throttle->record($identifier, $ip, false);

            return ApiResponse::error('ACCOUNT_SUSPENDED', 'Ce compte est suspendu.', 403);
        }

        $this->throttle->record($identifier, $ip, true);
        $this->throttle->clear($identifier, $ip);

        $issued = $this->tokens->issue($user, 'api', $input['device_name'] ?? null);
        $user->forceFill(['last_login_at' => now()])->save();

        return ApiResponse::created([
            'token' => $issued['plainText'],
            'expires_at' => $issued['token']->expires_at?->toIso8601String(),
            'user' => $this->userPayload($user),
            'organizations' => $this->organizationsFor($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('access_token');

        if ($token instanceof PersonalAccessToken) {
            $this->tokens->revoke($token);
        }

        return ApiResponse::noContent();
    }

    public function logoutEverywhere(Request $request): JsonResponse
    {
        $this->tokens->revokeAllFor($request->user());

        return ApiResponse::noContent();
    }

    /**
     * Le profil ET la portée exacte de la session : organisation active,
     * locations accessibles, permissions effectives. Le client ne devine rien.
     */
    public function me(Request $request): JsonResponse
    {
        $context = app(OrgContext::class);
        $membership = $context->membership();

        return ApiResponse::ok([
            'user' => $this->userPayload($request->user()),
            'organization' => [
                'id' => $membership->organization->id,
                'name' => $membership->organization->name,
                'slug' => $membership->organization->slug,
                'base_currency' => $membership->organization->base_currency,
                'timezone' => $membership->organization->timezone,
            ],
            'role' => $membership->role->code,
            // La RESTRICTION (vide = aucune) et les locations réellement
            // accessibles sont deux choses différentes. Un client qui ne
            // reçoit que la restriction ne sait pas où travailler.
            'location_ids' => $context->locationIds(),
            'has_full_location_access' => $context->hasFullLocationAccess(),
            'locations' => $this->accessibleLocations($context),
            'permissions' => $context->permissions(),
            'organizations' => $this->organizationsFor($request->user()),
        ]);
    }

    /**
     * Les points de vente sur lesquels cette session peut travailler.
     *
     * Portée vide = toutes celles de l'organisation ; portée non vide = cet
     * ensemble exactement. Dans les deux cas le client reçoit une liste
     * utilisable, jamais un tableau vide à interpréter.
     *
     * @return list<array{id: string, code: string, name: string, kind: string, display_unit: string}>
     */
    private function accessibleLocations(OrgContext $context): array
    {
        return Location::query()
            ->where('active', true)
            ->when(
                ! $context->hasFullLocationAccess(),
                fn ($q) => $q->whereIn('id', $context->locationIds()),
            )
            ->orderBy('code')
            ->get()
            ->map(fn (Location $l): array => [
                'id' => $l->id,
                'code' => $l->code,
                'name' => $l->name,
                'kind' => $l->kind,
                'display_unit' => $l->display_unit,
            ])
            ->all();
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'locale' => $user->locale,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
        ];
    }

    /** Les organisations auxquelles l'utilisateur appartient, pour le sélecteur. */
    private function organizationsFor(User $user): array
    {
        return Membership::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->with(['organization', 'role'])
            ->get()
            ->map(fn (Membership $m): array => [
                'id' => $m->organization->id,
                'name' => $m->organization->name,
                'slug' => $m->organization->slug,
                'base_currency' => $m->organization->base_currency,
                'role' => $m->role->code,
            ])
            ->values()
            ->all();
    }
}
