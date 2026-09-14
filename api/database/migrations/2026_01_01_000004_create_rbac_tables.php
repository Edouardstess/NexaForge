<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE permissions (
                code        text PRIMARY KEY,
                "group"     text NOT NULL,
                description text NOT NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE roles (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                organization_id uuid REFERENCES organizations ON DELETE CASCADE,
                code            text NOT NULL,
                name            text NOT NULL,
                created_at      timestamptz NOT NULL DEFAULT now(),
                UNIQUE NULLS NOT DISTINCT (organization_id, code)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE role_permissions (
                role_id         uuid NOT NULL REFERENCES roles ON DELETE CASCADE,
                permission_code text NOT NULL REFERENCES permissions ON DELETE CASCADE,
                PRIMARY KEY (role_id, permission_code)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE memberships (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id             uuid        NOT NULL REFERENCES users ON DELETE CASCADE,
                organization_id     uuid        NOT NULL REFERENCES organizations ON DELETE CASCADE,
                role_id             uuid        NOT NULL REFERENCES roles,
                status              text        NOT NULL DEFAULT 'ACTIVE'
                                    CHECK (status IN ('ACTIVE','SUSPENDED')),
                permissions_version integer     NOT NULL DEFAULT 1,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),
                UNIQUE (user_id, organization_id)
            )
        SQL);

        // LA table qui manquait aux documents. Ensemble VIDE = toutes les locations.
        DB::statement(<<<'SQL'
            CREATE TABLE membership_locations (
                membership_id uuid NOT NULL REFERENCES memberships ON DELETE CASCADE,
                location_id   uuid NOT NULL REFERENCES locations   ON DELETE CASCADE,
                PRIMARY KEY (membership_id, location_id)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE membership_permission_overrides (
                membership_id   uuid NOT NULL REFERENCES memberships ON DELETE CASCADE,
                permission_code text NOT NULL REFERENCES permissions ON DELETE CASCADE,
                effect          text NOT NULL CHECK (effect IN ('GRANT','REVOKE')),
                PRIMARY KEY (membership_id, permission_code)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS membership_permission_overrides, membership_locations, memberships, role_permissions, roles, permissions CASCADE');
    }
};
