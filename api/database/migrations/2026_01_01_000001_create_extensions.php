<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');   // gen_random_uuid()
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');     // emails insensibles à la casse
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');    // recherche produit
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist'); // EXCLUDE sur les prix
    }

    public function down(): void
    {
        // Les extensions ne sont jamais retirées : d'autres objets en dépendent.
    }
};
