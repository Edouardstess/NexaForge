<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Catalog\PriceResolver;
use App\Domain\Catalog\TaxCalculator;
use App\Domain\Inventory\StockService;
use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Ledger\LedgerService;
use App\Domain\Money\ExchangeRateService;
use App\Domain\Shared\Decimal;
use App\Domain\Shared\Money;
use App\Models\CashierSession;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Support\Tenancy\OrgContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * L'encaissement : tout ce qui compose une vente, dans UNE transaction.
 *
 * Commande, lignes, paiements, mouvements de stock, écritures comptables et
 * événements sortants sont écrits ensemble ou pas du tout. Aucune commande
 * partiellement enregistrée ne peut exister.
 *
 * La règle qui gouverne le reste : une vente encaissée n'est JAMAIS refusée
 * par le serveur. L'argent est dans le tiroir et la marchandise est partie ;
 * le serveur enregistre et signale, il n'annule pas un fait.            [D-09]
 */
final class CheckoutService
{
    public function __construct(
        private readonly OrgContext $context,
        private readonly PriceResolver $prices,
        private readonly StockService $stock,
        private readonly LedgerService $ledger,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * @param  list<array{variant_id: string, quantity: string, unit_price_minor?: int, discount_minor?: int}>  $lines
     * @param  list<array{method: string, currency: string, amount_minor: int, fx_rate?: string, tendered_minor?: int, reference?: string}>  $tenders
     */
    public function checkout(
        CashierSession $session,
        array $lines,
        array $tenders,
        ?string $customerId = null,
        ?string $clientOrderId = null,
        ?\DateTimeInterface $takenAt = null,
        string $origin = 'ONLINE',
    ): Order {
        if ($lines === []) {
            throw new DomainException('Une vente comporte au moins une ligne.');
        }

        // Une session close ne refuse PAS une vente hors-ligne : elle a été
        // encaissée pendant que la caisse tournait, la clôture est simplement
        // arrivée avant la synchronisation. Le Z est recalculé et marqué
        // amendé. En ligne, c'est une erreur : la caisse n'aurait pas dû
        // vendre sur une session fermée.                                [D-09]
        if (! $session->isOpen() && $origin !== 'OFFLINE') {
            throw new DomainException('Cette session de caisse est clôturée.');
        }

        $currency = $this->context->baseCurrency();
        $takenAt ??= now();

        return DB::transaction(function () use (
            $session, $lines, $tenders, $customerId, $clientOrderId, $takenAt, $origin, $currency
        ): Order {
            $conflicts = [];
            $priced = $this->priceLines($lines, $session->location_id, $currency, $takenAt, $origin, $conflicts);

            $subtotal = array_reduce(
                $priced,
                fn (Money $carry, array $line): Money => $carry->plus($line['base']),
                Money::zero($currency),
            );
            $tax = array_reduce(
                $priced,
                fn (Money $carry, array $line): Money => $carry->plus($line['tax']),
                Money::zero($currency),
            );
            $discount = array_reduce(
                $priced,
                fn (Money $carry, array $line): Money => $carry->plus($line['discount']),
                Money::zero($currency),
            );

            $total = $subtotal->minus($discount)->plus($tax);

            $order = Order::query()->create([
                'location_id' => $session->location_id,
                'cashier_session_id' => $session->id,
                'customer_id' => $customerId,
                'order_number' => $this->nextOrderNumber($session->location_id),
                'status' => 'COMPLETED',
                'currency' => $currency,
                'subtotal_minor' => $subtotal->minor,
                'discount_minor' => $discount->minor,
                'tax_minor' => $tax->minor,
                'total_minor' => $total->minor,
                'taken_at' => $takenAt,
                'synced_at' => now(),
                'origin' => $origin,
                'client_order_id' => $clientOrderId,
                'created_by' => $this->context->userId(),
            ]);

            $this->writeLines($order, $priced);
            $collected = $this->writePayments($order, $tenders, $total, $takenAt);

            if ($collected->lessThan($total)) {
                // Refusé AVANT tout encaissement : c'est une erreur de saisie
                // du caissier, pas un fait déjà accompli.
                throw new DomainException(sprintf(
                    'Encaissement insuffisant : %s reçus pour %s dus.',
                    $collected->toDecimalString(),
                    $total->toDecimalString(),
                ));
            }

            $cost = $this->writeStockMovements($order, $priced, $session->location_id, $conflicts);
            $this->postToLedger($order, $session, $subtotal, $discount, $tax, $cost);
            if (! $session->isOpen()) {
                $session->forceFill(['status' => 'AMENDED'])->save();

                $conflicts[] = [
                    'kind' => 'SESSION_CLOSED',
                    'details' => [
                        'cashier_session_id' => $session->id,
                        'closed_at' => $session->closed_at?->toIso8601String(),
                        'taken_at' => $takenAt->format(\DATE_ATOM),
                        'reason' => 'vente arrivée après la clôture — le Z a été recalculé',
                    ],
                ];
            }

            $this->recordConflicts($order, $session->location_id, $conflicts);
            $this->emit($order, 'OrderCompleted');

            return $order->load('items', 'payments');
        });
    }

    /**
     * Le prix appliqué est celui envoyé par la caisse quand il est fourni :
     * hors-ligne, c'est ce que le client a réellement payé, et le serveur
     * n'a pas autorité pour le réécrire.                                [D-09]
     *
     * @return list<array{variant: ProductVariant, quantity: string, unit: Money, base: Money, tax: Money, discount: Money, line_total: Money, tax_rate_bp: int, cost: ?int}>
     */
    private function priceLines(
        array $lines,
        string $locationId,
        string $currency,
        \DateTimeInterface $at,
        string $origin,
        array &$conflicts,
    ): array {
        $priced = [];

        foreach ($lines as $line) {
            $variant = ProductVariant::query()
                ->with('product.taxRate')
                ->findOrFail($line['variant_id']);

            if (! $variant->active || ! $variant->product->active) {
                // L'article a été retiré de la vente pendant que la caisse
                // était hors-ligne. On enregistre quand même : il est parti.
                $conflicts[] = [
                    'kind' => 'ARCHIVED_PRODUCT',
                    'details' => [
                        'product_variant_id' => $variant->id,
                        'sku' => $variant->sku,
                        'name' => $variant->product->name,
                    ],
                ];
            }

            $unit = isset($line['unit_price_minor'])
                ? $this->honourCashierPrice(
                    $variant, $locationId, $currency, $at,
                    Money::of($line['unit_price_minor'], $currency), $origin, $conflicts,
                )
                : $this->resolveUnitPrice($variant->id, $locationId, $currency, $at, $origin, $conflicts);

            $gross = $unit->multipliedBy($line['quantity']);
            $discount = Money::of($line['discount_minor'] ?? 0, $currency);
            $net = $gross->minus($discount);

            $taxRate = $variant->product->taxRate;
            $rateBp = $taxRate?->rate_bp ?? 0;
            $split = TaxCalculator::split($net, $rateBp, $taxRate?->inclusive ?? true);

            $priced[] = [
                'variant' => $variant,
                'quantity' => $line['quantity'],
                'unit' => $unit,
                'base' => $split['base']->plus($discount),
                'tax' => $split['tax'],
                'discount' => $discount,
                'line_total' => $net,
                'tax_rate_bp' => $rateBp,
                'cost' => $this->latestCostOf($variant, $locationId),
            ];
        }

        return $priced;
    }

    private function writeLines(Order $order, array $priced): void
    {
        foreach ($priced as $position => $line) {
            $order->items()->create([
                'product_variant_id' => $line['variant']->id,
                'description' => $line['variant']->product->name.' — '.$line['variant']->name,
                'quantity' => $line['quantity'],
                'unit_price_minor' => $line['unit']->minor,
                'discount_minor' => $line['discount']->minor,
                'tax_rate_bp' => $line['tax_rate_bp'],
                'tax_minor' => $line['tax']->minor,
                'line_total_minor' => $line['line_total']->minor,
                'unit_cost_minor' => $line['cost'],
                'position' => $position,
            ]);
        }
    }

    /** Chaque encaissement porte son taux, GELÉ sur la ligne. [D-04] */
    private function writePayments(Order $order, array $tenders, Money $total, \DateTimeInterface $at): Money
    {
        $collected = Money::zero($order->currency);
        $remaining = $total;

        foreach ($tenders as $tender) {
            $received = Money::of($tender['amount_minor'], $tender['currency']);

            $rate = $tender['fx_rate']
                ?? $this->rates->rateFor($tender['currency'], $order->currency, $at);

            $baseAmount = $received->currency === $order->currency
                ? $received
                : Money::of($received->multipliedBy($rate, 8)->minor, $order->currency);

            // Le rendu de monnaie se fait en devise de comptabilisation, au
            // taux de l'encaissement — jamais à un autre, ce qui creuserait
            // un écart de caisse structurel.
            $change = $baseAmount->greaterThan($remaining)
                ? $baseAmount->minus($remaining)
                : Money::zero($order->currency);

            $order->payments()->create([
                'method' => $tender['method'],
                'currency' => $received->currency,
                'amount_minor' => $received->minor,
                'fx_rate' => $rate,
                'base_amount_minor' => $baseAmount->minor,
                'tendered_minor' => $tender['tendered_minor'] ?? $received->minor,
                'change_minor' => $change->minor,
                'provider_reference' => $tender['reference'] ?? null,
                'status' => 'SETTLED',
                'received_at' => $at,
            ]);

            $collected = $collected->plus($baseAmount);
            $remaining = $baseAmount->greaterThan($remaining)
                ? Money::zero($order->currency)
                : $remaining->minus($baseAmount);
        }

        return $collected;
    }

    /** @return Money le coût total des marchandises sorties */
    private function writeStockMovements(Order $order, array $priced, string $locationId, array &$conflicts): Money
    {
        $cost = Money::zero($order->currency);

        foreach ($priced as $line) {
            $variant = $line['variant'];

            if (! $variant->product->track_stock) {
                continue;
            }

            // Une caisse de 24 vendue, ce sont 24 unités déduites. [D-06]
            $stockQuantity = $variant->toStockQuantity($line['quantity']);

            $balance = $this->stock->move(
                locationId: $locationId,
                variantId: $variant->stockVariantId(),
                type: 'SALE',
                quantity: '-'.ltrim($stockQuantity, '-'),
                referenceType: 'order',
                referenceId: $order->id,
            );

            // Un solde négatif remonte au gérant par l'écran des conflits,
            // pas seulement par la liste des écarts de stock : c'est là qu'il
            // regarde après une coupure.                                [D-09]
            if (\App\Domain\Shared\Decimal::toScaledInt($balance, 4) < 0) {
                $conflicts[] = [
                    'kind' => 'STOCK_NEGATIVE',
                    'details' => [
                        'product_variant_id' => $variant->id,
                        'sku' => $variant->sku,
                        'name' => $variant->product->name,
                        'sold' => $stockQuantity,
                        'balance' => $balance,
                    ],
                ];
            }

            if ($line['cost'] !== null) {
                $cost = $cost->plus(
                    Money::of($line['cost'], $order->currency)->multipliedBy($stockQuantity)
                );
            }
        }

        return $cost;
    }

    /**
     * Les écritures d'une vente.
     *
     *   Débit  encaissement (par moyen de paiement)     total
     *   Débit  remises accordées                        remise
     *   Crédit ventes                                   sous-total
     *   Crédit TCA à reverser                           taxe
     *   Débit  coût des marchandises vendues            coût
     *   Crédit stock                                    coût
     *
     * Un paiement en devise étrangère passe par le compte de position de
     * change, parce que l'équilibre se vérifie PAR DEVISE.
     */
    private function postToLedger(
        Order $order,
        CashierSession $session,
        Money $subtotal,
        Money $discount,
        Money $tax,
        Money $cost,
    ): void {
        $entries = [];
        $locationId = $session->location_id;
        $change = Money::zero($order->currency);

        foreach ($order->payments as $payment) {
            $account = ChartOfAccounts::forPaymentMethod($payment->method);
            $received = Money::of($payment->amount_minor, $payment->currency);
            $baseValue = Money::of($payment->base_amount_minor, $order->currency);
            $change = $change->plus(Money::of($payment->change_minor ?? 0, $order->currency));

            if ($payment->currency === $order->currency) {
                $entries[] = ['account' => $account, 'amount' => $baseValue, 'location_id' => $locationId];

                continue;
            }

            // Deux jambes reliées par la position de change : l'équilibre du
            // ledger se vérifie PAR DEVISE, donc une écriture USD ne peut pas
            // compenser une écriture HTG directement.
            $entries[] = ['account' => $account, 'amount' => $received, 'location_id' => $locationId];
            $entries[] = ['account' => ChartOfAccounts::FX_CLEARING, 'amount' => $received->negated(), 'location_id' => $locationId];
            $entries[] = ['account' => ChartOfAccounts::FX_CLEARING, 'amount' => $baseValue, 'location_id' => $locationId];
        }

        // Le rendu sort du tiroir, en devise de comptabilisation. Une écriture
        // à part : le soustraire de l'encaissement mélangerait deux faits.
        if (! $change->isZero()) {
            $entries[] = ['account' => ChartOfAccounts::CASH_DRAWER, 'amount' => $change->negated(), 'location_id' => $locationId];
        }

        $entries[] = ['account' => ChartOfAccounts::SALES_DISCOUNT, 'amount' => $discount, 'location_id' => $locationId];
        $entries[] = ['account' => ChartOfAccounts::SALES, 'amount' => $subtotal->negated(), 'location_id' => $locationId];
        $entries[] = ['account' => ChartOfAccounts::TAX_PAYABLE, 'amount' => $tax->negated(), 'location_id' => $locationId];

        if (! $cost->isZero()) {
            $entries[] = ['account' => ChartOfAccounts::COGS, 'amount' => $cost, 'location_id' => $locationId];
            $entries[] = ['account' => ChartOfAccounts::INVENTORY, 'amount' => $cost->negated(), 'location_id' => $locationId];
        }

        $this->ledger->post('order', $order->id, $entries, "Vente {$order->order_number}", $order->taken_at);
    }

    /**
     * Le prix envoyé par la caisse fait foi : c'est ce que le client a payé,
     * et le serveur n'a pas autorité sur un fait accompli.               [D-09]
     *
     * Mais un écart avec le prix en vigueur est signalé, pour que le gérant
     * voie qu'une caisse a vendu à un tarif qui n'est plus le sien — erreur
     * de saisie, catalogue périmé, ou pire.
     */
    private function honourCashierPrice(
        ProductVariant $variant,
        string $locationId,
        string $currency,
        \DateTimeInterface $at,
        Money $sent,
        string $origin,
        array &$conflicts,
    ): Money {
        $comparedTo = 'le prix en vigueur à la vente';

        try {
            $inForce = $this->prices->resolve($variant->id, $locationId, $currency, $at);
        } catch (\RuntimeException) {
            // Aucun prix ne couvrait cet instant — le gérant a remplacé la
            // ligne depuis. On compare alors au prix d'AUJOURD'HUI : sans
            // cela l'écart passerait inaperçu, ce qui est précisément le cas
            // qu'on veut voir après une longue coupure.
            try {
                $inForce = $this->prices->resolve($variant->id, $locationId, $currency);
                $comparedTo = 'le prix actuel';
            } catch (\RuntimeException) {
                return $sent;   // aucune référence nulle part : rien à dire
            }
        }

        if ($sent->equals($inForce)) {
            return $sent;
        }

        $conflicts[] = [
            'kind' => 'PRICE_VARIANCE',
            'details' => [
                'product_variant_id' => $variant->id,
                'sku' => $variant->sku,
                'charged_minor' => $sent->minor,
                'in_force_minor' => $inForce->minor,
                'difference_minor' => $sent->minor - $inForce->minor,
                'currency' => $currency,
                'compared_to' => $comparedTo,
                'origin' => $origin,
            ],
        ];

        return $sent;
    }

    /**
     * Le prix à appliquer quand la caisse n'en a pas envoyé.
     *
     * En ligne, un article sans prix en vigueur est un refus légitime : rien
     * n'a encore été encaissé. Hors-ligne, la vente a DÉJÀ eu lieu — refuser
     * ferait disparaître de l'argent réellement encaissé. On retombe alors
     * sur le plus ancien prix connu et on signale l'écart au gérant. [D-09]
     */
    private function resolveUnitPrice(
        string $variantId,
        string $locationId,
        string $currency,
        \DateTimeInterface $at,
        string $origin,
        array &$conflicts,
    ): Money {
        try {
            return $this->prices->resolve($variantId, $locationId, $currency, $at);
        } catch (\RuntimeException $e) {
            if ($origin !== 'OFFLINE') {
                throw $e;
            }

            $fallback = $this->prices->earliestKnown($variantId, $locationId, $currency);

            if ($fallback === null) {
                throw new DomainException(
                    "Aucun prix n'a jamais été enregistré pour l'article {$variantId} : ".
                    'la caisse doit envoyer unit_price_minor pour une vente hors-ligne.'
                );
            }

            $conflicts[] = [
                'kind' => 'PRICE_VARIANCE',
                'details' => [
                    'product_variant_id' => $variantId,
                    'taken_at' => $at->format(\DATE_ATOM),
                    'reason' => 'aucun prix en vigueur à cette date',
                    'applied_minor' => $fallback->minor,
                    'currency' => $currency,
                ],
            ];

            return $fallback;
        }
    }

    /** Les conflits ne sont jamais silencieux : le gérant doit les voir. */
    private function recordConflicts(Order $order, string $locationId, array $conflicts): void
    {
        if ($conflicts === []) {
            return;
        }

        DB::table('sync_conflicts')->insert(array_map(fn (array $c): array => [
            'id' => (string) Str::uuid7(),
            'organization_id' => $order->organization_id,
            'location_id' => $locationId,
            'order_id' => $order->id,
            'kind' => $c['kind'],
            'details' => json_encode($c['details'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ], $conflicts));
    }

    /** Le dernier coût d'achat connu, figé sur la ligne pour la marge. */
    private function latestCostOf(ProductVariant $variant, string $locationId): ?int
    {
        $cost = DB::table('stock_movements')
            ->where('product_variant_id', $variant->stockVariantId())
            ->where('location_id', $locationId)
            ->where('type', 'PURCHASE')
            ->whereNotNull('unit_cost_minor')
            ->orderByDesc('created_at')
            ->value('unit_cost_minor');

        return $cost === null ? null : (int) $cost;
    }

    /** Numéro lisible, unique par organisation, via un compteur verrouillé. */
    private function nextOrderNumber(string $locationId): string
    {
        $scope = "order:{$locationId}";
        $organizationId = $this->context->organizationId();

        DB::statement(
            'INSERT INTO sequence_counters (organization_id, scope, value) VALUES (?, ?, 0)
             ON CONFLICT (organization_id, scope) DO NOTHING',
            [$organizationId, $scope],
        );

        $next = DB::selectOne(
            'UPDATE sequence_counters SET value = value + 1
              WHERE organization_id = ? AND scope = ?
              RETURNING value',
            [$organizationId, $scope],
        );

        $code = DB::table('locations')->where('id', $locationId)->value('code');

        return sprintf('%s-%06d', $code, $next->value);
    }

    private function emit(Order $order, string $eventType): void
    {
        DB::table('outbox_events')->insert([
            'organization_id' => $order->organization_id,
            'event_type' => $eventType,
            'aggregate_type' => 'order',
            'aggregate_id' => $order->id,
            'payload' => json_encode([
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'location_id' => $order->location_id,
                'total_minor' => $order->total_minor,
                'currency' => $order->currency,
                'taken_at' => $order->taken_at->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $order->taken_at,
            'created_at' => now(),
        ]);
    }
}
