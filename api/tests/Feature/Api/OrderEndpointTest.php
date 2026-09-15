<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Money\ExchangeRateService;
use App\Models\Location;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrderEndpointTest extends TestCase
{
    private Location $shop;

    private string $token;

    private string $variantId;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $org = $this->makeOrganization('ti-machann', 'HTG');
        $this->shop = $this->makeLocation($org, 'DELMAS');

        $membership = $this->makeMember($org, 'OWNER', [], 'pwopriyete@nexa.test');
        $membership->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();
        $this->actingAsMember($membership);

        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 1000, 3000, $this->shop);
        $this->variantId = $item['variant']->id;
        $this->sessionId = $this->openSession($this->shop)->id;

        $this->token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'pwopriyete@nexa.test',
            'password' => 'modpas-123',
        ])->json('data.token');

        // Le contexte du test est remplacé par celui du middleware sur chaque
        // requête HTTP ; on le libère pour ne pas masquer un oubli de binding.
        app(\App\Support\Tenancy\OrgContext::class)->forget();
    }

    private function sale(string $key, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', array_merge([
                'cashier_session_id' => $this->sessionId,
                'lines' => [['variant_id' => $this->variantId, 'quantity' => '2']],
                'tenders' => [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 10000]],
            ], $overrides));
    }

    #[Test]
    public function a_sale_goes_through_over_http(): void
    {
        $response = $this->sale('POS1-0001');

        $response->assertCreated()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.total_minor', 10000)
            ->assertJsonPath('data.subtotal_minor', 9091)
            ->assertJsonPath('data.tax_minor', 909)
            ->assertJsonPath('data.order_number', 'DELMAS-000001');

        $this->assertCount(1, $response->json('data.items'));
        $this->assertCount(1, $response->json('data.payments'));
    }

    #[Test]
    public function replaying_the_same_idempotency_key_does_not_charge_twice(): void
    {
        // Le cas réel : la caisse perd le réseau après l'envoi, ne voit pas la
        // réponse, et réessaie avec la même clé.                        [D-11]
        $first = $this->sale('POS1-0001')->assertCreated();
        $second = $this->sale('POS1-0001');

        $second->assertCreated()->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::acrossAllOrganizations()->count());
        $this->assertSame(1, DB::table('payments')->count());
    }

    #[Test]
    public function the_same_key_on_a_different_basket_is_refused(): void
    {
        // Ce n'est pas un doublon, c'est un bug du client. Le masquer ferait
        // disparaître une vente.
        $this->sale('POS1-0001')->assertCreated();

        $this->sale('POS1-0001', [
            'lines' => [['variant_id' => $this->variantId, 'quantity' => '5']],
            'tenders' => [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 25000]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, Order::acrossAllOrganizations()->count());
    }

    #[Test]
    public function a_sale_without_an_idempotency_key_is_refused(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/orders', [
            'cashier_session_id' => $this->sessionId,
            'lines' => [['variant_id' => $this->variantId, 'quantity' => '1']],
            'tenders' => [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 5000]],
        ])->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    #[Test]
    public function an_offline_sale_replayed_under_its_client_id_returns_the_existing_sale(): void
    {
        // Deuxième filet, indépendant de la clé d'idempotence : la caisse a pu
        // regénérer une clé après un redémarrage, mais client_order_id, lui,
        // est stable.                                                   [D-09]
        $first = $this->sale('KEY-A', [
            'client_order_id' => 'POS1-000042',
            'origin' => 'OFFLINE',
            'taken_at' => now()->subHours(4)->toIso8601String(),
        ])->assertCreated();

        $second = $this->sale('KEY-B', [
            'client_order_id' => 'POS1-000042',
            'origin' => 'OFFLINE',
            'taken_at' => now()->subHours(4)->toIso8601String(),
        ]);

        $second->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::acrossAllOrganizations()->count());
    }

    #[Test]
    public function an_underpaid_sale_is_refused_with_a_usable_message(): void
    {
        $this->sale('POS1-0009', [
            'tenders' => [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 4000]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'CHECKOUT_REFUSED');

        $this->assertSame(0, Order::acrossAllOrganizations()->count());
    }

    #[Test]
    public function a_sale_on_a_closed_session_is_refused(): void
    {
        DB::table('cashier_sessions')->where('id', $this->sessionId)
            ->update(['status' => 'CLOSED', 'closed_at' => now()]);

        $this->sale('POS1-0010')->assertStatus(409)
            ->assertJsonPath('error.code', 'SESSION_NOT_OPEN');
    }

    #[Test]
    public function a_cashier_cannot_refund_because_the_role_does_not_grant_it(): void
    {
        $org = \App\Models\Organization::query()->first();
        $cashier = $this->makeMember($org, 'CASHIER', [], 'kesye@nexa.test');
        $cashier->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();

        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'kesye@nexa.test', 'password' => 'modpas-123',
        ])->json('data.token');

        // Il peut vendre…
        $this->withToken($token)->withHeader('Idempotency-Key', 'K1')
            ->postJson('/api/v1/orders', [
                'cashier_session_id' => $this->sessionId,
                'lines' => [['variant_id' => $this->variantId, 'quantity' => '1']],
                'tenders' => [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 5000]],
            ])->assertCreated();

        // …mais pas changer un prix.
        $this->withToken($token)->postJson("/api/v1/variants/{$this->variantId}/price", [
            'amount_minor' => 1,
        ])->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    }

    #[Test]
    public function a_mixed_currency_sale_works_end_to_end(): void
    {
        $this->actingAsMember(
            \App\Models\Membership::query()->where('organization_id', $this->shop->organization_id)->first()
        );
        app(ExchangeRateService::class)->record('USD', '132.50', now()->subDay());
        app(\App\Support\Tenancy\OrgContext::class)->forget();

        $response = $this->sale('POS1-0020', [
            'tenders' => [
                ['method' => 'CASH', 'currency' => 'USD', 'amount_minor' => 50],
                ['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 3375],
            ],
        ]);

        $response->assertCreated();

        $payments = $response->json('data.payments');
        $this->assertSame('USD', $payments[0]['currency']);
        $this->assertSame('132.50000000', $payments[0]['fx_rate']);
        $this->assertSame(6625, $payments[0]['base_amount_minor']);
    }

    #[Test]
    public function the_catalog_snapshot_gives_the_cashier_everything_to_go_offline(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/catalog/snapshot?location_id='.$this->shop->id);

        $response->assertOk()->assertJsonPath('data.base_currency', 'HTG');

        $item = collect($response->json('data.items'))
            ->firstWhere('variant_id', $this->variantId);

        $this->assertSame(5000, $item['price_minor']);
        $this->assertSame(1000, $item['tax_rate_bp']);
        $this->assertTrue($item['tax_inclusive']);
        $this->assertSame('100.0000', $item['stock']);
    }

    #[Test]
    public function a_sale_dated_in_the_future_is_refused(): void
    {
        // Horloge de caisse en avance : sans garde, la vente atterrit dans le
        // rapport de demain et le Z d'aujourd'hui ne tombe jamais juste.
        $this->sale('POS1-0030', [
            'taken_at' => now()->addHours(3)->toIso8601String(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function an_offline_sale_priced_before_any_known_price_is_still_accepted(): void
    {
        // La règle fondatrice : l'argent est dans le tiroir. On applique le
        // plus ancien prix connu et on signale au gérant.               [D-09]
        $response = $this->sale('POS1-0040', [
            'client_order_id' => 'c-old',
            'origin' => 'OFFLINE',
            'taken_at' => now()->subYear()->toIso8601String(),
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('sync_conflicts', [
            'order_id' => $response->json('data.id'),
            'kind' => 'PRICE_VARIANCE',
        ]);
    }

    #[Test]
    public function two_sales_in_the_same_second_keep_a_stable_order(): void
    {
        // Sans départage, la pagination répète une ligne et en saute une autre.
        $sameInstant = now()->subMinute()->toIso8601String();

        for ($i = 0; $i < 6; $i++) {
            $this->sale("K{$i}", [
                'client_order_id' => "c{$i}",
                'origin' => 'OFFLINE',          // un horodatage passé, c'est hors-ligne
                'taken_at' => $sameInstant,
            ])->assertCreated();
        }

        $first = $this->withToken($this->token)->getJson('/api/v1/orders')->json('data.*.order_number');
        $again = $this->withToken($this->token)->getJson('/api/v1/orders')->json('data.*.order_number');

        $this->assertSame($first, $again);
        $this->assertSame($first, array_values(array_unique($first)));
        $this->assertSame('DELMAS-000006', $first[0]);
    }

    #[Test]
    public function orders_are_listed_by_the_time_the_cashier_took_them(): void
    {
        // Un horodatage passé, c'est par définition une vente hors-ligne.
        $this->sale('A', ['client_order_id' => 'c1', 'origin' => 'OFFLINE',
            'taken_at' => now()->subHours(6)->toIso8601String()])->assertCreated();
        $this->sale('B', ['client_order_id' => 'c2', 'origin' => 'OFFLINE',
            'taken_at' => now()->subHour()->toIso8601String()])->assertCreated();

        $list = $this->withToken($this->token)->getJson('/api/v1/orders')->assertOk();

        $this->assertSame(2, $list->json('meta.total'));
        // Le plus récent à l'heure de la CAISSE, pas à l'heure d'arrivée.
        $this->assertSame('DELMAS-000002', $list->json('data.0.order_number'));
    }
}
