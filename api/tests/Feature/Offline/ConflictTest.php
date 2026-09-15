<?php

declare(strict_types=1);

namespace Tests\Feature\Offline;

use App\Domain\Catalog\PriceResolver;
use App\Domain\Sales\CheckoutService;
use App\Domain\Shared\Money;
use App\Models\CashierSession;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use DomainException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Les cas de conflit hors-ligne, un par un.                            [D-09]
 *
 * La règle unique dont ils découlent tous : une vente déjà encaissée n'est
 * JAMAIS rejetée. L'argent est dans le tiroir et la marchandise est partie ;
 * le serveur enregistre et signale, il n'annule pas un fait.
 */
final class ConflictTest extends TestCase
{
    private Location $shop;

    private CashierSession $session;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $org = $this->makeOrganization('ti-machann', 'HTG');
        $this->shop = $this->makeLocation($org, 'DELMAS');
        $this->actingAsMember($this->makeMember($org, 'OWNER'));

        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 0, 3000, $this->shop);
        $this->variant = $item['variant'];
        $this->session = $this->openSession($this->shop);
    }

    private function sell(array $overrides = [], string $origin = 'OFFLINE'): Order
    {
        $line = array_merge(['variant_id' => $this->variant->id, 'quantity' => '1'], $overrides['line'] ?? []);
        $amount = $overrides['paid'] ?? 5000;

        return app(CheckoutService::class)->checkout(
            $overrides['session'] ?? $this->session,
            [$line],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => $amount]],
            clientOrderId: $overrides['client_order_id'] ?? null,
            takenAt: $overrides['taken_at'] ?? null,
            origin: $origin,
        );
    }

    private function conflicts(Order $order): array
    {
        return DB::table('sync_conflicts')->where('order_id', $order->id)->pluck('kind')->all();
    }

    /* ---------------------------------------------------------------- 1 */

    #[Test]
    public function stock_running_out_does_not_stop_the_sale(): void
    {
        $order = $this->sell(['line' => ['quantity' => '150'], 'paid' => 750000]);

        $this->assertSame('COMPLETED', $order->status);
        $this->assertContains('STOCK_NEGATIVE', $this->conflicts($order));
        $this->assertDatabaseHas('stock_discrepancies', ['origin' => 'OFFLINE_SYNC']);
    }

    /* ---------------------------------------------------------------- 2 */

    #[Test]
    public function a_price_changed_while_offline_keeps_the_price_the_customer_paid(): void
    {
        // Le gérant a monté le prix pendant que la caisse était coupée.
        app(PriceResolver::class)->setPrice($this->variant->id, Money::of(6500, 'HTG'));

        $order = $this->sell(['line' => ['quantity' => '1', 'unit_price_minor' => 5000]]);

        $this->assertSame(5000, $order->total_minor, 'le client a payé 50,00, pas 65,00');
        $this->assertContains('PRICE_VARIANCE', $this->conflicts($order));

        $details = json_decode(
            DB::table('sync_conflicts')->where('order_id', $order->id)
                ->where('kind', 'PRICE_VARIANCE')->value('details'),
            true,
        );

        $this->assertSame(5000, $details['charged_minor']);
        $this->assertSame(6500, $details['in_force_minor']);
        $this->assertSame(-1500, $details['difference_minor']);
    }

    #[Test]
    public function selling_at_the_price_in_force_raises_nothing(): void
    {
        $order = $this->sell(['line' => ['quantity' => '1', 'unit_price_minor' => 5000]]);

        $this->assertNotContains('PRICE_VARIANCE', $this->conflicts($order));
    }

    /* ---------------------------------------------------------------- 3 */

    #[Test]
    public function an_item_withdrawn_from_sale_is_still_recorded(): void
    {
        Product::query()->where('id', $this->variant->product_id)->update(['active' => false]);

        $order = $this->sell();

        $this->assertSame('COMPLETED', $order->status);
        $this->assertContains('ARCHIVED_PRODUCT', $this->conflicts($order));
    }

    /* ---------------------------------------------------------------- 4 */

    #[Test]
    public function a_sale_arriving_after_the_close_is_attached_and_the_z_is_amended(): void
    {
        $this->session->forceFill(['status' => 'CLOSED', 'closed_at' => now()])->save();

        $order = $this->sell(['taken_at' => new \DateTimeImmutable('-2 hours')]);

        $this->assertSame('COMPLETED', $order->status);
        $this->assertSame($this->session->id, $order->cashier_session_id);
        $this->assertSame('AMENDED', $this->session->fresh()->status);
        $this->assertContains('SESSION_CLOSED', $this->conflicts($order));
    }

    #[Test]
    public function online_however_refuses_a_closed_session(): void
    {
        // En ligne, rien n'a encore été encaissé : refuser est la bonne
        // réponse, et la caisse n'aurait pas dû vendre là.
        $this->session->forceFill(['status' => 'CLOSED', 'closed_at' => now()])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/clôturée/');

        $this->sell(origin: 'ONLINE');
    }

    /* ---------------------------------------------------------------- 5 */

    #[Test]
    public function replaying_the_same_sale_never_creates_a_second_one(): void
    {
        $this->sell(['client_order_id' => 'POS1-000042']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->sell(['client_order_id' => 'POS1-000042']);
    }

    /* ---------------------------------------------------------------- 6 */

    #[Test]
    public function several_conflicts_on_one_sale_are_all_reported(): void
    {
        // Le cas réaliste après une longue coupure : l'article a été retiré,
        // le prix a bougé, le stock est à sec et la caisse a été clôturée.
        Product::query()->where('id', $this->variant->product_id)->update(['active' => false]);
        app(PriceResolver::class)->setPrice($this->variant->id, Money::of(9000, 'HTG'));
        $this->session->forceFill(['status' => 'CLOSED', 'closed_at' => now()])->save();

        $order = $this->sell([
            'line' => ['quantity' => '150', 'unit_price_minor' => 5000],
            'paid' => 750000,
            'taken_at' => new \DateTimeImmutable('-5 hours'),
        ]);

        $kinds = $this->conflicts($order);

        $this->assertContains('ARCHIVED_PRODUCT', $kinds);
        $this->assertContains('PRICE_VARIANCE', $kinds);
        $this->assertContains('STOCK_NEGATIVE', $kinds);
        $this->assertContains('SESSION_CLOSED', $kinds);
        $this->assertSame('COMPLETED', $order->status, 'la vente passe malgré tout');
    }

    /* ------------------------------------------------------- l'écran gérant */

    #[Test]
    public function the_manager_sees_the_conflicts_explained_and_can_close_them(): void
    {
        $org = \App\Models\Organization::query()->first();
        $manager = $this->makeMember($org, 'MANAGER', [], 'jeran@nexa.test');
        $manager->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();

        $order = $this->sell(['line' => ['quantity' => '150'], 'paid' => 750000]);

        app(\App\Support\Tenancy\OrgContext::class)->forget();
        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'jeran@nexa.test', 'password' => 'modpas-123',
        ])->json('data.token');

        $list = $this->withToken($token)->getJson('/api/v1/sync/conflicts')->assertOk();

        $this->assertSame(1, $list->json('meta.open_total'));
        $this->assertSame('STOCK_NEGATIVE', $list->json('data.0.kind'));
        $this->assertStringContainsString('comptez le rayon', $list->json('data.0.explanation'));
        $this->assertSame($order->order_number, $list->json('data.0.order.number'));

        $id = $list->json('data.0.id');

        $this->withToken($token)->postJson("/api/v1/sync/conflicts/{$id}/resolve")->assertOk();
        $this->withToken($token)->postJson("/api/v1/sync/conflicts/{$id}/resolve")->assertNotFound();

        $this->assertSame(0, $this->withToken($token)
            ->getJson('/api/v1/sync/conflicts')->json('meta.open_total'));
    }

    #[Test]
    public function a_cashier_cannot_see_the_conflicts(): void
    {
        $org = \App\Models\Organization::query()->first();
        $cashier = $this->makeMember($org, 'CASHIER', [], 'kesye@nexa.test');
        $cashier->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();
        app(\App\Support\Tenancy\OrgContext::class)->forget();

        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'kesye@nexa.test', 'password' => 'modpas-123',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/sync/conflicts')->assertForbidden();
    }
}
