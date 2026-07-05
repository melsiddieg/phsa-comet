<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Audit of role/enabled changes made through the user-admin UI. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('field', 40);   // is_mapper | is_importer | ... | enabled
            $table->string('old_value', 10)->nullable();
            $table->string('new_value', 10)->nullable();
            $table->string('changed_by', 100);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_audits');
    }
};
