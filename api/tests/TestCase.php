<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Location;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
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

    protected function makeOrganization(string $slug, string $currency = 'HTG'): Organization
    {
        return Organization::query()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'base_currency' => $currency,
        ]);
    }

    protected function makeLocation(Organization $org, string $code): Location
    {
        return Location::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'code' => $code,
            'name' => "Boutik {$code}",
        ]);
    }

    /**
     * Crée un utilisateur, son membership et sa portée.
     *
     * @param  list<Location>  $locations  vide = accès à toutes les locations
     */
    protected function makeMember(
        Organization $org,
        string $roleCode,
        array $locations = [],
        ?string $email = null,
    ): Membership {
        $user = User::query()->create([
            'email' => $email ?? strtolower($roleCode).'-'.uniqid().'@nexa.test',
            'password_hash' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => $roleCode,
        ]);

        $role = Role::query()->whereNull('organization_id')->where('code', $roleCode)->sole();

        $membership = Membership::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'role_id' => $role->id,
        ]);

        foreach ($locations as $location) {
            DB::table('membership_locations')->insert([
                'membership_id' => $membership->id,
                'location_id' => $location->id,
            ]);
        }

        return $membership->load('organization');
    }

    /** Active le contexte de ce membership, comme le ferait le middleware. */
    protected function actingAsMember(Membership $membership): Membership
    {
        app(OrgContext::class)->bind($membership);

        return $membership;
    }
}
