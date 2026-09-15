<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Sales\CheckoutService;
use App\Domain\Sales\RefundService;
use App\Models\CashierSession;
use App\Models\Location;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReportTest extends TestCase
{
    private Location $shop;

    private CashierSession $session;

    private string $kola;

    private string $diri;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $org = $this->makeOrganization('ti-machann', 'HTG');
        $this->shop = $this->makeLocation($org, 'DELMAS');

        $membership = $this->makeMember($org, 'OWNER', [], 'pwopriyete@nexa.test');
        $membership->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();
        $this->actingAsMember($membership);

        $this->kola = $this->makeCatalogItem('Kola', 5000, 'HTG', 1000, 3000, $this->shop)['variant']->id;
        $this->diri = $this->makeCatalogItem('Diri', 37500, 'HTG', 0, 30000, $this->shop)['variant']->id;
        $this->session = $this->openSession($this->shop);
    }

    private function sell(string $variantId, string $qty, int $paid, ?string $takenAt = null): Order
    {
        return app(CheckoutService::class)->checkout(
            $this->session,
            [['variant_id' => $variantId, 'quantity' => $qty]],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => $paid]],
            takenAt: $takenAt === null ? null : new \DateTimeImmutable($takenAt),
            origin: $takenAt === null ? 'ONLINE' : 'OFFLINE',
        );
    }

    private function asOwner(): string
    {
        if (! isset($this->token)) {
            app(\App\Support\Tenancy\OrgContext::class)->forget();
            $this->token = $this->postJson('/api/v1/auth/login', [
                'identifier' => 'pwopriyete@nexa.test', 'password' => 'modpas-123',
            ])->json('data.token');
        }

        return $this->token;
    }

    #[Test]
    public function the_daily_report_shows_takings_cost_and_margin(): void
    {
        $this->sell($this->kola, '10', 50000);   // 500,00 TTC · coût 300,00
        $this->sell($this->diri, '2', 75000);    // 750,00 TTC · coût 600,00

        $r = $this->withToken($this->asOwner())
            ->getJson('/api/v1/reports/daily')
            ->assertOk()->json('data');

        $this->assertSame(2, $r['orders']);
        $this->assertSame(125000, $r['gross_minor']);
        $this->assertSame(90000, $r['cost_minor']);
        $this->assertSame(35000, $r['margin_minor']);
        $this->assertSame(2800, $r['margin_bp']);        // 28,00 %
        $this->assertSame(62500, $r['average_basket_minor']);
        // 500,00 TTC dont TCA 10 % ⇒ 45,45 de taxe ; le diri est exempt.
        $this->assertSame(4545, $r['tax_minor']);
    }

    #[Test]
    public function a_refund_lowers_the_takings_and_the_margin(): void
    {
        $order = $this->sell($this->kola, '10', 50000);

        app(RefundService::class)->refund(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => '4']],
            'Retounen',
        );

        $r = $this->withToken($this->asOwner())
            ->getJson('/api/v1/reports/daily')
            ->assertOk()->json('data');

        $this->assertSame(50000, $r['gross_minor']);
        $this->assertSame(20000, $r['refunded_minor']);
        $this->assertSame(30000, $r['net_minor']);
        // Le coût suit les articles restés partis : 6 × 30,00.
        $this->assertSame(18000, $r['cost_minor']);
        $this->assertSame(12000, $r['margin_minor']);
    }

    #[Test]
    public function an_offline_sale_counts_on_the_day_the_cashier_took_it(): void
    {
        // Le point de D-09 : une vente prise hier et synchronisée aujourd'hui
        // appartient au chiffre d'HIER.
        //
        // Les deux dates sont calculées sur l'horloge DU COMMERCE : à 3h UTC
        // il est encore la veille à Port-au-Prince, et un « hier » calculé en
        // UTC tomberait sur la même journée locale.
        $shopNow = now()->setTimezone('America/Port-au-Prince');
        $yesterdayLocal = $shopNow->copy()->subDay()->setTime(14, 0);

        $this->sell($this->kola, '2', 10000);
        $this->sell($this->kola, '3', 15000, $yesterdayLocal->copy()->utc()->toIso8601String());

        $today = $this->withToken($this->asOwner())
            ->getJson('/api/v1/reports/daily?date='.$shopNow->toDateString())->json('data');
        $yesterday = $this->withToken($this->asOwner())
            ->getJson('/api/v1/reports/daily?date='.$yesterdayLocal->toDateString())->json('data');

        $this->assertSame(1, $today['orders']);
        $this->assertSame(10000, $today['gross_minor']);
        $this->assertSame(1, $yesterday['orders']);
        $this->assertSame(15000, $yesterday['gross_minor']);
    }

    #[Test]
    public function the_day_runs_on_the_shop_clock_not_utc(): void
    {
        // 23h30 à Port-au-Prince, c'est déjà le lendemain en UTC. La vente
        // doit rester dans la journée du commerçant.
        $localLateEvening = now()->setTimezone('America/Port-au-Prince')
            ->subDay()->setTime(23, 30);

        $this->sell($this->kola, '1', 5000, $localLateEvening->copy()->utc()->toIso8601String());

        $report = $this->withToken($this->asOwner())
            ->getJson('/api/v1/reports/daily?date='.$localLateEvening->toDateString())
            ->json('data');

        $this->assertSame('America/Port-au-Prince', $report['timezone']);
        $this->assertSame(1, $report['orders']);
    }

    #[Test]
    public function the_best_sellers_are_net_of_returns(): void
    {
        $order = $this->sell($this->kola, '10', 50000);
        $this->sell($this->diri, '3', 112500);

        app(RefundService::class)->refund(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => '8']],
            'Retounen',
        );

        $top = $this->withToken($this->asOwner())
            ->getJson('/api/v1/reports/top-products')
            ->assertOk()->json('data');

        // 10 Kola vendus moins 8 rendus = 2 ; le diri passe devant.
        $this->assertSame('Diri — Inite', $top[0]['name']);
        $this->assertSame('3.0000', $top[0]['quantity']);
        $this->assertSame('2.0000', $top[1]['quantity']);
        $this->assertSame(10000, $top[1]['revenue_minor']);
    }

    #[Test]
    public function the_stock_value_is_counted_at_purchase_cost(): void
    {
        $r = $this->withToken($this->asOwner())
            ->getJson('/api/v1/reports/stock-value?location_id='.$this->shop->id)
            ->assertOk()->json('data');

        // 100 Kola à 30,00 plus 100 Diri à 300,00.
        $this->assertSame(3300000, $r['total_value_minor']);
        $this->assertSame(0, $r['negative_lines']);
    }

    #[Test]
    public function the_z_report_subtracts_cash_refunds_from_the_expected_drawer(): void
    {
        // Sans cela, un commerçant qui rembourse voit un excédent fantôme
        // chaque soir — et un écart inexplicable finit par être ignoré.
        DB::table('cashier_session_totals')->insert([
            'cashier_session_id' => $this->session->id,
            'currency' => 'HTG',
            'opening_minor' => 100000,
            'expected_minor' => 100000,
        ]);

        $order = $this->sell($this->kola, '10', 50000);   // 500,00 encaissés

        app(RefundService::class)->refund(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => '4']],
            'Retounen',
            true,
            'CASH',
        );                                                 // 200,00 rendus

        $closed = $this->withToken($this->asOwner())
            ->postJson("/api/v1/cashier-sessions/{$this->session->id}/close", [
                // 1 000,00 + 500,00 − 200,00 = 1 300,00
                'counted' => [['currency' => 'HTG', 'amount_minor' => 130000]],
            ])->assertOk()->json('data.totals.HTG');

        $this->assertSame(130000, $closed['expected_minor']);
        $this->assertSame(0, $closed['variance_minor'], 'aucun excédent fantôme');
    }

    #[Test]
    public function a_cashier_cannot_read_the_reports(): void
    {
        $org = \App\Models\Organization::query()->first();
        $cashier = $this->makeMember($org, 'CASHIER', [], 'kesye@nexa.test');
        $cashier->user->forceFill(['password_hash' => bcrypt('modpas-123')])->save();
        app(\App\Support\Tenancy\OrgContext::class)->forget();

        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'kesye@nexa.test', 'password' => 'modpas-123',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/reports/daily')
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    }
}
