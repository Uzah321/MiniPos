<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standalone_provisioning_imports', function (Blueprint $table): void {
            $table->uuid('package_id')->primary();
            $table->uuid('company_id')->index();
            $table->char('payload_hash', 64);
            $table->string('signature_algorithm', 32);
            $table->string('signature_key_id', 64);
            // DATETIME avoids the implicit zero/default TIMESTAMP rules on
            // older MySQL/MariaDB installations used by some edge servers.
            $table->dateTime('package_issued_at');
            $table->dateTime('package_expires_at');
            $table->dateTime('imported_at');
            $table->timestamps();

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standalone_provisioning_imports');
    }
};
