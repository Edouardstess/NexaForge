<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE units (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid     NOT NULL REFERENCES organizations ON DELETE CASCADE,
                code            text     NOT NULL,
                name            text     NOT NULL,
                precision       smallint NOT NULL DEFAULT 0 CHECK (precision BETWEEN 0 AND 4),
                UNIQUE (organization_id, code)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE categories (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid    NOT NULL REFERENCES organizations ON DELETE CASCADE,
                parent_id       uuid REFERENCES categories ON DELETE SET NULL,
                name            text    NOT NULL,
                position        integer NOT NULL DEFAULT 0,
                UNIQUE NULLS NOT DISTINCT (organization_id, parent_id, name)
            )
        SQL);

        // D-07 — Haïti : TCA 10 %, prix affichés TTC (rate_bp en points de base).
        DB::statement(<<<'SQL'
            CREATE TABLE tax_rates (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid    NOT NULL REFERENCES organizations ON DELETE CASCADE,
                code            text    NOT NULL,
                name            text    NOT NULL,
                rate_bp         integer NOT NULL CHECK (rate_bp BETWEEN 0 AND 10000),
                inclusive       boolean NOT NULL DEFAULT true,
                active          boolean NOT NULL DEFAULT true,
                UNIQUE (organization_id, code)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE products (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid        NOT NULL REFERENCES organizations ON DELETE CASCADE,
                category_id     uuid REFERENCES categories ON DELETE SET NULL,
                tax_rate_id     uuid REFERENCES tax_rates,
                name            text        NOT NULL,
                description     text,
                kind            text        NOT NULL DEFAULT 'GOOD' CHECK (kind IN ('GOOD','SERVICE')),
                track_stock     boolean     NOT NULL DEFAULT true,
                active          boolean     NOT NULL DEFAULT true,
                version         integer     NOT NULL DEFAULT 0,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX products_name_trgm ON products USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX products_by_org ON products (organization_id) WHERE active');

        // D-06 — pack_size + base_variant_id résolvent « acheter la caisse, vendre l'unité ».
        // Le stock n'est tenu QUE sur la variante de base.
        DB::statement(<<<'SQL'
            CREATE TABLE product_variants (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid          NOT NULL REFERENCES organizations ON DELETE CASCADE,
                product_id      uuid          NOT NULL REFERENCES products ON DELETE CASCADE,
                base_variant_id uuid REFERENCES product_variants,
                unit_id         uuid          NOT NULL REFERENCES units,
                sku             text          NOT NULL,
                barcode         text,
                name            text          NOT NULL,
                pack_size       numeric(18,4) NOT NULL DEFAULT 1 CHECK (pack_size > 0),
                active          boolean       NOT NULL DEFAULT true,
                version         integer       NOT NULL DEFAULT 0,
                created_at      timestamptz   NOT NULL DEFAULT now(),
                updated_at      timestamptz   NOT NULL DEFAULT now(),
                UNIQUE (organization_id, sku),
                CHECK (base_variant_id IS NULL OR base_variant_id <> id),
                CHECK (base_variant_id IS NOT NULL OR pack_size = 1)
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX product_variants_barcode
                ON product_variants (organization_id, barcode) WHERE barcode IS NOT NULL
        SQL);
        DB::statement('CREATE INDEX product_variants_by_product ON product_variants (product_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE prices (
                id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id    uuid        NOT NULL REFERENCES organizations ON DELETE CASCADE,
                product_variant_id uuid        NOT NULL REFERENCES product_variants ON DELETE CASCADE,
                location_id        uuid REFERENCES locations ON DELETE CASCADE,
                currency           char(3)     NOT NULL,
                amount_minor       bigint      NOT NULL CHECK (amount_minor >= 0),
                valid_from         timestamptz NOT NULL DEFAULT now(),
                valid_to           timestamptz,
                created_by         uuid REFERENCES users,
                created_at         timestamptz NOT NULL DEFAULT now(),
                CHECK (valid_to IS NULL OR valid_to > valid_from)
            )
        SQL);

        // Deux prix valides au même instant = état IMPOSSIBLE en base, pas une
        // règle applicative qu'on oublie.
        DB::statement(<<<'SQL'
            ALTER TABLE prices ADD CONSTRAINT prices_no_overlap EXCLUDE USING gist (
                product_variant_id WITH =,
                (coalesce(location_id, '00000000-0000-0000-0000-000000000000'::uuid)) WITH =,
                currency WITH =,
                tstzrange(valid_from, valid_to) WITH &&
            )
        SQL);
        DB::statement('CREATE INDEX prices_lookup ON prices (product_variant_id, currency, valid_from DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS prices, product_variants, products, tax_rates, categories, units CASCADE');
    }
};
