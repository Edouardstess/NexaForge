<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // D-11 — Table dédiée plutôt qu'une colonne nullable dont l'unicité
        // reposait sur NULL <> NULL.
        DB::statement(<<<'SQL'
            CREATE TABLE idempotency_keys (
                organization_id     uuid        NOT NULL REFERENCES organizations ON DELETE CASCADE,
                key                 text        NOT NULL,
                request_fingerprint text        NOT NULL,
                status              text        NOT NULL CHECK (status IN ('IN_PROGRESS','COMPLETED')),
                response_status     integer,
                response_body       jsonb,
                locked_at           timestamptz,
                completed_at        timestamptz,
                created_at          timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (organization_id, key)
            )
        SQL);
        DB::statement('CREATE INDEX idempotency_keys_cleanup ON idempotency_keys (created_at)');

        // D-09 — Les six cas de conflit hors-ligne.
        DB::statement(<<<'SQL'
            CREATE TABLE sync_conflicts (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid        NOT NULL REFERENCES organizations ON DELETE CASCADE,
                location_id     uuid        NOT NULL REFERENCES locations,
                order_id        uuid REFERENCES orders ON DELETE CASCADE,
                kind            text        NOT NULL CHECK (kind IN
                                ('STOCK_NEGATIVE','PRICE_VARIANCE','ARCHIVED_PRODUCT',
                                 'EXPIRED_DISCOUNT','SESSION_CLOSED')),
                details         jsonb       NOT NULL,
                resolved_at     timestamptz,
                resolved_by     uuid REFERENCES users,
                created_at      timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX sync_conflicts_open ON sync_conflicts (organization_id, created_at DESC)
                WHERE resolved_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE outbox_events (
                id              bigserial PRIMARY KEY,
                organization_id uuid REFERENCES organizations ON DELETE CASCADE,
                event_type      text        NOT NULL,
                aggregate_type  text        NOT NULL,
                aggregate_id    uuid        NOT NULL,
                payload         jsonb       NOT NULL,
                occurred_at     timestamptz NOT NULL,
                published_at    timestamptz,
                attempts        integer     NOT NULL DEFAULT 0,
                last_error      text,
                created_at      timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX outbox_events_unpublished ON outbox_events (occurred_at) WHERE published_at IS NULL');

        DB::statement(<<<'SQL'
            CREATE TABLE audit_logs (
                id              bigserial PRIMARY KEY,
                organization_id uuid REFERENCES organizations ON DELETE CASCADE,
                location_id     uuid REFERENCES locations ON DELETE SET NULL,
                user_id         uuid REFERENCES users ON DELETE SET NULL,
                action          text        NOT NULL,
                entity_type     text        NOT NULL,
                entity_id       uuid,
                before_data     jsonb,
                after_data      jsonb,
                ip_address      inet,
                user_agent      text,
                request_id      text,
                created_at      timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE RULE audit_logs_no_update AS ON UPDATE TO audit_logs DO INSTEAD NOTHING');
        DB::statement('CREATE RULE audit_logs_no_delete AS ON DELETE TO audit_logs DO INSTEAD NOTHING');
        DB::statement('CREATE INDEX audit_logs_by_org ON audit_logs (organization_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_by_entity ON audit_logs (entity_type, entity_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audit_logs, outbox_events, sync_conflicts, idempotency_keys CASCADE');
    }
};
