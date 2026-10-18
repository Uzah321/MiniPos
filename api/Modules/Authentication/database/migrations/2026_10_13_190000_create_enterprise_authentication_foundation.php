<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendPosUsers();
        $this->createAuthPolicies();
        $this->createUserAccessAssignments();
        $this->createCredentialHistory();
        $this->createAuthSessions();
        $this->createAuthenticationEvents();
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_events');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('auth_credential_histories');
        Schema::dropIfExists('user_access_assignments');
        Schema::dropIfExists('auth_policies');

        if (! Schema::hasTable('pos_users')) {
            return;
        }

        if (Schema::hasIndex('pos_users', 'pos_users_company_code_lookup_uq')) {
            Schema::table('pos_users', function (Blueprint $table): void {
                $table->dropUnique('pos_users_company_code_lookup_uq');
            });
        }

        foreach ([
            'login_code_lookup',
            'login_code_lookup_key_id',
            'credential_version',
            'credentials_changed_at',
        ] as $column) {
            if (Schema::hasColumn('pos_users', $column)) {
                Schema::table('pos_users', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    private function extendPosUsers(): void
    {
        if (! Schema::hasTable('pos_users')) {
            return;
        }

        $missing = collect([
            'login_code_lookup',
            'login_code_lookup_key_id',
            'credential_version',
            'credentials_changed_at',
        ])->reject(fn (string $column) => Schema::hasColumn('pos_users', $column));

        if ($missing->isNotEmpty()) {
            Schema::table('pos_users', function (Blueprint $table) use ($missing): void {
                if ($missing->contains('login_code_lookup')) {
                    $table->char('login_code_lookup', 64)->nullable();
                }
                if ($missing->contains('login_code_lookup_key_id')) {
                    $table->string('login_code_lookup_key_id', 32)->nullable();
                }
                if ($missing->contains('credential_version')) {
                    $table->unsignedBigInteger('credential_version')->default(1);
                }
                if ($missing->contains('credentials_changed_at')) {
                    $table->timestamp('credentials_changed_at')->nullable();
                }
            });
        }

        if (Schema::hasColumn('pos_users', 'login_code_lookup')
            && ! Schema::hasIndex('pos_users', 'pos_users_company_code_lookup_uq')) {
            Schema::table('pos_users', function (Blueprint $table): void {
                // NULL values preserve legacy users until their code is reset.
                // New indexed codes are unique inside their owning company.
                $table->unique(
                    ['company_id', 'login_code_lookup'],
                    'pos_users_company_code_lookup_uq'
                );
            });
        }
    }

    private function createAuthPolicies(): void
    {
        if (Schema::hasTable('auth_policies')) {
            return;
        }

        Schema::create('auth_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('policy_key', 80);
            $this->scopeColumns($table);
            $table->unsignedInteger('priority')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->json('settings');
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['policy_key', 'scope_key', 'version'],
                'auth_policy_scope_version_uq'
            );
            $table->index(
                ['policy_key', 'is_active', 'scope_key'],
                'auth_policy_resolution_idx'
            );
            $table->index(
                ['effective_from', 'effective_until'],
                'auth_policy_window_idx'
            );

            $table->foreign('created_by', 'auth_policy_created_by_fk')
                ->references('id')->on('pos_users')->nullOnDelete();
            $table->foreign('updated_by', 'auth_policy_updated_by_fk')
                ->references('id')->on('pos_users')->nullOnDelete();
        });
    }

    private function createUserAccessAssignments(): void
    {
        if (Schema::hasTable('user_access_assignments')) {
            return;
        }

        Schema::create('user_access_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pos_user_id');
            $table->uuid('role_id');
            $this->scopeColumns($table);
            $table->string('status', 20)->default('active');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->uuid('granted_by')->nullable();
            $table->text('grant_reason')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            // One durable row per user/role/scope. Regranting updates the row;
            // immutable changes are retained in authentication_events.
            $table->unique(
                ['pos_user_id', 'role_id', 'scope_key'],
                'user_access_assignment_uq'
            );
            $table->index(
                ['pos_user_id', 'status', 'valid_from', 'valid_until'],
                'user_access_effective_idx'
            );
            $table->index(
                ['scope_key', 'status'],
                'user_access_scope_idx'
            );

            $table->foreign('pos_user_id', 'user_access_user_fk')
                ->references('id')->on('pos_users')->cascadeOnDelete();
            $table->foreign('role_id', 'user_access_role_fk')
                ->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('granted_by', 'user_access_granted_by_fk')
                ->references('id')->on('pos_users')->nullOnDelete();
            $table->foreign('revoked_by', 'user_access_revoked_by_fk')
                ->references('id')->on('pos_users')->nullOnDelete();
        });
    }

    private function createCredentialHistory(): void
    {
        if (Schema::hasTable('auth_credential_histories')) {
            return;
        }

        Schema::create('auth_credential_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pos_user_id');
            $table->string('credential_type', 32);
            $table->string('credential_hash');
            $table->unsignedBigInteger('credential_version');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['pos_user_id', 'credential_type', 'credential_version'],
                'auth_credential_version_uq'
            );
            $table->index(
                ['pos_user_id', 'credential_type', 'created_at'],
                'auth_credential_history_idx'
            );
            $table->foreign('pos_user_id', 'auth_credential_user_fk')
                ->references('id')->on('pos_users')->cascadeOnDelete();
        });
    }

    private function createAuthSessions(): void
    {
        if (Schema::hasTable('auth_sessions')) {
            return;
        }

        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pos_user_id');
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->string('channel', 32);
            $table->string('workspace', 32)->nullable();
            $table->string('authentication_method', 32);
            $this->scopeColumns($table, includeScopeIdentity: false);
            $table->uuid('branch_id')->nullable();
            $table->unsignedBigInteger('credential_version')->default(1);
            $table->char('policy_fingerprint', 64)->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->char('device_identifier_hash', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 120)->nullable();
            $table->timestamps();

            $table->unique('personal_access_token_id', 'auth_session_token_uq');
            $table->index(
                ['pos_user_id', 'revoked_at', 'expires_at'],
                'auth_session_user_active_idx'
            );
            $table->index(
                ['company_id', 'shop_id', 'till_id', 'revoked_at'],
                'auth_session_location_idx'
            );
            $table->foreign('pos_user_id', 'auth_session_user_fk')
                ->references('id')->on('pos_users')->cascadeOnDelete();
        });
    }

    private function createAuthenticationEvents(): void
    {
        if (Schema::hasTable('authentication_events')) {
            return;
        }

        Schema::create('authentication_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 80);
            $table->string('outcome', 20);
            $table->uuid('pos_user_id')->nullable();
            $table->uuid('actor_pos_user_id')->nullable();
            $table->uuid('auth_session_id')->nullable();
            $table->string('channel', 32)->nullable();
            $table->string('workspace', 32)->nullable();
            $table->string('authentication_method', 32)->nullable();
            $table->char('identifier_hash', 64)->nullable();
            $this->scopeColumns($table, includeScopeIdentity: false);
            $table->uuid('branch_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('reason_code', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(
                ['pos_user_id', 'occurred_at'],
                'auth_event_user_time_idx'
            );
            $table->index(
                ['actor_pos_user_id', 'occurred_at'],
                'auth_event_actor_time_idx'
            );
            $table->index(
                ['company_id', 'shop_id', 'till_id', 'occurred_at'],
                'auth_event_scope_time_idx'
            );
            $table->index(
                ['event_type', 'outcome', 'occurred_at'],
                'auth_event_type_time_idx'
            );
            $table->index('identifier_hash', 'auth_event_identifier_idx');
        });
    }

    private function scopeColumns(Blueprint $table, bool $includeScopeIdentity = true): void
    {
        if ($includeScopeIdentity) {
            $table->string('scope_type', 32);
            $table->string('scope_key', 80);
        }

        $table->uuid('country_id')->nullable();
        $table->uuid('company_id')->nullable();
        $table->uuid('brand_id')->nullable();
        $table->uuid('complex_id')->nullable();
        $table->uuid('shop_id')->nullable();
        $table->uuid('till_id')->nullable();
    }
};
