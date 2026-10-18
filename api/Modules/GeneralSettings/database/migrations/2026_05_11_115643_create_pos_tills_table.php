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
        Schema::create('pos_tills', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id');

            $table->string('till_code');
            $table->string('till_name');
            $table->string('device_label')->nullable();

            // Store hashed token only
            $table->string('registration_token', 128)->nullable()->unique();

            // Optional short code for manual activation if needed later
            $table->string('activation_code', 30)->nullable();

            $table->boolean('is_registered')->default(false);
            $table->boolean('is_active')->default(true);

            $table->uuid('registered_by')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->string('last_ip_address')->nullable();
            $table->text('last_user_agent')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['company_id', 'branch_id', 'till_code'],
                'pos_tills_company_branch_code_unique'
            );

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('till_code');
            $table->index('is_registered');
            $table->index('is_active');

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('company_branches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_tills');
    }
};
