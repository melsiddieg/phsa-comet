<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Map releases (reproducible, vocab-tagged freezes for ETL consumers),
 * import-run audit (each MappingReport import is a tracked run), and the
 * CDM data dictionary carried over from the legacy app (table descriptions
 * + Mauro catalogue links + source->target scoping/transformation rules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('vocab_release', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('created_by', 100);
            $table->unsignedInteger('map_count')->default(0);
            $table->timestamp('created_at');
        });

        Schema::create('release_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_term_id')->nullable();
            $table->string('source_code', 100);
            $table->string('source_vocabulary_id', 50);
            $table->text('source_code_description')->nullable();
            $table->unsignedBigInteger('target_concept_id');
            $table->string('target_concept_name', 400)->nullable();
            $table->string('target_vocabulary_id', 20)->nullable();
            $table->index('release_id');
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('status', 20)->default('pending'); // pending|running|completed|failed
            $table->string('started_by', 100);
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_new')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_absent')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // ── CDM data dictionary (from phsa_source_tables / phsa_target_tables /
        //    phsa_src_tgt_tables / phsa_target_table_columns) ──
        Schema::create('dd_source_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
        });

        Schema::create('dd_target_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->text('scoping_rule')->nullable();
            $table->text('notes')->nullable();
            $table->string('mauro_link', 500)->nullable();
        });

        Schema::create('dd_source_target_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dd_source_table_id')->constrained('dd_source_tables')->cascadeOnDelete();
            $table->foreignId('dd_target_table_id')->constrained('dd_target_tables')->cascadeOnDelete();
            $table->text('scoping_rule')->nullable();
            $table->string('scoping_jira', 100)->nullable();
            $table->text('transformation_rule')->nullable();
            $table->string('transformation_jira', 100)->nullable();
        });

        Schema::create('dd_target_table_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dd_target_table_id')->constrained('dd_target_tables')->cascadeOnDelete();
            $table->string('column_name', 100);
            $table->text('description')->nullable();
            $table->string('datatype', 50)->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('is_pk')->default(false);
            $table->boolean('is_fk')->default(false);
            $table->string('fk_table', 100)->nullable();
            $table->string('fk_domain', 100)->nullable();
            $table->text('etl_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dd_target_table_columns');
        Schema::dropIfExists('dd_source_target_links');
        Schema::dropIfExists('dd_target_tables');
        Schema::dropIfExists('dd_source_tables');
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('release_maps');
        Schema::dropIfExists('releases');
    }
};
