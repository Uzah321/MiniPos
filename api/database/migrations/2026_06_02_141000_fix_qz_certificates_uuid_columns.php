<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('qz_certificates')) {
            DB::statement("ALTER TABLE qz_certificates MODIFY id CHAR(36) NOT NULL");
            DB::statement("ALTER TABLE qz_certificates MODIFY company_id CHAR(36) NOT NULL");
            DB::statement("ALTER TABLE qz_certificates MODIFY branch_id CHAR(36) NULL");
            DB::statement("ALTER TABLE qz_certificates MODIFY till_id CHAR(36) NULL");

            DB::statement("ALTER TABLE qz_certificates MODIFY certificate_path VARCHAR(500) NOT NULL");
            DB::statement("ALTER TABLE qz_certificates MODIFY private_key_path VARCHAR(500) NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('qz_certificates')) {
            DB::statement("ALTER TABLE qz_certificates MODIFY certificate_path VARCHAR(255) NOT NULL");
            DB::statement("ALTER TABLE qz_certificates MODIFY private_key_path VARCHAR(255) NOT NULL");
        }
    }
};
