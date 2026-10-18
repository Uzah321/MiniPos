<?php

use App\Services\Enterprise\BranchMenuCatalogueService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu items created in Back Office were never added to their brand's
 * catalogue, so the till did not show them. Catalogue every existing
 * branch-owned menu item and give it its POS price. Edge installations
 * receive the catalogue from Central through sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('servnest.deployment_mode') === 'edge') {
            return;
        }

        if (! Schema::hasTable('products')
            || ! Schema::hasColumn('products', 'is_global')
            || ! Schema::hasColumn('products', 'branch_id')) {
            return;
        }

        $catalogue = app(BranchMenuCatalogueService::class);

        DB::table('products')
            ->where('is_global', false)
            ->whereNotNull('branch_id')
            ->whereIn('product_type', BranchMenuCatalogueService::MENU_PRODUCT_TYPES)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->each(fn ($productId) => $catalogue->syncProduct((string) $productId));
    }

    public function down(): void
    {
        // Catalogue entries may already have been sold or edited.
    }
};
