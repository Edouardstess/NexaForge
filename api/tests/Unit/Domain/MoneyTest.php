<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Shared\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function it_parses_decimal_input_exactly(): void
    {
        $this->assertSame(125075, Money::fromDecimalString('1250.75', 'HTG')->minor);
        $this->assertSame(125075, Money::fromDecimalString('1 250,75', 'HTG')->minor);
        $this->assertSame(125000, Money::fromDecimalString('1250', 'HTG')->minor);
        $this->assertSame(-500, Money::fromDecimalString('-5.00', 'USD')->minor);
        $this->assertSame(1250, Money::fromDecimalString('1250', 'JPY')->minor);
    }

    #[Test]
    public function it_rejects_more_decimals_than_the_currency_allows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimalString('10.999', 'HTG');
    }

    #[Test]
    public function the_classic_float_error_does_not_occur(): void
    {
        // 0.1 + 0.2 === 0.3 est faux en binaire. Ici c'est exact.
        $sum = Money::fromDecimalString('0.10', 'USD')->plus(Money::fromDecimalString('0.20', 'USD'));

        $this->assertSame(30, $sum->minor);
        $this->assertSame('0.30', $sum->toDecimalString());
    }

    #[Test]
    public function adding_different_currencies_throws_rather_than_guessing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, 'HTG')->plus(Money::of(100, 'USD'));
    }

    #[Test]
    public function comparing_different_currencies_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, 'HTG')->greaterThan(Money::of(100, 'USD'));
    }

    #[Test]
    #[DataProvider('multiplicationCases')]
    public function it_multiplies_by_a_decimal_quantity(int $minor, string $factor, int $expected): void
    {
        $this->assertSame($expected, Money::of($minor, 'HTG')->multipliedBy($factor)->minor);
    }

    public static function multiplicationCases(): array
    {
        return [
            'quantité entière'        => [2500, '3', 7500],
            'demi-kilo'               => [2500, '0.5', 1250],
            'arrondi au supérieur'    => [333, '0.5', 167],   // 166.5 → 167
            'montant négatif'         => [-2500, '0.5', -1250],
            'facteur négatif'         => [2500, '-0.5', -1250],
            'deux négatifs'           => [-2500, '-0.5', 1250],
            'symétrie autour de zéro' => [-333, '0.5', -167],
            'quantité fractionnaire'  => [1000, '2.5', 2500],
            'zéro'                    => [2500, '0', 0],
        ];
    }

    #[Test]
    public function allocation_never_loses_or_creates_a_centime(): void
    {
        // La commission NexaExpress des documents : 15 % plateforme, 85 % livreur.
        $shares = Money::of(1000, 'USD')->allocate(15, 85);

        $this->assertSame(150, $shares[0]->minor);
        $this->assertSame(850, $shares[1]->minor);
        $this->assertSame(1000, array_sum(array_map(fn ($m) => $m->minor, $shares)));
    }

    #[Test]
    public function allocation_distributes_an_indivisible_remainder(): void
    {
        $shares = Money::of(100, 'HTG')->allocate(1, 1, 1);

        $this->assertSame([34, 33, 33], array_map(fn ($m) => $m->minor, $shares));
        $this->assertSame(100, array_sum(array_map(fn ($m) => $m->minor, $shares)));
    }

    #[Test]
    public function allocation_of_a_negative_amount_also_balances(): void
    {
        $shares = Money::of(-100, 'HTG')->allocate(1, 1, 1);

        $this->assertSame(-100, array_sum(array_map(fn ($m) => $m->minor, $shares)));
    }

    #[Test]
    public function it_formats_for_display(): void
    {
        $this->assertSame('1250.75', Money::of(125075, 'HTG')->toDecimalString());
        $this->assertSame('-1250.75', Money::of(-125075, 'HTG')->toDecimalString());
        $this->assertSame('0.05', Money::of(5, 'USD')->toDecimalString());
        $this->assertSame('0.00', Money::zero('HTG')->toDecimalString());
        $this->assertSame('1250', Money::of(1250, 'JPY')->toDecimalString());
        $this->assertSame('1250.75 HTG', (string) Money::of(125075, 'HTG'));
    }

    #[Test]
    public function it_rejects_an_invalid_currency_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, 'GOURDE');
    }
}
