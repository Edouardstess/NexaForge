<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Inventory\StockService;
use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Ledger\LedgerService;
use App\Domain\Shared\Decimal;
use App\Domain\Shared\Money;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Tenancy\OrgContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Remboursement partiel ou total, par ligne.
 *
 * Trois règles, chacune tirée d'une décision :
 *
 *  — le taux de change est celui de la VENTE, jamais celui du jour. Un client
 *    qui rend un article doit récupérer ce qu'il a payé, pas ce que le
 *    marché en dit aujourd'hui.                                         [D-04]
 *  — on ne peut jamais rendre plus qu'on n'a vendu, et la base le garantit
 *    par une contrainte, pas seulement le code.
 *  — l'écriture inverse est une écriture de plus, jamais une réécriture de
 *    l'originale : le ledger est immuable.                              [D-10]
 */
final class RefundService
{
    private const SCALE = 4;

    public function __construct(
        private readonly OrgContext $context,
        private readonly StockService $stock,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @param  list<array{order_item_id: string, quantity: string}>  $lines
     */
    public function refund(
        Order $order,
        array $lines,
        string $reason,
        bool $restock = true,
        string $method = 'CASH',
    ): array {
        if ($lines === []) {
            throw new DomainException('Un remboursement porte sur au moins une ligne.');
        }

        if (in_array($order->status, ['VOIDED', 'DRAFT'], true)) {
            throw new DomainException("Une commande {$order->status} ne se rembourse pas.");
        }

        return DB::transaction(function () use ($order, $lines, $reason, $restock, $method): array {
            $currency = $order->currency;
            $total = Money::zero($currency);
            $cost = Money::zero($currency);
            $resolved = [];

            foreach ($lines as $line) {
                // Verrouiller la ligne : deux caissiers qui remboursent le
                // même article au même instant ne doivent pas passer tous les
                // deux le contrôle « pas plus que vendu ».
                $item = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->lockForUpdate()
                    ->findOrFail($line['order_item_id']);

                $item->load('variant.product');

                $quantity = $line['quantity'];
                $remaining = $this->subtract($item->quantity, $item->refunded_quantity);

                if ($this->scaled($quantity) <= 0) {
                    throw new DomainException('La quantité à rembourser doit être positive.');
                }

                if ($this->scaled($quantity) > $this->scaled($remaining)) {
                    throw new DomainException(sprintf(
                        'On ne peut pas rendre %s × %s : il n\'en reste que %s de remboursable.',
                        $quantity, $item->description, $remaining,
                    ));
                }

                // Le montant rendu est la part de la ligne, au prorata exact
                // de la quantité — pas un recalcul au prix d'aujourd'hui.
                $amount = $this->prorate($item->line_total_minor, $quantity, $item->quantity, $currency);
                $total = $total->plus($amount);

                if ($item->unit_cost_minor !== null) {
                    $cost = $cost->plus(
                        Money::of($item->unit_cost_minor, $currency)->multipliedBy($quantity)
                    );
                }

                $resolved[] = ['item' => $item, 'quantity' => $quantity, 'amount' => $amount];
            }

            if ($total->isZero()) {
                throw new DomainException('Le montant à rembourser est nul.');
            }

            // Le taux de la vente, repris tel quel.                     [D-04]
            $fxRate = (string) ($order->payments()->orderBy('created_at')->value('fx_rate') ?? '1');

            $refundId = (string) Str::uuid7();

            DB::table('refunds')->insert([
                'id' => $refundId,
                'organization_id' => $order->organization_id,
                'order_id' => $order->id,
                'payment_id' => null,
                'amount_minor' => $total->minor,
                'currency' => $currency,
                'fx_rate' => $fxRate,
                'method' => $method,
                'reason' => $reason,
                'restock' => $restock,
                'created_by' => $this->context->userId(),
                'created_at' => now(),
            ]);

            foreach ($resolved as $row) {
                DB::table('refund_lines')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $order->organization_id,
                    'refund_id' => $refundId,
                    'order_item_id' => $row['item']->id,
                    'quantity' => $row['quantity'],
                    'amount_minor' => $row['amount']->minor,
                    'restocked' => $restock,
                ]);

                DB::table('order_items')
                    ->where('id', $row['item']->id)
                    ->update([
                        'refunded_quantity' => $this->add($row['item']->refunded_quantity, $row['quantity']),
                    ]);

                if ($restock) {
                    $variant = $row['item']->variant;

                    if ($variant->product->track_stock) {
                        $this->stock->move(
                            locationId: $order->location_id,
                            variantId: $variant->stockVariantId(),
                            type: 'RETURN',
                            quantity: $variant->toStockQuantity($row['quantity']),
                            referenceType: 'refund',
                            referenceId: $refundId,
                            reason: $reason,
                        );
                    }
                }
            }

            $this->postToLedger($order, $refundId, $total, $restock ? $cost : Money::zero($currency), $method);

            $refunded = $order->refunded_minor + $total->minor;

            $order->forceFill([
                'refunded_minor' => $refunded,
                'status' => $refunded >= $order->total_minor ? 'REFUNDED' : 'PARTIALLY_REFUNDED',
            ])->save();

            DB::table('outbox_events')->insert([
                'organization_id' => $order->organization_id,
                'event_type' => 'RefundCreated',
                'aggregate_type' => 'order',
                'aggregate_id' => $order->id,
                'payload' => json_encode([
                    'refund_id' => $refundId,
                    'order_id' => $order->id,
                    'amount_minor' => $total->minor,
                    'currency' => $currency,
                    'method' => $method,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return [
                'id' => $refundId,
                'order_id' => $order->id,
                'amount_minor' => $total->minor,
                'currency' => $currency,
                'fx_rate' => $fxRate,
                'method' => $method,
                'restock' => $restock,
                'order_status' => $order->status,
                'order_refunded_minor' => $refunded,
            ];
        });
    }

    /**
     * L'écriture inverse.
     *
     *   Crédit  compte d'encaissement     montant rendu
     *   Débit   ventes                    part chiffre d'affaires
     *   Crédit  remises accordées         part remise
     *   Débit   TCA à reverser            part taxe
     *   Débit   stock / Crédit COGS       coût, si l'article revient
     *
     * Les trois parts sont calculées pour retomber EXACTEMENT sur le montant
     * rendu : salesBack − discountBack + taxBack = montant, par construction.
     */
    private function postToLedger(Order $order, string $refundId, Money $amount, Money $cost, string $method): void
    {
        $currency = $order->currency;
        $total = $order->total_minor;

        $taxBack = $this->share($amount->minor, $order->tax_minor, $total);
        $discountBack = $this->share($amount->minor, $order->discount_minor, $total);
        $salesBack = $amount->minor - $taxBack + $discountBack;

        $entries = [
            ['account' => ChartOfAccounts::forPaymentMethod($method), 'amount' => $amount->negated(), 'location_id' => $order->location_id],
            ['account' => ChartOfAccounts::SALES, 'amount' => Money::of($salesBack, $currency), 'location_id' => $order->location_id],
            ['account' => ChartOfAccounts::SALES_DISCOUNT, 'amount' => Money::of(-$discountBack, $currency), 'location_id' => $order->location_id],
            ['account' => ChartOfAccounts::TAX_PAYABLE, 'amount' => Money::of($taxBack, $currency), 'location_id' => $order->location_id],
        ];

        if (! $cost->isZero()) {
            $entries[] = ['account' => ChartOfAccounts::INVENTORY, 'amount' => $cost, 'location_id' => $order->location_id];
            $entries[] = ['account' => ChartOfAccounts::COGS, 'amount' => $cost->negated(), 'location_id' => $order->location_id];
        }

        $this->ledger->post('refund', $refundId, $entries, "Remboursement {$order->order_number}");
    }

    /** $amount × $part / $whole, arrondi à mi-chemin supérieur. */
    private function share(int $amount, int $part, int $whole): int
    {
        if ($whole === 0 || $part === 0) {
            return 0;
        }

        return intdiv(abs($amount) * $part * 2 + $whole, $whole * 2);
    }

    private function prorate(int $lineTotal, string $quantity, string $soldQuantity, string $currency): Money
    {
        $q = $this->scaled($quantity);
        $sold = $this->scaled($soldQuantity);

        if ($sold === 0) {
            return Money::zero($currency);
        }

        return Money::of(intdiv(abs($lineTotal) * $q * 2 + $sold, $sold * 2), $currency);
    }

    private function scaled(string $value): int
    {
        return Decimal::toScaledInt($value, self::SCALE);
    }

    private function add(string $a, string $b): string
    {
        return Decimal::fromScaledInt($this->scaled($a) + $this->scaled($b), self::SCALE);
    }

    private function subtract(string $a, string $b): string
    {
        return Decimal::fromScaledInt($this->scaled($a) - $this->scaled($b), self::SCALE);
    }
}
