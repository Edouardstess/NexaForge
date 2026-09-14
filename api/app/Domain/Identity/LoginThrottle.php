<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use Illuminate\Support\Facades\DB;

/**
 * Limitation des tentatives sur le couple (identifiant, IP).
 *
 * Volontairement PAS sur l'identifiant seul : sinon un attaquant verrouille
 * le compte d'un commerçant en échouant depuis n'importe où, ce qui
 * transforme une protection en déni de service.
 */
final class LoginThrottle
{
    private const MAX_ATTEMPTS = 5;

    private const WINDOW_MINUTES = 15;

    public function tooManyAttempts(string $identifier, string $ip): bool
    {
        return $this->recentFailures($identifier, $ip) >= self::MAX_ATTEMPTS;
    }

    public function secondsUntilRetry(string $identifier, string $ip): int
    {
        $oldest = DB::table('login_attempts')
            ->where('identifier', $identifier)
            ->where('ip_address', $ip)
            ->where('successful', false)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->min('created_at');

        if ($oldest === null) {
            return 0;
        }

        $elapsed = (int) now()->diffInSeconds(\Illuminate\Support\Carbon::parse($oldest), absolute: true);

        return max(0, self::WINDOW_MINUTES * 60 - $elapsed);
    }

    public function record(string $identifier, string $ip, bool $successful): void
    {
        DB::table('login_attempts')->insert([
            'identifier' => $identifier,
            'ip_address' => $ip,
            'successful' => $successful,
            'created_at' => now(),
        ]);
    }

    public function clear(string $identifier, string $ip): void
    {
        DB::table('login_attempts')
            ->where('identifier', $identifier)
            ->where('ip_address', $ip)
            ->where('successful', false)
            ->delete();
    }

    private function recentFailures(string $identifier, string $ip): int
    {
        return DB::table('login_attempts')
            ->where('identifier', $identifier)
            ->where('ip_address', $ip)
            ->where('successful', false)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->count();
    }
}
