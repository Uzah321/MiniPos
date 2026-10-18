<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('qz_certificates')) {
            Schema::create('qz_certificates', function (Blueprint $table) {
                $table->char('id', 36)->primary();

                $table->char('company_id', 36)->index();
                $table->char('branch_id', 36)->nullable()->index();
                $table->char('till_id', 36)->nullable()->index();

                $table->string('name')->nullable();

                $table->string('certificate_path');
                $table->string('private_key_path');

                $table->boolean('is_active')->default(true)->index();
                $table->text('notes')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(
                    ['company_id', 'branch_id', 'till_id'],
                    'qz_cert_company_branch_till_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qz_certificates');
    }
};
