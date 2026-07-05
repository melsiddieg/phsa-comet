<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1 search infrastructure:
 *  - unaccent extension + an IMMUTABLE wrapper (f_unaccent) so it can be used
 *    in expression indexes — needed for bilingual (FR/EN) synonym search;
 *  - accent-insensitive trigram indexes and 'simple'-config tsvector indexes
 *    over concept names + synonyms (token-coverage ranking for long phrases);
 *  - concept_ancestors (CONCEPT_ANCESTOR) for hierarchy browsing.
 *
 * comet:load-vocab builds the same indexes on staging tables before each swap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concept_ancestors', function (Blueprint $table) {
            $table->unsignedBigInteger('ancestor_concept_id');
            $table->unsignedBigInteger('descendant_concept_id');
            $table->unsignedSmallInteger('min_levels_of_separation');
            $table->unsignedSmallInteger('max_levels_of_separation');
            $table->primary(['ancestor_concept_id', 'descendant_concept_id']);
            $table->index('descendant_concept_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return; // SQLite test runs use the LIKE fallback; no pg objects
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        // unaccent() is STABLE, not IMMUTABLE; wrap it with the dictionary
        // pinned so Postgres allows it in expression indexes.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text AS
            $$ SELECT public.unaccent('public.unaccent', $1) $$
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
        SQL);

        // Accent-insensitive trigram + token search over names and synonyms.
        DB::statement('CREATE INDEX IF NOT EXISTS concepts_name_unaccent_trgm ON concepts USING gin (lower(f_unaccent(concept_name)) gin_trgm_ops)');
        DB::statement("CREATE INDEX IF NOT EXISTS concepts_name_tsv ON concepts USING gin (to_tsvector('simple', f_unaccent(concept_name)))");
        DB::statement('CREATE INDEX IF NOT EXISTS concept_synonyms_name_unaccent_trgm ON concept_synonyms USING gin (lower(f_unaccent(concept_synonym_name)) gin_trgm_ops)');
        DB::statement("CREATE INDEX IF NOT EXISTS concept_synonyms_name_tsv ON concept_synonyms USING gin (to_tsvector('simple', f_unaccent(concept_synonym_name)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('concept_ancestors');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS concepts_name_unaccent_trgm');
            DB::statement('DROP INDEX IF EXISTS concepts_name_tsv');
            DB::statement('DROP INDEX IF EXISTS concept_synonyms_name_unaccent_trgm');
            DB::statement('DROP INDEX IF EXISTS concept_synonyms_name_tsv');
            DB::statement('DROP FUNCTION IF EXISTS f_unaccent(text)');
        }
    }
};
