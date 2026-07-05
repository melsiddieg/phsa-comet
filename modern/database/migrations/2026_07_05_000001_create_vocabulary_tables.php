<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OMOP vocabulary reference tables, loaded from Athena downloads by
 * comet:load-vocab (staged + swapped, never migrated from the legacy DB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concepts', function (Blueprint $table) {
            $table->unsignedBigInteger('concept_id')->primary();
            $table->string('concept_name', 255);
            $table->string('domain_id', 20)->index();
            $table->string('vocabulary_id', 20)->index();
            $table->string('concept_class_id', 20);
            $table->char('standard_concept', 1)->nullable();
            $table->string('concept_code', 50)->index();
            $table->char('valid_start_date', 8);
            $table->char('valid_end_date', 8);
            $table->char('invalid_reason', 1)->nullable();
        });

        Schema::create('concept_synonyms', function (Blueprint $table) {
            $table->unsignedBigInteger('concept_id')->index();
            $table->string('concept_synonym_name', 1000);
            $table->unsignedBigInteger('language_concept_id');
        });

        Schema::create('concept_relationships', function (Blueprint $table) {
            $table->unsignedBigInteger('concept_id_1');
            $table->unsignedBigInteger('concept_id_2');
            $table->string('relationship_id', 20);
            $table->char('valid_start_date', 8);
            $table->char('valid_end_date', 8);
            $table->char('invalid_reason', 1)->nullable();
            $table->primary(['concept_id_1', 'concept_id_2', 'relationship_id']);
        });

        Schema::create('vocabularies', function (Blueprint $table) {
            $table->string('vocabulary_id', 20)->primary();
            $table->string('vocabulary_name', 255);
            $table->string('vocabulary_reference', 255)->nullable();
            $table->string('vocabulary_version', 255)->nullable();
            $table->unsignedBigInteger('vocabulary_concept_id');
        });

        Schema::create('vocab_meta', function (Blueprint $table) {
            $table->id();
            $table->string('athena_release', 255);
            $table->string('loaded_by', 100);
            $table->unsignedBigInteger('concept_count')->default(0);
            $table->timestamp('loaded_at');
        });

        // Usagi-style term matching: trigram similarity over names + synonyms.
        // Postgres-only; tests run on SQLite and skip these.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX concepts_name_trgm ON concepts USING gin (lower(concept_name) gin_trgm_ops)');
            DB::statement('CREATE INDEX concept_synonyms_name_trgm ON concept_synonyms USING gin (lower(concept_synonym_name) gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vocab_meta');
        Schema::dropIfExists('vocabularies');
        Schema::dropIfExists('concept_relationships');
        Schema::dropIfExists('concept_synonyms');
        Schema::dropIfExists('concepts');
    }
};
