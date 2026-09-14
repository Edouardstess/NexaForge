<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-10 — amount_minor signé : > 0 débit, < 0 crédit. Équilibre ⇒ SUM = 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ledger_accounts (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid    NOT NULL REFERENCES organizations ON DELETE CASCADE,
                location_id     uuid REFERENCES locations ON DELETE CASCADE,
                code            text    NOT NULL,
                name            text    NOT NULL,
                type            text    NOT NULL CHECK (type IN
                                ('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE')),
                currency        char(3) NOT NULL,
                active          boolean NOT NULL DEFAULT true,
                created_at      timestamptz NOT NULL DEFAULT now(),
                UNIQUE (organization_id, code, currency),
                UNIQUE (id, currency)
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ledger_accounts_scoped
                ON ledger_accounts (organization_id, code, currency,
                                    coalesce(location_id, '00000000-0000-0000-0000-000000000000'::uuid))
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE ledger_transactions (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid        NOT NULL REFERENCES organizations ON DELETE CASCADE,
                reference_type  text        NOT NULL,
                reference_id    uuid        NOT NULL,
                description     text,
                occurred_at     timestamptz NOT NULL,
                created_at      timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX ledger_transactions_by_reference
                ON ledger_transactions (organization_id, reference_type, reference_id)
        SQL);

        // organization_id et currency manquaient aux documents : sans eux, une
        // erreur de jointure mélangeait la comptabilité de deux clients.
        DB::statement(<<<'SQL'
            CREATE TABLE ledger_entries (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid    NOT NULL REFERENCES organizations ON DELETE CASCADE,
                transaction_id  uuid    NOT NULL REFERENCES ledger_transactions ON DELETE RESTRICT,
                account_id      uuid    NOT NULL,
                currency        char(3) NOT NULL,
                amount_minor    bigint  NOT NULL CHECK (amount_minor <> 0),
                created_at      timestamptz NOT NULL DEFAULT now(),
                FOREIGN KEY (account_id, currency) REFERENCES ledger_accounts (id, currency)
            )
        SQL);
        DB::statement('CREATE INDEX ledger_entries_by_account ON ledger_entries (account_id, created_at DESC)');
        DB::statement('CREATE INDEX ledger_entries_by_transaction ON ledger_entries (transaction_id)');
        DB::statement('CREATE RULE ledger_entries_no_update AS ON UPDATE TO ledger_entries DO INSTEAD NOTHING');
        DB::statement('CREATE RULE ledger_entries_no_delete AS ON DELETE TO ledger_entries DO INSTEAD NOTHING');

        // L'invariant que les documents énonçaient sans mécanisme.
        // Vérifié au COMMIT, PAR DEVISE : les écritures s'insèrent dans
        // n'importe quel ordre, mais la transaction ne peut PAS être validée
        // déséquilibrée.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_assert_balanced() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM ledger_entries e
                     WHERE e.transaction_id = NEW.transaction_id
                     GROUP BY e.currency
                    HAVING sum(e.amount_minor) <> 0
                ) THEN
                    RAISE EXCEPTION 'ledger transaction % is unbalanced', NEW.transaction_id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER ledger_entries_balanced
                AFTER INSERT ON ledger_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ledger_assert_balanced()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ledger_entries, ledger_transactions, ledger_accounts CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS ledger_assert_balanced()');
    }
};
