<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps and their immutable audit trail.
 *
 * A map links a source term to one OMOP standard concept (a term may carry
 * several maps = one-to-many mapping). Source codes live on the term
 * (source_term_codes); exports join them, so the map row itself stays
 * normalized. map_audits is append-only: every Add/Update/Delete/status
 * change writes one row carrying a JSON snapshot of the term's codes at the
 * time of the change (audit fidelity even if the term is later re-imported).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maps', function (Blueprint $table) {
            $table->id();
            // Nullable: legacy maps whose source term dropped out of later MR
            // imports stay unlinked but keep their own source snapshot below.
            $table->foreignId('source_term_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source_code', 100)->nullable();          // spot-1 snapshot (unlinked maps)
            $table->string('source_vocabulary_id', 50)->nullable();
            $table->text('source_code_description')->nullable();
            $table->json('source_snapshot')->nullable();              // all spots, unlinked maps only
            $table->unsignedBigInteger('target_concept_id')->index();
            $table->string('target_concept_name', 400);  // snapshot at mapping time
            $table->string('target_vocabulary_id', 20);
            $table->string('created_by', 100)->nullable();
            $table->string('updated_by', 100)->nullable();
            $table->timestamps();
            $table->index('source_term_id');
        });

        Schema::create('map_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('map_id')->nullable(); // null for term-status changes
            $table->foreignId('source_term_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30); // Add | Update | Delete | Include | Exclude | Question | Set to SDO Submission | Sent to SDO
            $table->unsignedBigInteger('target_concept_id')->nullable();
            $table->string('target_concept_name', 400)->nullable();
            $table->string('target_vocabulary_id', 20)->nullable();
            $table->unsignedBigInteger('before_target_concept_id')->nullable();
            $table->json('source_snapshot')->nullable(); // term codes at change time
            $table->string('username', 100);
            $table->string('approved_by', 100)->nullable(); // null = pending review
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['source_term_id', 'approved_by']);
            $table->index('approved_by');
        });

        // Per-approval snapshot of a term's full target set (before/after
        // comparison in review, same role as legacy phsa_maps_snapshot).
        Schema::create('map_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_term_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('concept_id');
            $table->string('approved_by', 100)->nullable();
            $table->timestamp('created_at');
            $table->index('source_term_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_snapshots');
        Schema::dropIfExists('map_audits');
        Schema::dropIfExists('maps');
    }
};
