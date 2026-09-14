<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE jobs (
                id           bigserial PRIMARY KEY,
                queue        text     NOT NULL,
                payload      text     NOT NULL,
                attempts     smallint NOT NULL,
                reserved_at  integer,
                available_at integer  NOT NULL,
                created_at   integer  NOT NULL
            )
        SQL);
        DB::statement('CREATE INDEX jobs_queue_index ON jobs (queue)');

        DB::statement(<<<'SQL'
            CREATE TABLE job_batches (
                id             text PRIMARY KEY,
                name           text    NOT NULL,
                total_jobs     integer NOT NULL,
                pending_jobs   integer NOT NULL,
                failed_jobs    integer NOT NULL,
                failed_job_ids text    NOT NULL,
                options        text,
                cancelled_at   integer,
                created_at     integer NOT NULL,
                finished_at    integer
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE failed_jobs (
                id         bigserial PRIMARY KEY,
                uuid       text        NOT NULL UNIQUE,
                connection text        NOT NULL,
                queue      text        NOT NULL,
                payload    text        NOT NULL,
                exception  text        NOT NULL,
                failed_at  timestamptz NOT NULL DEFAULT now()
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS failed_jobs, job_batches, jobs CASCADE');
    }
};
