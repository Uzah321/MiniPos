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
        Schema::create('audit_trails', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Company / Branch Scope
            |--------------------------------------------------------------------------
            */
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | User Who Performed The Action
            |--------------------------------------------------------------------------
            */
            $table->uuid('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_role')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Action Details
            |--------------------------------------------------------------------------
            | Examples:
            | create, update, delete, login, logout, approve, reject,
            | stock_receive, stock_adjust, product_break, price_change
            |--------------------------------------------------------------------------
            */
            $table->string('action', 100);
            $table->string('module', 100)->nullable();
            $table->string('description')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Auditable Record
            |--------------------------------------------------------------------------
            | auditable_type: Product, Supplier, PosUser, Role, Sale, Shift, etc.
            | auditable_id: UUID/id of the affected record
            |--------------------------------------------------------------------------
            */
            $table->string('auditable_type')->nullable();
            $table->uuid('auditable_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Before / After Data
            |--------------------------------------------------------------------------
            */
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changed_fields')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Request / Device Info
            |--------------------------------------------------------------------------
            */
            $table->string('ip_address', 100)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_name')->nullable();
            $table->string('terminal_name')->nullable();
            $table->string('session_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */
            $table->string('status', 50)->default('success');
            $table->text('error_message')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Extra Metadata
            |--------------------------------------------------------------------------
            */
            $table->json('metadata')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index('company_id');
            $table->index('branch_id');
            $table->index('user_id');
            $table->index('action');
            $table->index('module');
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('status');
            $table->index('created_at');

            /*
            |--------------------------------------------------------------------------
            | Foreign Keys
            |--------------------------------------------------------------------------
            | Keep nullable with nullOnDelete so audit history is not deleted
            | when related records are removed.
            |--------------------------------------------------------------------------
            */
            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->nullOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('company_branches')
                ->nullOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('pos_users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_trails');
    }
};
