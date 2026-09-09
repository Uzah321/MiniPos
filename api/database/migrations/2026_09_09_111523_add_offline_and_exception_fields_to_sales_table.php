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
        Schema::table('sales', function (Blueprint $table) {
            $table->string('client_reference')->nullable()->unique()->after('id');
            $table->boolean('is_offline')->default(false)->after('client_reference');
            $table->foreignId('exception_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exception_reviewed_at')->nullable();
            $table->text('exception_note')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exception_reviewed_by');
            $table->dropColumn(['client_reference', 'is_offline', 'exception_reviewed_at', 'exception_note']);
        });
    }
};
