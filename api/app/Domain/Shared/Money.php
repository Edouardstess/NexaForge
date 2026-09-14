<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Montant monétaire immuable, en unités mineures entières.            [D-05]
 *
 * Jamais de float sur de l'argent : 0.1 + 0.2 ≠ 0.3 en binaire, et un écart
 * d'un centime par vente devient un écart de caisse à la fin du mois.
 *
 * L'arithmétique entre devises différentes lève une exception plutôt que de
 * produire un nombre plausible mais faux.
 */
final readonly class Money implements \JsonSerializable, \Stringable
{
    /** Devises à deux décimales. HTG et USD en font partie. */
    private const DEFAULT_SCALE = 2;

    private const SCALES = [
        'HTG' => 2,
        'USD' => 2,
        'EUR' => 2,
        'CAD' => 2,
        'JPY' => 0,
    ];

    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public static function of(int $minor, string $currency): self
    {
        return new self($minor, self::normaliseCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::normaliseCurrency($currency));
    }

    /**
     * Construit depuis une saisie humaine : « 1250.75 », « 1 250,75 », 1250.75.
     * Le résultat est exact — la chaîne est découpée, jamais convertie en float.
     */
    public static function fromDecimalString(string $amount, string $currency): self
    {
        $currency = self::normaliseCurrency($currency);
        $scale = self::scaleFor($currency);

        $clean = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($amount));

        if (! preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $clean, $m)) {
            throw new InvalidArgumentException("Montant invalide : {$amount}");
        }

        [$sign, $whole, $fraction] = [$m[1], $m[2], $m[3] ?? ''];

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException(
                "{$amount} a plus de {$scale} décimales pour {$currency}."
            );
        }

        $fraction = str_pad($fraction, $scale, '0');
        $minor = (int) ($sign.$whole.$fraction);

        return new self($minor, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Multiplie par une quantité décimale (« 2.5 kg ») en restant exact :
     * le calcul passe par des entiers, l'arrondi est explicite et à mi-chemin
     * supérieur, comme sur un ticket de caisse.
     */
    public function multipliedBy(string $factor, int $factorScale = 4): self
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $factor)) {
            throw new InvalidArgumentException("Facteur invalide : {$factor}");
        }

        $negative = str_starts_with($factor, '-');
        $factor = ltrim($factor, '-');
        [$whole, $fraction] = array_pad(explode('.', $factor, 2), 2, '');

        if (strlen($fraction) > $factorScale) {
            $fraction = substr($fraction, 0, $factorScale);
        }
        $fraction = str_pad($fraction, $factorScale, '0');

        $scaledFactor = (int) ($whole.$fraction);
        $divisor = 10 ** $factorScale;

        // Magnitude et signe traités séparément : intdiv tronque vers zéro,
        // donc arrondir sur la valeur absolue garde le même comportement des
        // deux côtés de zéro.
        $magnitude = intdiv(abs($this->minor) * $scaledFactor * 2 + $divisor, $divisor * 2);
        $isNegative = ($this->minor < 0) !== $negative;

        return new self($isNegative ? -$magnitude : $magnitude, $this->currency);
    }

    /**
     * Répartit le montant en parts, sans perdre ni créer un centime.
     * Le reliquat est distribué une unité à la fois sur les premières parts.
     */
    public function allocate(int ...$ratios): array
    {
        $total = array_sum($ratios);

        if ($total <= 0) {
            throw new InvalidArgumentException('La somme des ratios doit être positive.');
        }

        $remainder = $this->minor;
        $shares = [];

        foreach ($ratios as $ratio) {
            $share = intdiv($this->minor * $ratio, $total);
            $shares[] = $share;
            $remainder -= $share;
        }

        for ($i = 0; $remainder !== 0; $i++) {
            $step = $remainder > 0 ? 1 : -1;
            $shares[$i % count($shares)] += $step;
            $remainder -= $step;
        }

        return array_map(fn (int $minor): self => new self($minor, $this->currency), $shares);
    }

    public function negated(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    public function toDecimalString(): string
    {
        $scale = self::scaleFor($this->currency);

        if ($scale === 0) {
            return (string) $this->minor;
        }

        $sign = $this->minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($this->minor), $scale + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    public function jsonSerialize(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency,
            'formatted' => $this->toDecimalString(),
        ];
    }

    public function __toString(): string
    {
        return $this->toDecimalString().' '.$this->currency;
    }

    public static function scaleFor(string $currency): int
    {
        return self::SCALES[self::normaliseCurrency($currency)] ?? self::DEFAULT_SCALE;
    }

    private static function normaliseCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Code devise invalide : {$currency}");
        }

        return $currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Opération entre devises différentes : {$this->currency} et {$other->currency}. ".
                'Une conversion explicite par ExchangeRateService est requise.'
            );
        }
    }
}
