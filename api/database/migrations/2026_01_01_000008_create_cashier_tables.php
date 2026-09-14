<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE cashier_sessions (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid        NOT NULL REFERENCES organizations ON DELETE CASCADE,
                location_id     uuid        NOT NULL REFERENCES locations,
                register_code   text        NOT NULL,
                opened_by       uuid        NOT NULL REFERENCES users,
                opened_at       timestamptz NOT NULL,
                closed_by       uuid REFERENCES users,
                closed_at       timestamptz,
                status          text        NOT NULL DEFAULT 'OPEN'
                                CHECK (status IN ('OPEN','CLOSED','AMENDED')),
                notes           text,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        // Une seule session ouverte par caisse physique, garanti par la base.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cashier_sessions_one_open
                ON cashier_sessions (location_id, register_code) WHERE status = 'OPEN'
        SQL);
        DB::statement('CREATE INDEX cashier_sessions_by_location ON cashier_sessions (location_id, opened_at DESC)');

        // Un fonds de caisse et un comptage PAR DEVISE : le tiroir contient
        // des gourdes ET des dollars. [D-04]
        DB::statement(<<<'SQL'
            CREATE TABLE cashier_session_totals (
                cashier_session_id uuid    NOT NULL REFERENCES cashier_sessions ON DELETE CASCADE,
                currency           char(3) NOT NULL,
                opening_minor      bigint  NOT NULL DEFAULT 0,
                expected_minor     bigint  NOT NULL DEFAULT 0,
                counted_minor      bigint,
                variance_minor     bigint GENERATED ALWAYS AS (counted_minor - expected_minor) STORED,
                PRIMARY KEY (cashier_session_id, currency)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS cashier_session_totals, cashier_sessions CASCADE');
    }
};
