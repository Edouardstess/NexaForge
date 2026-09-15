<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\PriceResolver;
use App\Domain\Inventory\StockService;
use App\Domain\Money\ExchangeRateService;
use App\Domain\Shared\Money;
use App\Models\Category;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Support\Tenancy\OrgContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Un commerce de démonstration : Ti Machann Delmas 33.
 *
 * Les données passent par les vrais services, jamais par des insertions
 * brutes : un stock arrive par une réception, un prix par PriceResolver.
 * Ainsi la démo est arithmétiquement cohérente avec ce que l'application
 * produirait elle-même, et un bug dans un service casse le seed avant de
 * casser un client.
 */
final class DemoSeeder extends Seeder
{
    /** [nom, conditionnement, prix TTC en centimes, catégorie, TCA bp, coût, stock] */
    private const CATALOGUE = [
        ['Diri Tchako',   'sak 5 lb',     37500, 'Manje',    0,    30000, 42],
        ['Pwa nwa',       '1 lb',         14500, 'Manje',    0,    11000, 31],
        ['Mayi moulen',   '3 lb',         15000, 'Manje',    0,    11500, 18],
        ['Spaghetti',     '200 g',         6000, 'Manje',    0,     4200, 64],
        ['Lwil',          '1 L',          42500, 'Manje',    1000, 34000, 12],
        ['Sik ble',       '2 lb',         12500, 'Manje',    0,     9500, 27],
        ['Mamba',         'pòt 350 g',    25000, 'Manje',    1000, 18000, 9],
        ['Aransò',        'inite',         7500, 'Manje',    0,     5200, 55],
        ['Lèt an poud',   '400 g',        55000, 'Manje',    1000, 44000, 6],
        ['Biskwit Toto',  'paket',         2500, 'Manje',    1000,  1700, 120],
        ['Kola Kouwòn',   '50 cl',         5000, 'Bwason',   1000,  3400, 88],
        ['Prestij',       '33 cl',        10000, 'Bwason',   1000,  7200, 46],
        ['Dlo trete',     '5 galon',       9000, 'Bwason',   0,     6000, 23],
        ['Kafe moulen',   '250 g',        22500, 'Bwason',   1000, 16000, 14],
        ['Savon lesiv',   'bar',           4500, 'Kay',      1000,  3100, 73],
        ['Chabon',        'sak',          50000, 'Kay',      0,    38000, 8],
        ['Bouji',         'paket 6',       7500, 'Kay',      1000,  5000, 35],
        ['Klowòks',       '1 L',          11000, 'Kay',      1000,  7800, 19],
        ['Siman',         'sak 42,5 kg',  75000, 'Materyèl', 1000, 63000, 4],
        ['Klou 3 pous',   '1 lb',          9500, 'Materyèl', 1000,  6500, 26],
        ['Fil elektrik',  'mèt',           6500, 'Materyèl', 1000,  4300, 60],
        ['Penti blan',    '1 galon',     135000, 'Materyèl', 1000,108000, 3],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder ne tourne jamais en production.');
        }

        $this->call(PermissionSeeder::class);

        $org = Organization::query()->firstOrCreate(
            ['slug' => 'ti-machann'],
            ['name' => 'Ti Machann Delmas 33', 'base_currency' => 'HTG', 'country' => 'HT'],
        );

        $owner = $this->member($org, 'OWNER', 'pwopriyete@nexa.test', 'Edouard', 'Estess');
        $this->member($org, 'CASHIER', 'kesye@nexa.test', 'Mirlande', 'Joseph');
        $this->member($org, 'MANAGER', 'jeran@nexa.test', 'Ricardo', 'Pierre');

        // À partir d'ici tout passe par le contexte, comme une vraie requête.
        app(OrgContext::class)->bind($owner->load('organization'));

        $shop = Location::query()->firstOrCreate(
            ['organization_id' => $org->id, 'code' => 'DELMAS'],
            ['name' => 'Delmas 33', 'kind' => 'STORE', 'display_unit' => 'HTG'],
        );

        app(ExchangeRateService::class)->record('USD', '132.50', now()->subDays(2));

        $unit = Unit::query()->firstOrCreate(
            ['organization_id' => $org->id, 'code' => 'UNIT'],
            ['name' => 'Inite', 'precision' => 0],
        );

        $taxed = TaxRate::query()->firstOrCreate(
            ['organization_id' => $org->id, 'code' => 'TCA'],
            ['name' => 'TCA 10%', 'rate_bp' => 1000, 'inclusive' => true],
        );
        $exempt = TaxRate::query()->firstOrCreate(
            ['organization_id' => $org->id, 'code' => 'EXEMPT'],
            ['name' => 'Egzante', 'rate_bp' => 0, 'inclusive' => true],
        );

        $prices = app(PriceResolver::class);
        $stock = app(StockService::class);

        foreach (self::CATALOGUE as [$name, $size, $price, $categoryName, $taxBp, $cost, $onHand]) {
            $category = Category::query()->firstOrCreate(
                ['organization_id' => $org->id, 'parent_id' => null, 'name' => $categoryName],
            );

            $product = Product::query()->firstOrCreate(
                ['organization_id' => $org->id, 'name' => $name],
                [
                    'category_id' => $category->id,
                    'tax_rate_id' => ($taxBp > 0 ? $taxed : $exempt)->id,
                    'track_stock' => true,
                ],
            );

            // Translittérer avant de nettoyer : sinon « Kouwòn » devient
            // KOLA-KOUW-N, avec un tiré à la place de l'accent. Un commerçant
            // lit ces codes sur ses étiquettes.
            $ascii = \Illuminate\Support\Str::ascii($name);
            $sku = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $ascii), '-'));

            $variant = ProductVariant::query()->firstOrCreate(
                ['organization_id' => $org->id, 'sku' => $sku],
                ['product_id' => $product->id, 'unit_id' => $unit->id, 'name' => $size],
            );

            if (! $variant->wasRecentlyCreated) {
                continue;
            }

            $prices->setPrice($variant->id, Money::of($price, 'HTG'));

            // Le stock arrive par une réception, avec son coût : c'est ce qui
            // rend la marge des rapports vraie.
            DB::transaction(fn () => $stock->move(
                locationId: $shop->id,
                variantId: $variant->id,
                type: 'PURCHASE',
                quantity: (string) $onHand,
                unitCostMinor: $cost,
                costCurrency: 'HTG',
            ));
        }

        app(OrgContext::class)->forget();

        $this->command?->info('Ti Machann Delmas 33 — '.count(self::CATALOGUE).' atik, stòk resevwa.');
        $this->command?->info('pwopriyete@nexa.test / kesye@nexa.test / jeran@nexa.test — modpas: demo1234');
    }

    private function member(Organization $org, string $roleCode, string $email, string $first, string $last): Membership
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'password_hash' => bcrypt('demo1234'),
                'first_name' => $first,
                'last_name' => $last,
                'locale' => 'ht',
            ],
        );

        $role = Role::query()->whereNull('organization_id')->where('code', $roleCode)->sole();

        return Membership::query()->firstOrCreate(
            ['user_id' => $user->id, 'organization_id' => $org->id],
            ['role_id' => $role->id],
        );
    }
}
