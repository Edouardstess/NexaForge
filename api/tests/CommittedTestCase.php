<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Tenancy\OrgContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Base pour les tests qui ont besoin de données RÉELLEMENT committées.
 *
 * RefreshDatabase enveloppe chaque test dans une transaction annulée à la
 * fin : pratique, mais alors aucune autre connexion ne voit les lignes, et
 * DB::transactionLevel() vaut déjà 1. Un test de verrouillage ou de garde
 * transactionnelle écrit ainsi ne prouve rien.
 *
 * Ici les données sont committées et les tables vidées après coup.
 */
abstract class CommittedTestCase extends BaseTestCase
{
    use \Tests\Concerns\MakesDomainFixtures;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$migrated = true;
        }

        $this->truncateAll();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        app(OrgContext::class)->forget();
        $this->truncateAll();

        parent::tearDown();
    }

    private function truncateAll(): void
    {
        $tables = collect(DB::select(<<<'SQL'
            SELECT tablename FROM pg_tables
             WHERE schemaname = 'public' AND tablename <> 'migrations'
        SQL))->pluck('tablename');

        if ($tables->isEmpty()) {
            return;
        }

        // Les règles DO INSTEAD NOTHING bloquent DELETE sur les tables
        // append-only ; TRUNCATE passe outre, ce qui est exactement ce qu'on
        // veut entre deux tests et jamais en production.
        DB::statement('TRUNCATE TABLE '.$tables->map(fn ($t) => '"'.$t.'"')->implode(', ').' CASCADE');
    }
}
