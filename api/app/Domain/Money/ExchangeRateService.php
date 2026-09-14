<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Shared\Decimal;
use App\Domain\Shared\Money;
use App\Support\Tenancy\OrgContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Conversion entre devises.                                            [D-04]
 *
 * `rate` = nombre d'unités de base_currency pour 1 unité de quote_currency.
 * base HTG, quote USD, rate 132.50  ⇒  1 USD = 132.50 HTG
 *
 * Le taux obtenu ici est destiné à être GELÉ sur la ligne qui l'utilise. Un
 * remboursement trois semaines plus tard doit rejouer le taux d'origine, sans
 * quoi les rapports changent rétroactivement à chaque variation du marché.
 */
final class ExchangeRateService
{
    public function __construct(private readonly OrgContext $context) {}

    /** Le taux en vigueur à une date donnée. 1 si les devises sont identiques. */
    public function rateFor(string $from, string $to, ?DateTimeInterface $at = null): string
    {
        if ($from === $to) {
            return '1';
        }

        $at ??= now();
        $base = $this->context->baseCurrency();

        // Seuls les couples (base, devise) sont stockés : une organisation ne
        // tient qu'une seule comptabilité, donc tout passe par sa devise.
        if ($from === $base) {
            return $this->invert($this->storedRate($base, $to, $at));
        }

        if ($to === $base) {
            return $this->storedRate($base, $from, $at);
        }

        throw new RuntimeException(
            "Conversion {$from} → {$to} impossible : aucune des deux n'est la devise de comptabilisation ({$base})."
        );
    }

    /** Convertit un montant, au taux en vigueur ou à un taux imposé. */
    public function convert(Money $amount, string $to, ?string $rate = null): Money
    {
        if ($amount->currency === $to) {
            return $amount;
        }

        $rate ??= $this->rateFor($amount->currency, $to);

        // Le calcul passe par la classe Money : entiers, arrondi explicite.
        return Money::of($amount->multipliedBy($rate, 8)->minor, $to);
    }

    public function record(string $quoteCurrency, string $rate, ?DateTimeInterface $effectiveFrom = null): void
    {
        DB::table('exchange_rates')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'organization_id' => $this->context->organizationId(),
            'base_currency' => $this->context->baseCurrency(),
            'quote_currency' => strtoupper($quoteCurrency),
            'rate' => $rate,
            'effective_from' => $effectiveFrom ?? now(),
            'source' => 'MANUAL',
            'created_by' => $this->context->userId(),
            'created_at' => now(),
        ]);
    }

    /** Depuis quand le taux courant n'a-t-il pas bougé ? Sert à alerter le gérant. */
    public function ageInDays(string $quoteCurrency): ?int
    {
        $effectiveFrom = DB::table('exchange_rates')
            ->where('organization_id', $this->context->organizationId())
            ->where('base_currency', $this->context->baseCurrency())
            ->where('quote_currency', strtoupper($quoteCurrency))
            ->where('effective_from', '<=', now())
            ->max('effective_from');

        return $effectiveFrom === null
            ? null
            : (int) now()->diffInDays(\Illuminate\Support\Carbon::parse($effectiveFrom), absolute: true);
    }

    private function storedRate(string $base, string $quote, DateTimeInterface $at): string
    {
        $rate = DB::table('exchange_rates')
            ->where('organization_id', $this->context->organizationId())
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')
            ->value('rate');

        if ($rate === null) {
            throw new RuntimeException(
                "Aucun taux {$base}/{$quote} enregistré au ".$at->format('Y-m-d H:i').'. '.
                'Le propriétaire doit saisir le taux du jour avant d\'encaisser dans cette devise.'
            );
        }

        return (string) $rate;
    }

    private function invert(string $rate): string
    {
        // Arithmétique entière : (string) (1 / 132.5) perdrait des décimales.
        return Decimal::reciprocal($rate, 8);
    }
}
