<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Location;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\OrgContext;
use Illuminate\Support\Facades\DB;

/** Fixtures partagées entre les deux bases de test. */
trait MakesDomainFixtures
{
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

    /**
     * Un catalogue minimal : une unité, une TCA à 10 %, un produit, sa
     * variante unitaire et un prix TTC.
     *
     * @return array{variant: \App\Models\ProductVariant, product: \App\Models\Product, tax: \App\Models\TaxRate}
     */
    protected function makeCatalogItem(
        string $name = 'Kola',
        int $priceMinor = 5000,
        string $currency = 'HTG',
        int $taxBp = 1000,
        ?int $costMinor = null,
        ?\App\Models\Location $location = null,
    ): array {
        $unit = \App\Models\Unit::query()->firstOrCreate(
            ['code' => 'UNIT'],
            ['name' => 'Inite', 'precision' => 0],
        );

        $tax = \App\Models\TaxRate::query()->firstOrCreate(
            ['code' => 'TCA'.$taxBp],
            ['name' => 'TCA', 'rate_bp' => $taxBp, 'inclusive' => true],
        );

        $product = \App\Models\Product::query()->create([
            'name' => $name,
            'tax_rate_id' => $tax->id,
            'track_stock' => true,
        ]);

        $variant = \App\Models\ProductVariant::query()->create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'sku' => strtoupper($name).'-'.random_int(1000, 9999),
            'name' => 'Inite',
        ]);

        app(\App\Domain\Catalog\PriceResolver::class)
            ->setPrice($variant->id, \App\Domain\Shared\Money::of($priceMinor, $currency));

        if ($costMinor !== null && $location !== null) {
            \Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Domain\Inventory\StockService::class)->move(
                locationId: $location->id,
                variantId: $variant->id,
                type: 'PURCHASE',
                quantity: '100',
                unitCostMinor: $costMinor,
                costCurrency: $currency,
            ));
        }

        return ['variant' => $variant, 'product' => $product, 'tax' => $tax];
    }

    protected function openSession(\App\Models\Location $location, string $register = 'CAISSE-1'): \App\Models\CashierSession
    {
        return \App\Models\CashierSession::query()->create([
            'location_id' => $location->id,
            'register_code' => $register,
            'opened_by' => app(OrgContext::class)->userId(),
            'opened_at' => now(),
            'status' => 'OPEN',
        ]);
    }

    /** Active le contexte de ce membership, comme le ferait le middleware. */
    protected function actingAsMember(Membership $membership): Membership
    {
        app(OrgContext::class)->bind($membership);

        return $membership;
    }
}
