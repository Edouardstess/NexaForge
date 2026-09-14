<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Shared\Money;

/**
 * Décomposition d'un prix en base et taxe.                             [D-07]
 *
 * En commerce de détail haïtien, le prix affiché est le prix payé : les prix
 * sont saisis TTC. L'arrondi porte sur la TAXE, jamais sur la base, pour que
 * la somme des lignes égale exactement le total encaissé.
 */
final readonly class TaxCalculator
{
    public const BASIS_POINTS = 10000;

    /** @return array{base: Money, tax: Money} */
    public static function split(Money $amount, int $rateBp, bool $inclusive): array
    {
        if ($rateBp === 0) {
            return ['base' => $amount, 'tax' => Money::zero($amount->currency)];
        }

        if (! $inclusive) {
            // Prix hors taxe : la taxe s'ajoute par-dessus.
            $tax = self::proportion($amount, $rateBp, self::BASIS_POINTS);

            return ['base' => $amount, 'tax' => $tax];
        }

        // Prix TTC : on retrouve la base, la taxe est le reste — donc la somme
        // retombe toujours exactement sur le montant encaissé.
        $base = self::proportion($amount, self::BASIS_POINTS, self::BASIS_POINTS + $rateBp);

        return ['base' => $base, 'tax' => $amount->minus($base)];
    }

    /** $amount × $numerator / $denominator, arrondi à mi-chemin supérieur. */
    private static function proportion(Money $amount, int $numerator, int $denominator): Money
    {
        $product = abs($amount->minor) * $numerator;
        $magnitude = intdiv($product * 2 + $denominator, $denominator * 2);

        return Money::of($amount->minor < 0 ? -$magnitude : $magnitude, $amount->currency);
    }
}
