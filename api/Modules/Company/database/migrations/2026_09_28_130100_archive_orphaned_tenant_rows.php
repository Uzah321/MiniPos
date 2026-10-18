<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $tables = ['company_clock_guards', 'discount_types', 'general_settings'];

    public function up(): void
    {
        Schema::create('tenant_orphan_archives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_table', 128)->index();
            $table->string('source_id', 191)->nullable()->index();
            $table->uuid('original_company_id')->index();
            $table->json('payload');
            $table->string('reason', 255);
            $table->timestamp('archived_at');
            $table->timestamps();
            $table->unique(['source_table', 'source_id', 'original_company_id'], 'tenant_orphan_archive_source_unique');
        });

        DB::transaction(function () {
            foreach ($this->tables as $sourceTable) {
                if (!Schema::hasTable($sourceTable) || !Schema::hasColumn($sourceTable, 'company_id')) continue;

                $rows = DB::table($sourceTable . ' as orphan')
                    ->leftJoin('companies as owner', 'owner.id', '=', 'orphan.company_id')
                    ->whereNotNull('orphan.company_id')
                    ->whereNull('owner.id')
                    ->select('orphan.*')
                    ->get();

                foreach ($rows as $row) {
                    $payload = (array) $row;
                    DB::table('tenant_orphan_archives')->insert([
                        'id' => (string) Str::uuid(),
                        'source_table' => $sourceTable,
                        'source_id' => isset($payload['id']) ? (string) $payload['id'] : null,
                        'original_company_id' => (string) $payload['company_id'],
                        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'reason' => 'Source company was deleted; archived to preserve tenant isolation.',
                        'archived_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if ($rows->isNotEmpty()) {
                    DB::table($sourceTable)
                        ->whereIn('company_id', $rows->pluck('company_id')->unique()->all())
                        ->whereNotIn('company_id', DB::table('companies')->select('id'))
                        ->delete();
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tenant_orphan_archives')) return;

        DB::transaction(function () {
            foreach (DB::table('tenant_orphan_archives')->orderBy('archived_at')->get() as $archive) {
                if (!in_array($archive->source_table, $this->tables, true) || !Schema::hasTable($archive->source_table)) continue;
                $payload = json_decode($archive->payload, true);
                if (!is_array($payload)) continue;
                $exists = isset($payload['id']) && DB::table($archive->source_table)->where('id', $payload['id'])->exists();
                if (!$exists) DB::table($archive->source_table)->insert($payload);
            }
        });

        Schema::dropIfExists('tenant_orphan_archives');
    }
};
