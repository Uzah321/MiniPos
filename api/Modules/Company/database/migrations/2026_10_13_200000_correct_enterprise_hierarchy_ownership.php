<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->makeComplexCompanyOwned();
        $this->addCanonicalTillScope();
    }

    public function down(): void
    {
        // This migration deliberately removes the false Brand -> Complex
        // ownership. A complex may now contain shops from multiple brands, so
        // no lossless value can be reconstructed for complexes.brand_id.
        // Refuse a partial rollback that would silently corrupt that model.
        throw new RuntimeException(
            'The corrected enterprise hierarchy ownership migration is irreversible. Restore from backup to roll it back.'
        );
    }

    private function makeComplexCompanyOwned(): void
    {
        if (! Schema::hasTable('complexes') || ! Schema::hasColumn('complexes', 'brand_id')) {
            return;
        }

        $this->dropForeignForColumn('complexes', 'brand_id');
        if (Schema::hasIndex('complexes', 'complexes_brand_code_uq')) {
            Schema::table('complexes', fn (Blueprint $table) => $table->dropUnique('complexes_brand_code_uq'));
        }

        Schema::table('complexes', function (Blueprint $table): void {
            $table->uuid('brand_id')->nullable()->change();
        });

        // Shops retain the canonical brand assignment. A complex is physical
        // company infrastructure and therefore has no single owning brand.
        DB::table('complexes')->update(['brand_id' => null]);

        $this->deduplicateComplexCodes();
        if (! Schema::hasIndex('complexes', 'complexes_company_code_uq')) {
            Schema::table('complexes', fn (Blueprint $table) => $table
                ->unique(['company_id', 'code'], 'complexes_company_code_uq'));
        }
    }

    /**
     * Brand-scoped legacy data may reuse a code inside one company. Preserve
     * every row while making the new company-owned identifier deterministic.
     */
    private function deduplicateComplexCodes(): void
    {
        $seen = [];
        DB::table('complexes')
            ->select(['id', 'company_id', 'code'])
            ->orderBy('company_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->each(function (object $complex) use (&$seen): void {
                $companyId = (string) $complex->company_id;
                $base = strtoupper(trim((string) $complex->code));
                $base = $base !== '' ? $base : 'COMPLEX';
                $key = mb_strtolower($base);
                if (! isset($seen[$companyId][$key])) {
                    $seen[$companyId][$key] = true;
                    if ((string) $complex->code !== $base) {
                        DB::table('complexes')->where('id', $complex->id)->update(['code' => $base]);
                    }

                    return;
                }

                $suffix = 2;
                do {
                    $tail = '-'.$suffix++;
                    $candidate = substr($base, 0, 80 - strlen($tail)).$tail;
                    $candidateKey = mb_strtolower($candidate);
                } while (isset($seen[$companyId][$candidateKey]));

                $seen[$companyId][$candidateKey] = true;
                DB::table('complexes')->where('id', $complex->id)->update(['code' => $candidate]);
            });
    }

    private function addCanonicalTillScope(): void
    {
        if (! Schema::hasTable('pos_tills')) {
            return;
        }

        Schema::table('pos_tills', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_tills', 'country_id')) {
                $table->uuid('country_id')->nullable()->index('pos_tills_country_idx');
            }
            if (! Schema::hasColumn('pos_tills', 'brand_id')) {
                $table->uuid('brand_id')->nullable()->index('pos_tills_brand_idx');
            }
            if (! Schema::hasColumn('pos_tills', 'complex_id')) {
                $table->uuid('complex_id')->nullable()->index('pos_tills_complex_idx');
            }
        });

        if (Schema::hasTable('shops')) {
            DB::table('shops')
                ->select(['id', 'country_id', 'brand_id', 'complex_id'])
                ->orderBy('id')
                ->each(function (object $shop): void {
                    DB::table('pos_tills')->where('shop_id', $shop->id)->update([
                        'country_id' => $shop->country_id,
                        'brand_id' => $shop->brand_id,
                        'complex_id' => $shop->complex_id,
                    ]);
                });
        }

        $constraints = [
            ['country_id', 'countries', 'pos_tills_country_fk'],
            ['brand_id', 'brands', 'pos_tills_brand_fk'],
            ['complex_id', 'complexes', 'pos_tills_complex_fk'],
        ];
        foreach ($constraints as [$column, $target, $name]) {
            if (! Schema::hasTable($target) || $this->hasForeignForColumn('pos_tills', $column)) {
                continue;
            }
            Schema::table('pos_tills', fn (Blueprint $table) => $table
                ->foreign($column, $name)
                ->references('id')
                ->on($target)
                ->restrictOnDelete());
        }
    }

    private function dropForeignForColumn(string $table, string $column): void
    {
        $foreign = collect(Schema::getForeignKeys($table))->first(
            static fn (array $definition): bool => ($definition['columns'] ?? []) === [$column]
        );
        if (! $foreign) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($foreign, $column): void {
            DB::getDriverName() === 'sqlite'
                ? $blueprint->dropForeign([$column])
                : $blueprint->dropForeign($foreign['name']);
        });
    }

    private function hasForeignForColumn(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(
            static fn (array $definition): bool => ($definition['columns'] ?? []) === [$column]
        );
    }
};
