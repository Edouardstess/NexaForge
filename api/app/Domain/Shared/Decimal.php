<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Le strict minimum d'arithmétique décimale exacte, en entiers.
 *
 * Écrit à la main plutôt que délégué à bcmath : l'extension n'est pas garantie
 * présente partout où cette API tournera, et un taux de change calculé en
 * float finit par décaler une caisse d'une gourde par jour.
 */
final class Decimal
{
    /** Convertit « 132.5 » en entier mis à l'échelle : 13_250_000_000 pour scale 8. */
    public static function toScaledInt(string $value, int $scale): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', trim($value), $m)) {
            throw new InvalidArgumentException("Décimal invalide : {$value}");
        }

        [$sign, $whole, $fraction] = [$m[1], $m[2], $m[3] ?? ''];

        // Tronquer au-delà de l'échelle plutôt qu'arrondir : sur un taux de
        // change, l'arrondi appartient à la conversion, pas au stockage.
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');

        return (int) ($sign.$whole.$fraction);
    }

    /** L'inverse : 13_250_000_000 et scale 8 donnent « 132.50000000 ». */
    public static function fromScaledInt(int $scaled, int $scale): string
    {
        if ($scale === 0) {
            return (string) $scaled;
        }

        $sign = $scaled < 0 ? '-' : '';
        $digits = str_pad((string) abs($scaled), $scale + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    /**
     * 1 / $value, avec `scale` décimales, arrondi à mi-chemin supérieur.
     *
     * Pour un taux de 132.50, rend « 0.00754717 » — et non le 0.0075471698…
     * tronqué qu'un cast de float produirait.
     */
    public static function reciprocal(string $value, int $scale = 8): string
    {
        $scaled = self::toScaledInt($value, $scale);

        if ($scaled === 0) {
            throw new InvalidArgumentException('Division par zéro.');
        }

        $unit = 10 ** $scale;
        $magnitude = intdiv($unit * $unit * 2 + abs($scaled), abs($scaled) * 2);

        return self::fromScaledInt($scaled < 0 ? -$magnitude : $magnitude, $scale);
    }
}
