<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry that pins each custom (Canadian) source code to a stable
 * concept_id in the 2-billion range, so re-running the CIHI/Infoway
 * converters produces identical ids across releases (maps stay valid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_concept_ids', function (Blueprint $table) {
            $table->unsignedBigInteger('concept_id')->primary();
            $table->string('vocabulary_id', 20);
            $table->string('concept_code', 50);
            $table->timestamp('created_at');
            $table->unique(['vocabulary_id', 'concept_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_concept_ids');
    }
};
