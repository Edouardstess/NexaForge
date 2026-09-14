<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use InvalidArgumentException;

/**
 * Le plan comptable minimal d'un commerce de détail.
 *
 * Volontairement court : ajouter un compte est une décision comptable, pas
 * une chaîne libre qu'un contrôleur invente au vol.
 */
final class ChartOfAccounts
{
    public const CASH_DRAWER = 'CASH_DRAWER';

    public const MONCASH = 'MONCASH';

    public const NATCASH = 'NATCASH';

    public const CARD_CLEARING = 'CARD_CLEARING';

    public const ACCOUNTS_RECEIVABLE = 'ACCOUNTS_RECEIVABLE';

    public const INVENTORY = 'INVENTORY';

    public const SALES = 'SALES';

    public const SALES_DISCOUNT = 'SALES_DISCOUNT';

    public const COGS = 'COGS';

    public const TAX_PAYABLE = 'TAX_PAYABLE';

    public const FX_CLEARING = 'FX_CLEARING';

    public const FX_GAIN_LOSS = 'FX_GAIN_LOSS';

    public const CASH_VARIANCE = 'CASH_VARIANCE';

    /** @var array<string, array{type: string, name: string}> */
    private const ACCOUNTS = [
        self::CASH_DRAWER => ['type' => 'ASSET', 'name' => 'Tiroir-caisse'],
        self::MONCASH => ['type' => 'ASSET', 'name' => 'MonCash'],
        self::NATCASH => ['type' => 'ASSET', 'name' => 'NatCash'],
        self::CARD_CLEARING => ['type' => 'ASSET', 'name' => 'Cartes en attente'],
        self::ACCOUNTS_RECEIVABLE => ['type' => 'ASSET', 'name' => 'Clients'],
        self::INVENTORY => ['type' => 'ASSET', 'name' => 'Stock'],
        self::SALES => ['type' => 'REVENUE', 'name' => 'Ventes'],
        // Compte de passage entre les deux jambes d'un encaissement en devise
        // étrangère : l'équilibre du ledger se vérifie PAR DEVISE, donc une
        // écriture USD ne peut pas compenser une écriture HTG directement.
        self::FX_CLEARING => ['type' => 'ASSET', 'name' => 'Position de change'],
        self::SALES_DISCOUNT => ['type' => 'REVENUE', 'name' => 'Remises accordées'],
        self::COGS => ['type' => 'EXPENSE', 'name' => 'Coût des marchandises vendues'],
        self::TAX_PAYABLE => ['type' => 'LIABILITY', 'name' => 'TCA à reverser'],
        self::FX_GAIN_LOSS => ['type' => 'REVENUE', 'name' => 'Écart de change'],
        self::CASH_VARIANCE => ['type' => 'EXPENSE', 'name' => 'Écart de caisse'],
    ];

    /** Le compte d'encaissement correspondant à un moyen de paiement. */
    public static function forPaymentMethod(string $method): string
    {
        return match ($method) {
            'CASH' => self::CASH_DRAWER,
            'MONCASH' => self::MONCASH,
            'NATCASH' => self::NATCASH,
            'CARD' => self::CARD_CLEARING,
            'CREDIT' => self::ACCOUNTS_RECEIVABLE,
            default => throw new InvalidArgumentException("Moyen de paiement inconnu : {$method}"),
        };
    }

    public static function typeFor(string $code): string
    {
        return self::ACCOUNTS[$code]['type']
            ?? throw new InvalidArgumentException("Compte inconnu : {$code}");
    }

    public static function nameFor(string $code): string
    {
        return self::ACCOUNTS[$code]['name']
            ?? throw new InvalidArgumentException("Compte inconnu : {$code}");
    }
}
