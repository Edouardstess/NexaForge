<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Catalog\PriceResolver;
use App\Domain\Sales\CheckoutService;
use App\Models\Location;
use App\Models\Organization;
use App\Support\Tenancy\OrgContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La portée par point de vente, éprouvée depuis l'extérieur.
 *
 * `membership_locations` existe pour qu'un membre rattaché à une boutique ne
 * voie pas les autres. Un filtre d'organisation seul ne suffit pas : les deux
 * boutiques appartiennent au MÊME commerçant, et c'est justement ce cas que la
 * portée est censée couvrir.                                           [D-03]
 */
final class LocationScopeTest extends TestCase
{
    private Organization $org;

    private Location $delmas;

    private Location $petionville;

    private string $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('ti-machann', 'HTG');
        $this->delmas = $this->makeLocation($this->org, 'DELMAS');
        $this->petionville = $this->makeLocation($this->org, 'PETIONVILLE');

        // Le propriétaire vend dans les deux boutiques, pour qu'il y ait
        // quelque chose à voir dans chacune.
        $owner = $this->makeMember($this->org, 'OWNER', [], 'pwopriyete@nexa.test');
        $this->actingAsMember($owner);

        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 0, 3000, $this->delmas);
        $this->variantId = $item['variant']->id;

        foreach ([$this->delmas, $this->petionville] as $shop) {
            DB::transaction(fn () => app(\App\Domain\Inventory\StockService::class)->move(
                locationId: $shop->id,
                variantId: $this->variantId,
                type: 'PURCHASE',
                quantity: '50',
                unitCostMinor: 3000,
                costCurrency: 'HTG',
            ));

            app(CheckoutService::class)->checkout(
                $this->openSession($shop, 'CAISSE-'.$shop->code),
                [['variant_id' => $this->variantId, 'quantity' => '2']],
                [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 10000]],
            );
        }
    }

    /** Un gérant rattaché à Delmas seulement. */
    private function managerOfDelmas(): string
    {
        $manager = $this->makeMember($this->org, 'MANAGER', [$this->delmas], 'jeran@nexa.test');
        $manager->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();
        app(OrgContext::class)->forget();

        return $this->postJson('/api/v1/auth/login', [
            'identifier' => 'jeran@nexa.test', 'password' => 'modpas-123',
        ])->json('data.token');
    }

    #[Test]
    public function a_manager_scoped_to_one_shop_cannot_read_the_other_daily_report(): void
    {
        $token = $this->managerOfDelmas();

        // Sa propre boutique : lisible.
        $this->withToken($token)
            ->getJson('/api/v1/reports/daily?location_id='.$this->delmas->id)
            ->assertOk()
            ->assertJsonPath('data.orders', 1);

        // Celle d'à côté, en nommant simplement son identifiant : refusée.
        $this->withToken($token)
            ->getJson('/api/v1/reports/daily?location_id='.$this->petionville->id)
            ->assertNotFound();
    }

    #[Test]
    public function without_naming_a_shop_the_manager_sees_only_their_own(): void
    {
        $token = $this->managerOfDelmas();

        // Deux ventes existent dans l'organisation, une par boutique.
        $this->withToken($token)->getJson('/api/v1/reports/daily')
            ->assertOk()->assertJsonPath('data.orders', 1);
    }

    #[Test]
    public function the_owner_sees_both_shops(): void
    {
        $owner = \App\Models\Membership::query()
            ->whereHas('user', fn ($q) => $q->where('email', 'pwopriyete@nexa.test'))->sole();
        $owner->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();
        app(OrgContext::class)->forget();

        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'pwopriyete@nexa.test', 'password' => 'modpas-123',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/reports/daily')
            ->assertOk()->assertJsonPath('data.orders', 2);
    }

    #[Test]
    public function a_manager_scoped_to_one_shop_cannot_read_the_other_best_sellers(): void
    {
        $token = $this->managerOfDelmas();

        $this->withToken($token)
            ->getJson('/api/v1/reports/top-products?location_id='.$this->petionville->id)
            ->assertNotFound();
    }

    #[Test]
    public function a_manager_cannot_set_a_price_for_a_shop_they_do_not_run(): void
    {
        // Un prix par point de vente change ce que ce point de vente facture
        // au client : il suit la même portée que le reste.
        $token = $this->managerOfDelmas();

        $this->withToken($token)
            ->postJson("/api/v1/variants/{$this->variantId}/price", [
                'amount_minor' => 1,
                'location_id' => $this->petionville->id,
            ])
            ->assertNotFound();

        $this->assertSame(
            0,
            DB::table('prices')->where('location_id', $this->petionville->id)->count(),
            'aucun prix ne doit avoir été posé sur la boutique voisine',
        );
    }

    #[Test]
    public function a_manager_can_set_a_price_for_their_own_shop(): void
    {
        $token = $this->managerOfDelmas();

        $this->withToken($token)
            ->postJson("/api/v1/variants/{$this->variantId}/price", [
                'amount_minor' => 5500,
                'location_id' => $this->delmas->id,
            ])
            ->assertCreated();

        $this->assertSame(
            5500,
            app(PriceResolver::class)->resolve($this->variantId, $this->delmas->id, 'HTG')->minor,
        );
    }
}
