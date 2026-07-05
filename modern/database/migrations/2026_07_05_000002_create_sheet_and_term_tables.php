<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cerner MR sheets and their imported source terms.
 *
 * Normalizes the legacy phsa_mr_sheets 6-flat-"spot" layout into
 * sheet_source_columns, and drops the drift-prone denormalized counters
 * (progress is computed live). Legacy ids are preserved by the data
 * migration for cross-referencing and export diffing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('auto_map_exists')->default(false);
            $table->timestamp('last_import_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sheet_source_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('spot'); // 1-based position
            $table->string('vocabulary', 50);     // source vocabulary label, e.g. 'Cerner Code'
            $table->string('code_label', 100);    // column header for the code in the MR file
            $table->string('desc_label', 100);    // column header for the description
            $table->unique(['sheet_id', 'spot']);
        });

        Schema::create('sheet_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('col_position');
            $table->unique(['sheet_id', 'col_position']);
        });

        Schema::create('sheet_vocabularies', function (Blueprint $table) {
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->string('vocabulary', 50);
            $table->primary(['sheet_id', 'vocabulary']);
        });

        Schema::create('sheet_domains', function (Blueprint $table) {
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->string('domain_id', 30);
            $table->primary(['sheet_id', 'domain_id']);
        });

        // Generalizes the legacy hard-coded Event(15)/Result(16,17) tooltips
        // via ER sheets (18,19): "when showing `sheet`, look rows up in
        // `via_sheet` matching our code at `via_spot_self`, and follow the code
        // at `via_spot_other` into `other_sheet`."
        Schema::create('sheet_cross_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained('sheets')->cascadeOnDelete();
            $table->foreignId('via_sheet_id')->constrained('sheets')->cascadeOnDelete();
            $table->unsignedSmallInteger('via_spot_self');
            $table->unsignedSmallInteger('via_spot_other');
            $table->foreignId('other_sheet_id')->constrained('sheets')->cascadeOnDelete();
            $table->string('label', 10); // e.g. 'R', 'E'
        });

        Schema::create('source_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('total_count')->nullable(); // production usage frequency
            $table->string('map_source', 10)->nullable();          // Auto | User
            $table->string('mr_status', 30)->nullable();           // New in MR | Still in MR | Absent in latest MR
            $table->string('exclude_status', 40)->nullable();      // Out of Scope - Exclude | Question - Pending | SDO ...
            $table->string('inserted_by', 100)->nullable();
            $table->string('updated_by', 100)->nullable();
            $table->timestamps();
            $table->index(['sheet_id', 'exclude_status']);
        });

        Schema::create('source_term_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_term_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('spot');
            $table->string('code', 100)->index();
            $table->text('description')->nullable();
            $table->unique(['source_term_id', 'spot']);
        });

        Schema::create('source_term_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sheet_attribute_id')->constrained()->cascadeOnDelete();
            $table->text('value')->nullable();
        });

        Schema::create('source_term_comments', function (Blueprint $table) {
            $table->foreignId('source_term_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('comment_text')->nullable();
            $table->string('created_by', 100)->nullable();
            $table->string('updated_by', 100)->nullable();
            $table->timestamps();
        });

        // Candidate target carried in the Cerner MappingReport's own
        // "OMOP Concept ID" column (may be several per term).
        Schema::create('suggested_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_term_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('concept_id')->index();
        });

        // Duplicate-map propagation matches identical spot-1 descriptions.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX source_term_codes_desc_lower ON source_term_codes (lower(trim(description))) WHERE spot = 1');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suggested_targets');
        Schema::dropIfExists('source_term_comments');
        Schema::dropIfExists('source_term_attributes');
        Schema::dropIfExists('source_term_codes');
        Schema::dropIfExists('source_terms');
        Schema::dropIfExists('sheet_cross_references');
        Schema::dropIfExists('sheet_domains');
        Schema::dropIfExists('sheet_vocabularies');
        Schema::dropIfExists('sheet_attributes');
        Schema::dropIfExists('sheet_source_columns');
        Schema::dropIfExists('sheets');
    }
};
