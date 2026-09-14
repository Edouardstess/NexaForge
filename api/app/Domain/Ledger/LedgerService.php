<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money;
use App\Support\Tenancy\OrgContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Écritures en partie double.                                          [D-10]
 *
 * `amount_minor` est signé : positif = débit, négatif = crédit. L'équilibre
 * est donc « somme nulle », vérifié PAR DEVISE au COMMIT par un
 * CONSTRAINT TRIGGER différé. Le code applicatif ne peut pas produire un
 * ledger déséquilibré, même en se trompant.
 *
 * Les soldes ne sont jamais des colonnes qu'on incrémente : ils se calculent
 * à partir des écritures.
 */
final class LedgerService
{
    public function __construct(private readonly OrgContext $context) {}

    /**
     * @param  list<array{account: string, currency?: string, amount: Money, location_id?: ?string}>  $entries
     */
    public function post(
        string $referenceType,
        string $referenceId,
        array $entries,
        ?string $description = null,
        ?\DateTimeInterface $occurredAt = null,
    ): string {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'LedgerService::post doit tourner dans une transaction : une écriture isolée '.
                'serait rejetée par le contrôle d\'équilibre au COMMIT.'
            );
        }

        if (count($entries) < 2) {
            throw new LogicException('Une écriture en partie double compte au moins deux lignes.');
        }

        $transactionId = (string) Str::uuid7();
        $organizationId = $this->context->organizationId();

        DB::table('ledger_transactions')->insert([
            'id' => $transactionId,
            'organization_id' => $organizationId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'occurred_at' => $occurredAt ?? now(),
            'created_at' => now(),
        ]);

        $rows = [];

        foreach ($entries as $entry) {
            /** @var Money $amount */
            $amount = $entry['amount'];

            if ($amount->isZero()) {
                continue;   // une ligne à zéro n'apporte rien et viole le CHECK
            }

            $rows[] = [
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'transaction_id' => $transactionId,
                'account_id' => $this->accountId($entry['account'], $amount->currency, $entry['location_id'] ?? null),
                'currency' => $amount->currency,
                'amount_minor' => $amount->minor,
                'created_at' => now(),
            ];
        }

        DB::table('ledger_entries')->insert($rows);

        return $transactionId;
    }

    /** Le solde d'un compte, calculé — jamais lu dans une colonne. */
    public function balance(string $code, string $currency, ?string $locationId = null): Money
    {
        $sum = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('e.organization_id', $this->context->organizationId())
            ->where('a.code', $code)
            ->where('e.currency', $currency)
            ->when(
                $locationId === null,
                fn ($q) => $q->whereNull('a.location_id'),
                fn ($q) => $q->where('a.location_id', $locationId),
            )
            ->sum('e.amount_minor');

        return Money::of((int) $sum, $currency);
    }

    /** Vérifie que chaque transaction est à somme nulle, par devise. */
    public function findUnbalanced(): array
    {
        return DB::select(<<<'SQL'
            SELECT transaction_id, currency, sum(amount_minor) AS delta
              FROM ledger_entries
             WHERE organization_id = ?
             GROUP BY transaction_id, currency
            HAVING sum(amount_minor) <> 0
        SQL, [$this->context->organizationId()]);
    }

    /** Crée le compte à la demande : un plan comptable se construit à l'usage. */
    public function accountId(string $code, string $currency, ?string $locationId = null): string
    {
        $organizationId = $this->context->organizationId();

        $existing = DB::table('ledger_accounts')
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->where('currency', $currency)
            ->when(
                $locationId === null,
                fn ($q) => $q->whereNull('location_id'),
                fn ($q) => $q->where('location_id', $locationId),
            )
            ->value('id');

        if ($existing !== null) {
            return $existing;
        }

        $id = (string) Str::uuid7();

        DB::table('ledger_accounts')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'location_id' => $locationId,
            'code' => $code,
            'name' => ChartOfAccounts::nameFor($code),
            'type' => ChartOfAccounts::typeFor($code),
            'currency' => $currency,
            'active' => true,
            'created_at' => now(),
        ]);

        return $id;
    }
}
