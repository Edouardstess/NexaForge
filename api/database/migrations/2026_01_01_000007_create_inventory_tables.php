<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE suppliers (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid    NOT NULL REFERENCES organizations ON DELETE CASCADE,
                name            text    NOT NULL,
                phone           text,
                email           citext,
                notes           text,
                active          boolean NOT NULL DEFAULT true,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX suppliers_by_org ON suppliers (organization_id) WHERE active');

        // D-08 — L'ÉTAT COURANT. Lu par la caisse, verrouillé FOR UPDATE avant écriture.
        // quantity peut être NÉGATIVE : une vente encaissée n'est jamais rejetée. [D-09]
        DB::statement(<<<'SQL'
            CREATE TABLE stock_levels (
                organization_id    uuid          NOT NULL REFERENCES organizations ON DELETE CASCADE,
                location_id        uuid          NOT NULL REFERENCES locations ON DELETE CASCADE,
                product_variant_id uuid          NOT NULL REFERENCES product_variants ON DELETE CASCADE,
                quantity           numeric(18,4) NOT NULL DEFAULT 0,
                reserved           numeric(18,4) NOT NULL DEFAULT 0,
                updated_at         timestamptz   NOT NULL DEFAULT now(),
                PRIMARY KEY (location_id, product_variant_id)
            )
        SQL);
        DB::statement('CREATE INDEX stock_levels_by_org ON stock_levels (organization_id, product_variant_id)');

        // L'HISTOIRE. Immuable. quantity est signée.
        DB::statement(<<<'SQL'
            CREATE TABLE stock_movements (
                id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id    uuid          NOT NULL REFERENCES organizations ON DELETE CASCADE,
                location_id        uuid          NOT NULL REFERENCES locations,
                product_variant_id uuid          NOT NULL REFERENCES product_variants,
                type               text          NOT NULL CHECK (type IN
                                   ('PURCHASE','SALE','RETURN','ADJUSTMENT',
                                    'TRANSFER_IN','TRANSFER_OUT','WASTE','COUNT')),
                quantity           numeric(18,4) NOT NULL CHECK (quantity <> 0),
                unit_cost_minor    bigint,
                cost_currency      char(3),
                reference_type     text,
                reference_id       uuid,
                reason             text,
                created_by         uuid REFERENCES users,
                created_at         timestamptz   NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE RULE stock_movements_no_update AS ON UPDATE TO stock_movements DO INSTEAD NOTHING');
        DB::statement('CREATE RULE stock_movements_no_delete AS ON DELETE TO stock_movements DO INSTEAD NOTHING');
        DB::statement('CREATE INDEX stock_movements_history ON stock_movements (organization_id, product_variant_id, created_at DESC)');
        DB::statement('CREATE INDEX stock_movements_by_reference ON stock_movements (reference_type, reference_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE stock_discrepancies (
                id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id    uuid          NOT NULL REFERENCES organizations ON DELETE CASCADE,
                location_id        uuid          NOT NULL REFERENCES locations,
                product_variant_id uuid          NOT NULL REFERENCES product_variants,
                expected_quantity  numeric(18,4) NOT NULL,
                actual_quantity    numeric(18,4) NOT NULL,
                origin             text          NOT NULL CHECK (origin IN
                                   ('RECONCILIATION','OFFLINE_SYNC','PHYSICAL_COUNT')),
                resolved_at        timestamptz,
                resolved_by        uuid REFERENCES users,
                created_at         timestamptz   NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX stock_discrepancies_open ON stock_discrepancies (organization_id, created_at DESC)
                WHERE resolved_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stock_discrepancies, stock_movements, stock_levels, suppliers CASCADE');
    }
};
