<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A company is the tenant; the countries it trades in are an explicit
 * operating footprint. Brands and complexes are created in one of the
 * company's operating countries instead of always inheriting the head-office
 * country, so one company can run shops in several countries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_countries')) {
            Schema::create('company_countries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('country_id');
                $table->boolean('is_home')->default(false);
                $table->string('status', 20)->default('active');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'country_id'], 'company_countries_company_country_uq');
                $table->index(['country_id', 'status'], 'company_countries_country_status_idx');
                $table->foreign('company_id', 'company_countries_company_fk')
                    ->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('country_id', 'company_countries_country_fk')
                    ->references('id')->on('countries')->restrictOnDelete();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('company_countries');
    }

    /** Every country a company already uses becomes an operating country. */
    private function backfill(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('countries')) {
            return;
        }

        $now = now();
        $pairs = [];
        if (Schema::hasColumn('companies', 'country_id')) {
            DB::table('companies')->whereNotNull('country_id')->orderBy('id')
                ->each(function (object $company) use (&$pairs): void {
                    $pairs[$company->id.'|'.$company->country_id] = true;
                });
        }
        foreach (['brands', 'complexes', 'shops'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, ['company_id', 'country_id'])) {
                continue;
            }
            DB::table($table)->whereNotNull('company_id')->whereNotNull('country_id')
                ->select(['company_id', 'country_id'])->distinct()->orderBy('company_id')
                ->each(function (object $row) use (&$pairs): void {
                    $pairs[$row->company_id.'|'.$row->country_id] ??= false;
                }, 500);
        }

        $countryIds = DB::table('countries')->pluck('id')->map(fn ($id): string => (string) $id)->flip();
        foreach (array_keys($pairs) as $pair) {
            [$companyId, $countryId] = explode('|', $pair, 2);
            if (! $countryIds->has($countryId)) {
                continue;
            }
            $isHome = (string) DB::table('companies')->where('id', $companyId)->value('country_id') === $countryId;
            $exists = DB::table('company_countries')
                ->where('company_id', $companyId)
                ->where('country_id', $countryId)
                ->exists();
            if (! $exists) {
                DB::table('company_countries')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'country_id' => $countryId,
                    'is_home' => $isHome,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
