<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            // Nullable: Entra-provisioned accounts have no local password.
            // Only break-glass local admins (auth_source = 'local') carry one.
            $table->string('password')->nullable();
            $table->uuid('entra_oid')->nullable()->unique();
            $table->string('auth_source', 10)->default('entra'); // 'entra' | 'local'
            $table->boolean('enabled')->default(true);
            // COMET roles (managed in-app; Entra authenticates only)
            $table->boolean('is_mapper')->default(false);
            $table->boolean('is_importer')->default(false);
            $table->boolean('is_reviewer')->default(false);
            $table->boolean('is_portal_admin')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
