<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-03 — Le mot « tenant » n'apparaît nulle part.
 * organization_id EST la frontière d'isolation ; location_id la portée opérationnelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE organizations (
                id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                name          text        NOT NULL,
                slug          citext      NOT NULL UNIQUE,
                country       char(2)     NOT NULL DEFAULT 'HT',
                base_currency char(3)     NOT NULL DEFAULT 'HTG',
                timezone      text        NOT NULL DEFAULT 'America/Port-au-Prince',
                status        text        NOT NULL DEFAULT 'ACTIVE'
                              CHECK (status IN ('ACTIVE','SUSPENDED','ARCHIVED')),
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        // display_unit : HTD5 = « dollar haïtien » = 5 HTG. AFFICHAGE seulement.
        // Le stockage reste en centimes de la devise de base, toujours. [D-04]
        DB::statement(<<<'SQL'
            CREATE TABLE locations (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid        NOT NULL REFERENCES organizations,
                code            text        NOT NULL,
                name            text        NOT NULL,
                kind            text        NOT NULL DEFAULT 'STORE'
                                CHECK (kind IN ('STORE','WAREHOUSE')),
                display_unit    text        NOT NULL DEFAULT 'HTG'
                                CHECK (display_unit IN ('HTG','HTD5','USD')),
                address         text,
                phone           text,
                latitude        numeric(10,7),
                longitude       numeric(10,7),
                active          boolean     NOT NULL DEFAULT true,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now(),
                UNIQUE (organization_id, code)
            )
        SQL);
        DB::statement('CREATE INDEX locations_by_org ON locations (organization_id) WHERE active');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS locations, organizations CASCADE');
    }
};
