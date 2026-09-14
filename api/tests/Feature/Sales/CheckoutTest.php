<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Inventory\StockService;
use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Ledger\LedgerService;
use App\Domain\Money\ExchangeRateService;
use App\Domain\Sales\CheckoutService;
use App\Domain\Shared\Money;
use App\Models\Location;
use App\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CheckoutTest extends TestCase
{
    private Location $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $org = $this->makeOrganization('ti-machann', 'HTG');
        $this->shop = $this->makeLocation($org, 'DELMAS');
        $this->actingAsMember($this->makeMember($org, 'OWNER'));
    }

    private function checkout(): CheckoutService
    {
        return app(CheckoutService::class);
    }

    #[Test]
    public function a_cash_sale_in_gourdes_is_recorded_completely(): void
    {
        // 50,00 HTG TTC, TCA 10 % ⇒ base 45,45 + taxe 4,55
        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 1000, 3000, $this->shop);
        $session = $this->openSession($this->shop);

        $order = $this->checkout()->checkout(
            $session,
            [['variant_id' => $item['variant']->id, 'quantity' => '2']],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 10000]],
        );

        $this->assertSame('COMPLETED', $order->status);
        $this->assertSame(10000, $order->total_minor);
        $this->assertSame(9091, $order->subtotal_minor);
        $this->assertSame(909, $order->tax_minor);
        $this->assertSame('DELMAS-000001', $order->order_number);

        // subtotal - remise + taxe = total, garanti aussi par un CHECK en base
        $this->assertSame(
            $order->total_minor,
            $order->subtotal_minor - $order->discount_minor + $order->tax_minor,
        );
    }

    #[Test]
    public function the_sale_deducts_stock_in_the_same_transaction(): void
    {
        $item = $this->makeCatalogItem('Diri', 25000, 'HTG', 1000, 18000, $this->shop);
        $session = $this->openSession($this->shop);

        $this->checkout()->checkout(
            $session,
            [['variant_id' => $item['variant']->id, 'quantity' => '3']],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 75000]],
        );

        $this->assertSame(
            '97.0000',
            app(StockService::class)->quantityAt($this->shop->id, $item['variant']->id),
        );
    }

    #[Test]
    public function the_ledger_balances_and_carries_the_whole_sale(): void
    {
        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 1000, 3000, $this->shop);
        $session = $this->openSession($this->shop);

        $order = $this->checkout()->checkout(
            $session,
            [['variant_id' => $item['variant']->id, 'quantity' => '2']],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 10000]],
        );

        $ledger = app(LedgerService::class);

        $this->assertSame([], $ledger->findUnbalanced());
        $this->assertSame(10000, $ledger->balance(ChartOfAccounts::CASH_DRAWER, 'HTG', $this->shop->id)->minor);
        $this->assertSame(-9091, $ledger->balance(ChartOfAccounts::SALES, 'HTG', $this->shop->id)->minor);
        $this->assertSame(-909, $ledger->balance(ChartOfAccounts::TAX_PAYABLE, 'HTG', $this->shop->id)->minor);
        $this->assertSame(6000, $ledger->balance(ChartOfAccounts::COGS, 'HTG', $this->shop->id)->minor);
        $this->assertSame(-6000, $ledger->balance(ChartOfAccounts::INVENTORY, 'HTG', $this->shop->id)->minor);

        $this->assertDatabaseHas('ledger_transactions', [
            'reference_type' => 'order',
            'reference_id' => $order->id,
        ]);
    }

    #[Test]
    public function a_sale_paid_partly_in_dollars_and_partly_in_gourdes(): void
    {
        // Le cas normal à Port-au-Prince, pas l'exception.
        app(ExchangeRateService::class)->record('USD', '132.50', now()->subDay());

        $item = $this->makeCatalogItem('Siman', 100000, 'HTG', 0, 70000, $this->shop);
        $session = $this->openSession($this->shop);

        // 1 000,00 HTG dus ; 5,00 USD = 662,50 HTG, complétés par 337,50 HTG
        $order = $this->checkout()->checkout(
            $session,
            [['variant_id' => $item['variant']->id, 'quantity' => '1']],
            [
                ['method' => 'CASH', 'currency' => 'USD', 'amount_minor' => 500],
                ['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 33750],
            ],
        );

        $this->assertSame(100000, $order->total_minor);

        $payments = $order->payments()->orderBy('created_at')->get();
        $this->assertSame('USD', $payments[0]->currency);
        $this->assertSame(66250, $payments[0]->base_amount_minor);
        $this->assertSame('132.50000000', $payments[0]->fx_rate);

        // Le ledger reste équilibré PAR DEVISE grâce au compte de position.
        $ledger = app(LedgerService::class);
        $this->assertSame([], $ledger->findUnbalanced());
        $this->assertSame(500, $ledger->balance(ChartOfAccounts::CASH_DRAWER, 'USD', $this->shop->id)->minor);
        $this->assertSame(33750, $ledger->balance(ChartOfAccounts::CASH_DRAWER, 'HTG', $this->shop->id)->minor);
    }

    #[Test]
    public function the_exchange_rate_is_frozen_on_the_payment(): void
    {
        $rates = app(ExchangeRateService::class);
        $rates->record('USD', '132.50', now()->subDays(2));

        $item = $this->makeCatalogItem('Siman', 100000, 'HTG', 0, 70000, $this->shop);
        $session = $this->openSession($this->shop);

        $order = $this->checkout()->checkout(
            $session,
            [['variant_id' => $item['variant']->id, 'quantity' => '1']],
            [['method' => 'CASH', 'currency' => 'USD', 'amount_minor' => 800]],
        );

        $frozen = $order->payments()->first()->fx_rate;

        // Le taux bouge après la vente : la ligne ne doit pas bouger avec lui,
        // sinon les rapports d'hier changent aujourd'hui.
        $rates->record('USD', '145.00', now());

        $this->assertSame($frozen, $order->payments()->first()->fresh()->fx_rate);
        $this->assertSame('132.50000000', $frozen);
    }

    #[Test]
    public function change_is_given_back_in_the_accounting_currency(): void
    {
        $item = $this->makeCatalogItem('Pen', 2500, 'HTG', 0, 1500, $this->shop);
        $session = $this->openSession($this->shop);

        $order = $this->checkout()->checkout(
            $session,
            [['variant_id' => $item['variant']->id, 'quantity' => '1']],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 10000]],
        );

        $payment = $order->payments()->first();
        $this->assertSame(7500, $payment->change_minor);

        // Le tiroir ne garde que le net : 100,00 reçus moins 75,00 rendus.
        $ledger = app(LedgerService::class);
        $this->assertSame(2500, $ledger->balance(ChartOfAccounts::CASH_DRAWER, 'HTG', $this->shop->id)->minor);
        $this->assertSame([], $ledger->findUnbalanced());
    }

    #[Test]
    public function an_underpaid_sale_is_refused_before_anything_is_written(): void
    {
        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 1000, 3000, $this->shop);
        $session = $this->openSession($this->shop);

        try {
            $this->checkout()->checkout(
                $session,
                [['variant_id' => $item['variant']->id, 'quantity' => '2']],
                [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 5000]],
            );
            $this->fail('Une vente sous-payée aurait dû être refusée.');
        } catch (DomainException) {
            // attendu
        }

        // Rien de partiel n'a survécu au ROLLBACK.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, DB::table('order_items')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('ledger_transactions')->count());
        $this->assertSame(
            '100.0000',
            app(StockService::class)->quantityAt($this->shop->id, $item['variant']->id),
        );
    }

    #[Test]
    public function an_offline_sale_keeps_the_price_the_customer_actually_paid(): void
    {
        // Le prix a changé pendant que la caisse était hors-ligne. Le serveur
        // n'a pas autorité pour réécrire ce que le client a payé.      [D-09]
        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 0, 3000, $this->shop);
        $session = $this->openSession($this->shop);

        $order = $this->checkout()->checkout(
            $session,
            [['variant_id' => $item['variant']->id, 'quantity' => '1', 'unit_price_minor' => 4000]],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 4000]],
            clientOrderId: 'POS1-000042',
            takenAt: now()->subHours(6),
            origin: 'OFFLINE',
        );

        $this->assertSame(4000, $order->total_minor);
        $this->assertSame('OFFLINE', $order->origin);
        $this->assertSame(4000, $order->items()->first()->unit_price_minor);

        // taken_at porte l'heure de la CAISSE, pas celle de la synchronisation.
        $this->assertTrue($order->taken_at->lessThan($order->created_at));
    }

    #[Test]
    public function a_replayed_offline_sale_cannot_create_a_duplicate(): void
    {
        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 0, 3000, $this->shop);
        $session = $this->openSession($this->shop);

        $sale = fn () => $this->checkout()->checkout(
            $session,
            [['variant_id' => $item['variant']->id, 'quantity' => '1']],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 5000]],
            clientOrderId: 'POS1-000042',
        );

        $sale();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $sale();
    }

    #[Test]
    public function a_sale_can_push_stock_negative_rather_than_be_refused(): void
    {
        // La règle fondatrice : une vente encaissée n'est jamais rejetée.
        // Un stock négatif est l'information exacte que le compte physique ne
        // correspond plus au compte théorique.                          [D-09]
        $item = $this->makeCatalogItem('Rare', 5000, 'HTG', 0, 3000, $this->shop);
        $session = $this->openSession($this->shop);

        $this->checkout()->checkout(
            $session,
            [['variant_id' => $item['variant']->id, 'quantity' => '150']],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 750000]],
        );

        $this->assertSame(
            '-50.0000',
            app(StockService::class)->quantityAt($this->shop->id, $item['variant']->id),
        );

        // Et le gérant en est informé.
        $this->assertDatabaseHas('stock_discrepancies', [
            'product_variant_id' => $item['variant']->id,
            'origin' => 'OFFLINE_SYNC',
        ]);
    }

    #[Test]
    public function the_sale_emits_an_outbox_event(): void
    {
        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 0, 3000, $this->shop);
        $session = $this->openSession($this->shop);

        $order = $this->checkout()->checkout(
            $session,
            [['variant_id' => $item['variant']->id, 'quantity' => '1']],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 5000]],
        );

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'OrderCompleted',
            'aggregate_id' => $order->id,
            'published_at' => null,
        ]);
    }

    #[Test]
    public function selling_a_case_deducts_the_units_it_contains(): void
    {
        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 0, 3000, $this->shop);
        $unitVariant = $item['variant'];

        $case = \App\Models\ProductVariant::query()->create([
            'product_id' => $item['product']->id,
            'base_variant_id' => $unitVariant->id,
            'unit_id' => $unitVariant->unit_id,
            'sku' => 'KOLA-KES-24',
            'name' => 'Kès de 24',
            'pack_size' => '24',
        ]);

        app(\App\Domain\Catalog\PriceResolver::class)
            ->setPrice($case->id, Money::of(110000, 'HTG'));

        $session = $this->openSession($this->shop);

        $this->checkout()->checkout(
            $session,
            [['variant_id' => $case->id, 'quantity' => '2']],
            [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 220000]],
        );

        // 2 caisses × 24 = 48 unités sorties du stock unitaire.
        $this->assertSame(
            '52.0000',
            app(StockService::class)->quantityAt($this->shop->id, $unitVariant->id),
        );
    }

    #[Test]
    public function order_numbers_are_sequential_per_location(): void
    {
        $item = $this->makeCatalogItem('Kola', 5000, 'HTG', 0, 3000, $this->shop);
        $session = $this->openSession($this->shop);

        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $numbers[] = $this->checkout()->checkout(
                $session,
                [['variant_id' => $item['variant']->id, 'quantity' => '1']],
                [['method' => 'CASH', 'currency' => 'HTG', 'amount_minor' => 5000]],
            )->order_number;
        }

        $this->assertSame(['DELMAS-000001', 'DELMAS-000002', 'DELMAS-000003'], $numbers);
    }
}
