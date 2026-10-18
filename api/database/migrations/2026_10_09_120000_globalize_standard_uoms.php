<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Support\StandardUomCatalogue;

return new class extends Migration
{
    /** @var array<string, string> */
    private const UOM_REFERENCES = [
        'products' => 'base_uom_id',
        'food_recipes' => 'yield_uom_id',
        'food_recipe_ingredients' => 'uom_id',
        'food_manufacturing_batch_lines' => 'uom_id',
        'production_recipe_items' => 'uom_id',
        'production_order_inputs' => 'uom_id',
        'production_order_outputs' => 'uom_id',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('uoms')) {
            return;
        }

        Schema::table('uoms', function (Blueprint $table): void {
            $table->uuid('company_id')->nullable()->change();
        });

        DB::transaction(function (): void {
            foreach (StandardUomCatalogue::definitions() as $definition) {
                $canonicalId = StandardUomCatalogue::idForCode($definition['code']);
                $idCollision = DB::table('uoms')
                    ->where('id', $canonicalId)
                    ->whereRaw('UPPER(code) <> ?', [strtoupper($definition['code'])])
                    ->exists();

                if ($idCollision) {
                    throw new RuntimeException(
                        "The deterministic UOM ID for {$definition['code']} is already used by another unit."
                    );
                }

                $canonicalExists = DB::table('uoms')->where('id', $canonicalId)->exists();
                if (! $canonicalExists) {
                    DB::table('uoms')->insert([
                        'id' => $canonicalId,
                        'company_id' => null,
                        'name' => $definition['name'],
                        'code' => $definition['code'],
                        'quantity_type' => $definition['quantity_type'],
                        'decimal_places' => $definition['decimal_places'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'deleted_at' => null,
                    ]);
                } else {
                    DB::table('uoms')->where('id', $canonicalId)->update([
                        'company_id' => null,
                        'name' => $definition['name'],
                        'code' => $definition['code'],
                        'quantity_type' => $definition['quantity_type'],
                        'decimal_places' => $definition['decimal_places'],
                        'is_active' => true,
                        'updated_at' => now(),
                        'deleted_at' => null,
                    ]);
                }

                $duplicateIds = DB::table('uoms')
                    ->where('id', '<>', $canonicalId)
                    ->whereRaw('UPPER(code) = ?', [strtoupper($definition['code'])])
                    ->pluck('id');

                foreach ($duplicateIds as $duplicateId) {
                    $this->replaceReferences((string) $duplicateId, $canonicalId);
                    DB::table('uoms')->where('id', $duplicateId)->delete();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('uoms')) {
            return;
        }

        $companyId = Schema::hasTable('companies')
            ? DB::table('companies')->orderBy('id')->value('id')
            : null;

        if (! $companyId && DB::table('uoms')->whereNull('company_id')->exists()) {
            throw new RuntimeException('Cannot restore tenant-only UOMs without an existing company.');
        }

        if ($companyId) {
            DB::table('uoms')->whereNull('company_id')->update([
                'company_id' => $companyId,
                'updated_at' => now(),
            ]);
        }

        Schema::table('uoms', function (Blueprint $table): void {
            $table->uuid('company_id')->nullable(false)->change();
        });
    }

    private function replaceReferences(string $oldId, string $canonicalId): void
    {
        foreach (self::UOM_REFERENCES as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)
                ->where($column, $oldId)
                ->update([$column => $canonicalId]);
        }
    }
};
