<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-12 — Un abonnement par Organization ; quantity = nombre de Locations actives.
 * Doc 1 §5 le rattachait à l'organisation, Doc 2 §23 au tenant : cette
 * contradiction décidait si un commerçant à trois boutiques paie une fois ou trois.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE plans (
                id       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code     text    NOT NULL UNIQUE,
                name     text    NOT NULL,
                features jsonb   NOT NULL DEFAULT '{}'::jsonb,
                active   boolean NOT NULL DEFAULT true
            )
        SQL);

        // Le modèle qui manquait complètement : c'est lui qui permet de facturer
        // en HTG tout en raisonnant en USD.
        DB::statement(<<<'SQL'
            CREATE TABLE plan_prices (
                id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                plan_id       uuid        NOT NULL REFERENCES plans ON DELETE CASCADE,
                currency      char(3)     NOT NULL,
                interval      text        NOT NULL CHECK (interval IN ('MONTH','YEAR')),
                amount_minor  bigint      NOT NULL CHECK (amount_minor >= 0),
                per_location  boolean     NOT NULL DEFAULT true,
                min_locations integer     NOT NULL DEFAULT 1 CHECK (min_locations >= 1),
                valid_from    timestamptz NOT NULL DEFAULT now(),
                valid_to      timestamptz
            )
        SQL);
        DB::statement('CREATE INDEX plan_prices_lookup ON plan_prices (plan_id, currency, interval, valid_from DESC)');

        DB::statement(<<<'SQL'
            CREATE TABLE subscriptions (
                id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id      uuid        NOT NULL REFERENCES organizations ON DELETE CASCADE,
                plan_price_id        uuid        NOT NULL REFERENCES plan_prices,
                quantity             integer     NOT NULL DEFAULT 1 CHECK (quantity > 0),
                status               text        NOT NULL CHECK (status IN
                                     ('TRIALING','ACTIVE','PAST_DUE','SUSPENDED','CANCELLED')),
                current_period_start timestamptz NOT NULL,
                current_period_end   timestamptz NOT NULL,
                grace_until          timestamptz,
                cancel_at            timestamptz,
                created_at           timestamptz NOT NULL DEFAULT now(),
                updated_at           timestamptz NOT NULL DEFAULT now(),
                CHECK (current_period_end > current_period_start)
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_live ON subscriptions (organization_id)
                WHERE status IN ('TRIALING','ACTIVE','PAST_DUE')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE invoices (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid        NOT NULL REFERENCES organizations ON DELETE CASCADE,
                subscription_id uuid REFERENCES subscriptions ON DELETE SET NULL,
                number          text        NOT NULL,
                currency        char(3)     NOT NULL,
                subtotal_minor  bigint      NOT NULL,
                tax_minor       bigint      NOT NULL DEFAULT 0,
                total_minor     bigint      NOT NULL,
                paid_minor      bigint      NOT NULL DEFAULT 0,
                status          text        NOT NULL CHECK (status IN
                                ('DRAFT','OPEN','PAID','VOID','UNCOLLECTIBLE')),
                due_at          timestamptz,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now(),
                UNIQUE (organization_id, number),
                CHECK (total_minor = subtotal_minor + tax_minor),
                CHECK (paid_minor >= 0 AND paid_minor <= total_minor)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE invoice_lines (
                id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                invoice_id  uuid          NOT NULL REFERENCES invoices ON DELETE CASCADE,
                description text          NOT NULL,
                quantity    numeric(18,4) NOT NULL,
                unit_minor  bigint        NOT NULL,
                total_minor bigint        NOT NULL
            )
        SQL);
        DB::statement('CREATE INDEX invoice_lines_by_invoice ON invoice_lines (invoice_id)');

        // Compteurs verrouillables par organisation : numéros de commande, de
        // facture et de reçu sans collision sous concurrence.
        DB::statement(<<<'SQL'
            CREATE TABLE sequence_counters (
                organization_id uuid    NOT NULL REFERENCES organizations ON DELETE CASCADE,
                scope           text    NOT NULL,
                value           bigint  NOT NULL DEFAULT 0,
                PRIMARY KEY (organization_id, scope)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sequence_counters, invoice_lines, invoices, subscriptions, plan_prices, plans CASCADE');
    }
};
