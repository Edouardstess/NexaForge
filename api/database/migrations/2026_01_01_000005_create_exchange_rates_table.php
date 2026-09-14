<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-04 — rate = nombre d'unités de base_currency pour 1 unité de quote_currency.
 * base HTG, quote USD, rate 132.50  ⇒  1 USD = 132.50 HTG
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE exchange_rates (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid          NOT NULL REFERENCES organizations ON DELETE CASCADE,
                base_currency   char(3)       NOT NULL,
                quote_currency  char(3)       NOT NULL,
                rate            numeric(18,8) NOT NULL CHECK (rate > 0),
                effective_from  timestamptz   NOT NULL,
                source          text          NOT NULL DEFAULT 'MANUAL'
                                CHECK (source IN ('MANUAL','BRH','PROVIDER')),
                created_by      uuid REFERENCES users,
                created_at      timestamptz   NOT NULL DEFAULT now(),
                UNIQUE (organization_id, base_currency, quote_currency, effective_from),
                CHECK (base_currency <> quote_currency)
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX exchange_rates_current
                ON exchange_rates (organization_id, base_currency, quote_currency, effective_from DESC)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS exchange_rates CASCADE');
    }
};
