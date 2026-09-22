<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('scope_type')->default('global');
            $table->foreignUuid('country_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('complex_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->unsignedSmallInteger('password_min_length')->default(12);
            $table->boolean('password_require_uppercase')->default(true);
            $table->boolean('password_require_lowercase')->default(true);
            $table->boolean('password_require_number')->default(true);
            $table->boolean('password_require_symbol')->default(true);
            $table->unsignedSmallInteger('password_expiry_days')->default(90);
            $table->unsignedSmallInteger('password_history_count')->default(5);
            $table->unsignedSmallInteger('pin_length')->default(6);
            $table->boolean('pin_disallow_sequential')->default(true);
            $table->boolean('pin_disallow_repeated')->default(true);
            $table->unsignedSmallInteger('pin_expiry_days')->default(90);
            $table->unsignedSmallInteger('pin_history_count')->default(5);
            $table->unsignedSmallInteger('max_failed_attempts')->default(5);
            $table->unsignedSmallInteger('lockout_minutes')->default(15);
            $table->unsignedSmallInteger('back_office_session_minutes')->default(480);
            $table->unsignedSmallInteger('pos_session_minutes')->default(720);
            $table->boolean('require_mfa_for_back_office')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['scope_type', 'active', 'priority']);
        });

        Schema::create('user_access_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('scope_type')->default('global');
            $table->foreignUuid('country_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('complex_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status', 'scope_type']);
            $table->index(['country_id', 'brand_id', 'complex_id', 'store_id'], 'access_org_scope_index');
        });

        Schema::create('user_password_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('credential_type');
            $table->string('credential_hash');
            $table->timestamps();

            $table->index(['user_id', 'credential_type', 'created_at'], 'credential_history_index');
        });

        Schema::create('user_mfa_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('totp');
            $table->text('secret');
            $table->json('recovery_code_hashes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });

        Schema::create('mfa_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
            $table->foreignUuid('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel');
            $table->string('device_name')->nullable();
            $table->string('device_identifier_hash', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'channel', 'revoked_at']);
        });

        Schema::create('authentication_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->string('channel')->nullable();
            $table->string('identifier_hash', 64)->nullable()->index();
            $table->foreignUuid('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_events');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('mfa_challenges');
        Schema::dropIfExists('user_mfa_methods');
        Schema::dropIfExists('user_password_histories');
        Schema::dropIfExists('user_access_assignments');
        Schema::dropIfExists('password_policies');
    }
};
