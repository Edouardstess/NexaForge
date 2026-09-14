<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // En Haïti le téléphone est souvent le seul identifiant : l'un OU l'autre suffit.
        DB::statement(<<<'SQL'
            CREATE TABLE users (
                id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                email             citext UNIQUE,
                phone             text UNIQUE,
                password_hash     text        NOT NULL,
                first_name        text        NOT NULL,
                last_name         text        NOT NULL,
                locale            text        NOT NULL DEFAULT 'ht',
                status            text        NOT NULL DEFAULT 'ACTIVE'
                                  CHECK (status IN ('ACTIVE','SUSPENDED','INVITED','DELETED')),
                totp_secret       text,
                totp_confirmed_at timestamptz,
                last_login_at     timestamptz,
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now(),
                CHECK (email IS NOT NULL OR phone IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE login_attempts (
                id         bigserial PRIMARY KEY,
                identifier citext      NOT NULL,
                ip_address inet        NOT NULL,
                successful boolean     NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX login_attempts_lookup ON login_attempts (identifier, ip_address, created_at DESC)');

        DB::statement(<<<'SQL'
            CREATE TABLE personal_access_tokens (
                id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id      uuid        NOT NULL REFERENCES users ON DELETE CASCADE,
                name         text        NOT NULL,
                token        char(64)    NOT NULL UNIQUE,
                abilities    jsonb       NOT NULL DEFAULT '["*"]'::jsonb,
                device_name  text,
                last_used_at timestamptz,
                expires_at   timestamptz,
                revoked_at   timestamptz,
                created_at   timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX personal_access_tokens_user ON personal_access_tokens (user_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE recovery_codes (
                id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id    uuid NOT NULL REFERENCES users ON DELETE CASCADE,
                code_hash  text NOT NULL,
                used_at    timestamptz,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX recovery_codes_user ON recovery_codes (user_id) WHERE used_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS recovery_codes, personal_access_tokens, login_attempts, users CASCADE');
    }
};
