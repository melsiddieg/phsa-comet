<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 team workflow:
 *  - claimed_by/claimed_at: lightweight, auto-expiring claim so two mappers
 *    don't silently work the same term.
 *  - review_state + review_comment: reviewer "request changes" loop; a returned
 *    term surfaces to the mapper and clears when they re-save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_terms', function (Blueprint $table) {
            $table->string('claimed_by', 100)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('review_state', 12)->nullable(); // null | 'returned'
            $table->string('returned_to', 100)->nullable(); // mapper who last touched it
            $table->index('claimed_by');
            $table->index('review_state');
        });
    }

    public function down(): void
    {
        Schema::table('source_terms', function (Blueprint $table) {
            $table->dropColumn(['claimed_by', 'claimed_at', 'review_state', 'returned_to']);
        });
    }
};
