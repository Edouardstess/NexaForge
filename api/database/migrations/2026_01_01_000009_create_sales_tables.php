<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE customers (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid NOT NULL REFERENCES organizations ON DELETE CASCADE,
                name            text NOT NULL,
                phone           text,
                email           citext,
                notes           text,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX customers_phone ON customers (organization_id, phone)');
        DB::statement('CREATE INDEX customers_name_trgm ON customers USING gin (name gin_trgm_ops)');

        // taken_at = horloge de la CAISSE. Distinct de created_at : une caisse
        // restée hors-ligne six heures ne doit pas faire apparaître ses ventes
        // du matin dans le chiffre du soir. Les rapports utilisent taken_at.
        DB::statement(<<<'SQL'
            CREATE TABLE orders (
                id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id    uuid        NOT NULL REFERENCES organizations ON DELETE CASCADE,
                location_id        uuid        NOT NULL REFERENCES locations,
                cashier_session_id uuid REFERENCES cashier_sessions,
                customer_id        uuid REFERENCES customers ON DELETE SET NULL,
                order_number       text        NOT NULL,
                status             text        NOT NULL DEFAULT 'DRAFT' CHECK (status IN
                                   ('DRAFT','COMPLETED','VOIDED','REFUNDED','PARTIALLY_REFUNDED')),
                currency           char(3)     NOT NULL,
                subtotal_minor     bigint      NOT NULL DEFAULT 0,
                discount_minor     bigint      NOT NULL DEFAULT 0,
                tax_minor          bigint      NOT NULL DEFAULT 0,
                total_minor        bigint      NOT NULL DEFAULT 0,
                refunded_minor     bigint      NOT NULL DEFAULT 0,
                taken_at           timestamptz NOT NULL,
                synced_at          timestamptz,
                origin             text        NOT NULL DEFAULT 'ONLINE'
                                   CHECK (origin IN ('ONLINE','OFFLINE')),
                client_order_id    text,
                created_by         uuid        NOT NULL REFERENCES users,
                version            integer     NOT NULL DEFAULT 0,
                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now(),
                UNIQUE (organization_id, order_number),
                CHECK (total_minor = subtotal_minor - discount_minor + tax_minor),
                CHECK (refunded_minor >= 0 AND refunded_minor <= total_minor)
            )
        SQL);
        // Deuxième filet anti-doublon hors-ligne, au niveau de la base.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX orders_client_id ON orders (organization_id, client_order_id)
                WHERE client_order_id IS NOT NULL
        SQL);
        DB::statement('CREATE INDEX orders_daily ON orders (organization_id, location_id, taken_at DESC)');
        DB::statement('CREATE INDEX orders_by_session ON orders (cashier_session_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE order_items (
                id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id    uuid          NOT NULL REFERENCES organizations ON DELETE CASCADE,
                order_id           uuid          NOT NULL REFERENCES orders ON DELETE CASCADE,
                product_variant_id uuid          NOT NULL REFERENCES product_variants,
                description        text          NOT NULL,
                quantity           numeric(18,4) NOT NULL CHECK (quantity > 0),
                unit_price_minor   bigint        NOT NULL,
                discount_minor     bigint        NOT NULL DEFAULT 0,
                tax_rate_bp        integer       NOT NULL DEFAULT 0,
                tax_minor          bigint        NOT NULL DEFAULT 0,
                line_total_minor   bigint        NOT NULL,
                unit_cost_minor    bigint,
                position           integer       NOT NULL,
                UNIQUE (order_id, position)
            )
        SQL);
        DB::statement('CREATE INDEX order_items_by_order ON order_items (order_id)');
        DB::statement('CREATE INDEX order_items_by_variant ON order_items (organization_id, product_variant_id)');

        // D-04 — Une commande accepte PLUSIEURS paiements de devises DIFFÉRENTES.
        // fx_rate est gelé ici : une réimpression ou un remboursement trois
        // semaines plus tard rejoue le taux d'origine, pas le taux du jour.
        DB::statement(<<<'SQL'
            CREATE TABLE payments (
                id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id    uuid          NOT NULL REFERENCES organizations ON DELETE CASCADE,
                order_id           uuid          NOT NULL REFERENCES orders ON DELETE CASCADE,
                method             text          NOT NULL CHECK (method IN
                                   ('CASH','MONCASH','NATCASH','CARD','CREDIT')),
                currency           char(3)       NOT NULL,
                amount_minor       bigint        NOT NULL CHECK (amount_minor > 0),
                fx_rate            numeric(18,8) NOT NULL DEFAULT 1 CHECK (fx_rate > 0),
                base_amount_minor  bigint        NOT NULL CHECK (base_amount_minor > 0),
                tendered_minor     bigint,
                change_minor       bigint,
                provider_reference text,
                status             text          NOT NULL DEFAULT 'SETTLED' CHECK (status IN
                                   ('PENDING','SETTLED','FAILED','REVERSED')),
                received_at        timestamptz   NOT NULL,
                created_at         timestamptz   NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX payments_by_order ON payments (order_id)');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_provider_reference
                ON payments (organization_id, method, provider_reference)
                WHERE provider_reference IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE refunds (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid          NOT NULL REFERENCES organizations ON DELETE CASCADE,
                order_id        uuid          NOT NULL REFERENCES orders,
                payment_id      uuid REFERENCES payments,
                amount_minor    bigint        NOT NULL CHECK (amount_minor > 0),
                currency        char(3)       NOT NULL,
                fx_rate         numeric(18,8) NOT NULL DEFAULT 1 CHECK (fx_rate > 0),
                reason          text          NOT NULL,
                restock         boolean       NOT NULL DEFAULT true,
                created_by      uuid          NOT NULL REFERENCES users,
                created_at      timestamptz   NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX refunds_by_order ON refunds (order_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS refunds, payments, order_items, orders, customers CASCADE');
    }
};
