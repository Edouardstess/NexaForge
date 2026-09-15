<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un remboursement porte sur des ARTICLES, pas sur un montant abstrait : il
 * faut savoir combien de chaque ligne est déjà revenu, sinon on peut
 * rembourser trois fois un article vendu une fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE order_items
                ADD COLUMN refunded_quantity numeric(18,4) NOT NULL DEFAULT 0,
                ADD CONSTRAINT order_items_refund_within_sold
                    CHECK (refunded_quantity >= 0 AND refunded_quantity <= quantity)
        SQL);

        // Le remboursement référence les lignes qu'il annule, avec la
        // quantité et le montant figés au moment où il est fait.
        DB::statement(<<<'SQL'
            CREATE TABLE refund_lines (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid          NOT NULL REFERENCES organizations ON DELETE CASCADE,
                refund_id       uuid          NOT NULL REFERENCES refunds ON DELETE CASCADE,
                order_item_id   uuid          NOT NULL REFERENCES order_items,
                quantity        numeric(18,4) NOT NULL CHECK (quantity > 0),
                amount_minor    bigint        NOT NULL CHECK (amount_minor > 0),
                restocked       boolean       NOT NULL DEFAULT false
            )
        SQL);
        DB::statement('CREATE INDEX refund_lines_by_refund ON refund_lines (refund_id)');
        DB::statement('CREATE INDEX refund_lines_by_item ON refund_lines (order_item_id)');

        // Le moyen par lequel l'argent ressort : rembourser du MonCash en
        // espèces vide le tiroir sans que rien ne le dise.
        DB::statement(<<<'SQL'
            ALTER TABLE refunds
                ADD COLUMN method text NOT NULL DEFAULT 'CASH'
                    CHECK (method IN ('CASH','MONCASH','NATCASH','CARD','CREDIT'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS refund_lines CASCADE');
        DB::statement('ALTER TABLE refunds DROP COLUMN IF EXISTS method');
        DB::statement(<<<'SQL'
            ALTER TABLE order_items
                DROP CONSTRAINT IF EXISTS order_items_refund_within_sold,
                DROP COLUMN IF EXISTS refunded_quantity
        SQL);
    }
};
