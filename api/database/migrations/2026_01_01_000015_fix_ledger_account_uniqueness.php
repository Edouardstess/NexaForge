<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un compte de ledger est propre à son point de vente.
 *
 * La table portait deux unicités contradictoires : `(organization_id, code,
 * currency)`, qui ignore la location, et `ledger_accounts_scoped`, qui
 * l'inclut. La première gagnait, donc une organisation ne pouvait posséder
 * qu'UN SEUL compte CASH_DRAWER en gourdes — et la deuxième boutique
 * n'arrivait jamais à encaisser sa première vente.
 *
 * Aucun test ne l'avait vu : ils vendaient tous dans une seule boutique. Le
 * bug est apparu en écrivant un test de portée à deux points de vente.
 *
 * `UNIQUE (id, currency)` reste : c'est elle qui porte la clé étrangère
 * composite depuis `ledger_entries`, qui garantit que la devise d'une
 * écriture est celle de son compte.                                    [D-10]
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_accounts
                DROP CONSTRAINT IF EXISTS ledger_accounts_organization_id_code_currency_key
        SQL);
    }

    public function down(): void
    {
        // Volontairement vide : remettre cette contrainte casserait tout
        // commerce à plusieurs points de vente.
    }
};
