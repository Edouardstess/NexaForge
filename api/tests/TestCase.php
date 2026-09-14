<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Tenancy\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use \Tests\Concerns\MakesDomainFixtures;
    use RefreshDatabase;

    /**
     * Les tests tournent sur PostgreSQL, jamais SQLite : les index partiels,
     * les contraintes EXCLUDE, les règles DO INSTEAD NOTHING et le trigger
     * différé du ledger n'existent que là. Un test vert sur SQLite ne
     * prouverait rien de ce qui compte ici.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        app(OrgContext::class)->forget();

        parent::tearDown();
    }
}
