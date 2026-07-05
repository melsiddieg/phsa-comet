<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 throughput:
 *  - maps.equivalence: mapping fidelity (Usagi/HL7 vocabulary), nullable.
 *  - map_candidates: precomputed ranked suggestions from comet:auto-map so the
 *    editor and mapping mode open with zero search latency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            // EQUAL | EQUIVALENT | WIDER | NARROWER | INEXACT | null(unspecified)
            $table->string('equivalence', 12)->nullable();
        });

        Schema::create('map_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_term_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('concept_id');
            $table->float('score');
            $table->unsignedSmallInteger('rank');
            $table->string('matched_on', 12)->nullable(); // code|name|synonym|tokens
            $table->string('engine_version', 20)->default('v1');
            $table->timestamp('generated_at');
            $table->unique(['source_term_id', 'concept_id']);
            $table->index(['source_term_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_candidates');
        Schema::table('maps', fn (Blueprint $table) => $table->dropColumn('equivalence'));
    }
};
