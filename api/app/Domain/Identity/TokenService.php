<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Jetons porteurs opaques.
 *
 * Seul le SHA-256 est stocké : une fuite de la table ne donne à personne un
 * jeton utilisable. La valeur en clair n'est visible qu'une fois, au moment
 * où elle est créée.
 */
final class TokenService
{
    public const TTL_DAYS = 30;

    /** @return array{token: PersonalAccessToken, plainText: string} */
    public function issue(User $user, string $name, ?string $deviceName = null): array
    {
        $plainText = Str::random(64);

        $token = PersonalAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token' => hash('sha256', $plainText),
            'device_name' => $deviceName,
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return ['token' => $token, 'plainText' => $plainText];
    }

    public function find(string $plainText): ?PersonalAccessToken
    {
        if (strlen($plainText) !== 64) {
            return null;
        }

        $token = PersonalAccessToken::query()
            ->where('token', hash('sha256', $plainText))
            ->first();

        return $token?->isUsable() === true ? $token : null;
    }

    public function revoke(PersonalAccessToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }

    public function revokeAllFor(User $user): void
    {
        PersonalAccessToken::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
