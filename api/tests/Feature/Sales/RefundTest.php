<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Inventory\StockService;
use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Ledger\LedgerService;
use App\Domain\Money\ExchangeRateService;
use App\Domain\Sales\CheckoutService;
use App\Domain\Sales\RefundService;
use App\Models\CashierSession;
use App\Models\Location;
use App\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RefundTest extends TestCase
{
    private Location $shop;

    private CashierSession $session;

    private string $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        $org = $this->makeOrganization('ti-machann', 'HTG');
        $this->shop = $this->makeLocation($org, 'DELMAS');
        $this->actingAsMember($this->makeMember($org, 'OWNER'));

        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 1000, 3000, $this->shop);
        $this->variantId = $item['variant']->id;
        $this->session = $this->openSession($this->shop);
    }

    private function sell(string $quantity = '4'): Order
    {
        return app(CheckoutService::class)->checkout(
            $this->session,
            [['variant_id' => $this->variantId, 'quantity' => $quantity]],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 5000 * (int) $quantity]],
        );
    }

    private function refund(Order $order, string $quantity, bool $restock = true): array
    {
        return app(RefundService::class)->refund(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => $quantity]],
            'Kliyan an pa t vle l',
            $restock,
        );
    }

    #[Test]
    public function a_partial_refund_returns_the_proportional_amount(): void
    {
        $order = $this->sell('4');   // 200,00 HTG TTC

        $refund = $this->refund($order, '1');

        $this->assertSame(5000, $refund['amount_minor']);
        $this->assertSame('PARTIALLY_REFUNDED', $refund['order_status']);
        $this->assertSame(5000, $refund['order_refunded_minor']);
    }

    #[Test]
    public function refunding_everything_closes_the_order(): void
    {
        $order = $this->sell('4');

        $refund = $this->refund($order, '4');

        $this->assertSame(20000, $refund['amount_minor']);
        $this->assertSame('REFUNDED', $refund['order_status']);
    }

    #[Test]
    public function a_refunded_item_goes_back_on_the_shelf(): void
    {
        $order = $this->sell('4');

        $this->assertSame('96.0000', app(StockService::class)->quantityAt($this->shop->id, $this->variantId));

        $this->refund($order, '2');

        $this->assertSame('98.0000', app(StockService::class)->quantityAt($this->shop->id, $this->variantId));
        $this->assertDatabaseHas('stock_movements', ['type' => 'RETURN', 'quantity' => 2]);
    }

    #[Test]
    public function a_damaged_item_is_refunded_without_going_back_on_the_shelf(): void
    {
        $order = $this->sell('4');

        $this->refund($order, '2', restock: false);

        $this->assertSame('96.0000', app(StockService::class)->quantityAt($this->shop->id, $this->variantId));
        $this->assertDatabaseMissing('stock_movements', ['type' => 'RETURN']);
    }

    #[Test]
    public function you_cannot_hand_back_more_than_was_sold(): void
    {
        $order = $this->sell('2');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/rembours/');

        $this->refund($order, '3');
    }

    #[Test]
    public function two_partial_refunds_cannot_exceed_the_quantity_sold(): void
    {
        $order = $this->sell('3');

        $this->refund($order, '2');

        try {
            $this->refund($order->fresh(['items']), '2');
            $this->fail('Le deuxième remboursement aurait dû être refusé.');
        } catch (DomainException) {
            // attendu
        }

        // La base le garantit aussi, pas seulement le service.
        $this->assertSame('2.0000', DB::table('order_items')->value('refunded_quantity'));
    }

    #[Test]
    public function the_ledger_stays_balanced_after_a_refund(): void
    {
        $order = $this->sell('4');
        $ledger = app(LedgerService::class);

        $this->refund($order, '1');

        $this->assertSame([], $ledger->findUnbalanced());

        // 200,00 encaissés moins 50,00 rendus.
        $this->assertSame(15000, $ledger->balance(ChartOfAccounts::CASH_DRAWER, 'HTG', $this->shop->id)->minor);

        // Le chiffre d'affaires et la TCA reculent d'un quart chacun.
        $this->assertSame(-13637, $ledger->balance(ChartOfAccounts::SALES, 'HTG', $this->shop->id)->minor);
        $this->assertSame(-1363, $ledger->balance(ChartOfAccounts::TAX_PAYABLE, 'HTG', $this->shop->id)->minor);

        // Le stock revient, le coût des marchandises vendues recule.
        $this->assertSame(9000, $ledger->balance(ChartOfAccounts::COGS, 'HTG', $this->shop->id)->minor);
        $this->assertSame(-9000, $ledger->balance(ChartOfAccounts::INVENTORY, 'HTG', $this->shop->id)->minor);
    }

    #[Test]
    public function the_refund_replays_the_rate_of_the_sale_not_of_today(): void
    {
        // Le cas qui compte en Haïti : le taux a bougé entre la vente et le
        // retour. Le client doit récupérer ce qu'il a payé.             [D-04]
        $rates = app(ExchangeRateService::class);
        $rates->record('USD', '132.50', now()->subDays(3));

        $order = app(CheckoutService::class)->checkout(
            $this->session,
            [['variant_id' => $this->variantId, 'quantity' => '2']],
            [['method' => 'CASH', 'currency' => 'USD', 'amount_minor' => 76]],
        );

        $rates->record('USD', '160.00', now());

        $refund = $this->refund($order, '1');

        $this->assertSame('132.50000000', $refund['fx_rate']);
    }

    #[Test]
    public function a_refund_given_by_moncash_does_not_empty_the_cash_drawer(): void
    {
        $order = $this->sell('4');

        app(RefundService::class)->refund(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => '1']],
            'Ranbouse pa MonCash',
            true,
            'MONCASH',
        );

        $ledger = app(LedgerService::class);

        // Le tiroir garde ses 200,00 : l'argent est sorti par MonCash.
        $this->assertSame(20000, $ledger->balance(ChartOfAccounts::CASH_DRAWER, 'HTG', $this->shop->id)->minor);
        $this->assertSame(-5000, $ledger->balance(ChartOfAccounts::MONCASH, 'HTG', $this->shop->id)->minor);
        $this->assertSame([], $ledger->findUnbalanced());
    }

    #[Test]
    public function the_refund_is_written_line_by_line_and_emits_an_event(): void
    {
        $order = $this->sell('4');
        $refund = $this->refund($order, '2');

        $this->assertDatabaseHas('refund_lines', [
            'refund_id' => $refund['id'],
            'quantity' => 2,
            'amount_minor' => 10000,
            'restocked' => true,
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'RefundCreated',
            'aggregate_id' => $order->id,
        ]);
    }

    #[Test]
    public function a_voided_order_is_not_refundable(): void
    {
        $order = $this->sell('2');
        $order->forceFill(['status' => 'VOIDED'])->save();

        $this->expectException(DomainException::class);
        $this->refund($order, '1');
    }
}
