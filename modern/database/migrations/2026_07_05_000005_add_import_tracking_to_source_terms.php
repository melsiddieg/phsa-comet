<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the legacy per-row `inphp_status` "seen this import" flag with a
 * run stamp: terms whose last_seen_import_run_id != the current run are marked
 * "Absent in latest MR" after the import completes (MR drift tracking).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_terms', function (Blueprint $table) {
            $table->unsignedBigInteger('last_seen_import_run_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('source_terms', function (Blueprint $table) {
            $table->dropColumn('last_seen_import_run_id');
        });
    }
};
