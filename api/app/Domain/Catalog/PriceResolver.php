<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Shared\Money;
use App\Support\Tenancy\OrgContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Résolution du prix en vigueur.                                       [D-06]
 *
 * Ordre : prix spécifique à la location, puis prix par défaut de
 * l'organisation. La contrainte EXCLUDE en base garantit qu'au plus une ligne
 * est valide à un instant donné — la requête ne peut donc pas être ambiguë.
 */
final class PriceResolver
{
    public function __construct(private readonly OrgContext $context) {}

    public function resolve(
        string $variantId,
        string $locationId,
        ?string $currency = null,
        ?DateTimeInterface $at = null,
    ): Money {
        $currency ??= $this->context->baseCurrency();
        $at ??= now();

        $row = DB::table('prices')
            ->where('organization_id', $this->context->organizationId())
            ->where('product_variant_id', $variantId)
            ->where('currency', $currency)
            ->where('valid_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at))
            ->where(fn ($q) => $q->where('location_id', $locationId)->orWhereNull('location_id'))
            // false trie avant true : le prix de la location l'emporte sur le
            // prix par défaut de l'organisation.
            ->orderByRaw('location_id IS NULL')
            ->first();

        if ($row === null) {
            throw new RuntimeException(
                "Aucun prix {$currency} en vigueur pour l'article {$variantId}."
            );
        }

        return Money::of((int) $row->amount_minor, $row->currency);
    }

    /**
     * Change le prix : ferme la ligne courante, en ouvre une nouvelle.
     * On ne fait JAMAIS d'UPDATE sur le montant, sinon la marge d'une vente
     * passée change quand on change le prix d'aujourd'hui.
     */
    public function setPrice(
        string $variantId,
        Money $amount,
        ?string $locationId = null,
        ?DateTimeInterface $from = null,
    ): void {
        $from ??= now();
        $organizationId = $this->context->organizationId();

        DB::transaction(function () use ($variantId, $amount, $locationId, $from, $organizationId): void {
            DB::table('prices')
                ->where('organization_id', $organizationId)
                ->where('product_variant_id', $variantId)
                ->where('currency', $amount->currency)
                ->when(
                    $locationId === null,
                    fn ($q) => $q->whereNull('location_id'),
                    fn ($q) => $q->where('location_id', $locationId),
                )
                ->whereNull('valid_to')
                ->update(['valid_to' => $from]);

            DB::table('prices')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'organization_id' => $organizationId,
                'product_variant_id' => $variantId,
                'location_id' => $locationId,
                'currency' => $amount->currency,
                'amount_minor' => $amount->minor,
                'valid_from' => $from,
                'valid_to' => null,
                'created_by' => $this->context->userId(),
                'created_at' => now(),
            ]);
        });
    }
}
