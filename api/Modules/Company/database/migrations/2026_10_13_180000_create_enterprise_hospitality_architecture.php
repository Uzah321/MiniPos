<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->normaliseHospitalityProductType();
        $this->createHierarchy();
        $this->extendExistingTenantTables();
        $this->createHospitalityCatalogue();
        $this->extendRecipes();
        $this->extendLegacyHospitalityOptionScopes();
        $this->backfillLegacyHierarchy();
        $this->backfillLegacyHospitalityOptionScopes();
        $this->addIntegrityConstraints();
    }

    /**
     * The original installation used a four-value MySQL ENUM even though the
     * Hospitality controllers and GAAP catalogue use additional item kinds.
     * A varchar is intentionally used instead of another enum so future brand
     * catalogues do not require table-rebuilding DDL for every new kind.
     */
    private function normaliseHospitalityProductType(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'product_type')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `products` MODIFY `product_type` VARCHAR(40) NOT NULL DEFAULT 'simple'");

            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('product_type', 40)->default('simple')->change();
        });
    }

    public function down(): void
    {
        foreach ([
            'hospitality_external_keys',
            'hospitality_catalog_imports',
            'shop_menu_item_overrides',
            'menu_item_modifier_exclusions',
            'menu_item_modifier_groups',
            'modifier_group_options',
            'modifier_groups',
            'menu_item_trading_schedules',
            'menu_item_channels',
            'product_uom_conversions',
            'brand_products',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->dropIndexIfPresent('food_recipe_ingredients', 'food_recipe_lines_child_idx');
        $this->dropIndexIfPresent('food_recipe_ingredients', 'food_recipe_lines_source_idx');
        $this->dropColumnIfPresent('food_recipe_ingredients', 'line_total');
        $this->dropColumnIfPresent('food_recipe_ingredients', 'unit_cost');
        $this->dropColumnIfPresent('food_recipe_ingredients', 'sequence_no');
        $this->dropColumnIfPresent('food_recipe_ingredients', 'source_line_key');
        $this->dropColumnIfPresent('food_recipe_ingredients', 'child_recipe_id');

        $this->dropIndexIfPresent('food_recipes', 'food_recipes_brand_idx');
        $this->dropIndexIfPresent('food_recipes', 'food_recipes_source_key_idx');
        $this->dropIndexIfPresent('food_recipes', 'food_recipes_current_idx');
        $this->dropColumnIfPresent('food_recipes', 'is_current');
        $this->dropColumnIfPresent('food_recipes', 'effective_to');
        $this->dropColumnIfPresent('food_recipes', 'effective_from');
        $this->dropColumnIfPresent('food_recipes', 'source_key');
        $this->dropColumnIfPresent('food_recipes', 'external_code');
        $this->dropColumnIfPresent('food_recipes', 'brand_id');

        foreach ([
            'product_prep_message_assignments',
            'product_prep_messages',
            'product_modifier_assignments',
            'product_modifiers',
        ] as $table) {
            $this->dropForeignIfPresent($table, $table.'_brand_fk', 'brand_id');
            $this->dropIndexIfPresent($table, $table.'_brand_idx');
            $this->dropColumnIfPresent($table, 'brand_id');
        }

        foreach (['sync_local_states', 'sync_nodes'] as $table) {
            $this->dropForeignIfPresent($table, $table.'_shop_fk', 'shop_id');
            $this->dropIndexIfPresent($table, $table.'_shop_idx');
            $this->dropColumnIfPresent($table, 'shop_id');
        }
        $this->dropColumnIfPresent('pos_tills', 'configuration_version');
        $this->dropForeignIfPresent('pos_tills', 'pos_tills_shop_fk', 'shop_id');
        $this->dropIndexIfPresent('pos_tills', 'pos_tills_shop_idx');
        $this->dropColumnIfPresent('pos_tills', 'shop_id');

        Schema::dropIfExists('shops');
        Schema::dropIfExists('complexes');
        Schema::dropIfExists('brands');

        $this->dropForeignIfPresent('companies', 'companies_country_fk', 'country_id');
        $this->dropIndexIfPresent('companies', 'companies_country_idx');
        $this->dropColumnIfPresent('companies', 'country_id');
        Schema::dropIfExists('countries');
    }

    private function createHierarchy(): void
    {
        if (! Schema::hasTable('countries')) {
            Schema::create('countries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 16)->unique();
                $table->char('iso_alpha_2', 2)->nullable()->unique();
                $table->char('iso_alpha_3', 3)->nullable()->unique();
                $table->string('name');
                $table->char('currency_code', 3)->nullable();
                $table->string('timezone', 80)->nullable();
                $table->string('locale', 20)->nullable();
                $table->string('image_path')->nullable();
                $table->string('image_url', 2048)->nullable();
                $table->json('metadata')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['is_active', 'name'], 'countries_active_name_idx');
            });
        }

        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'country_id')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->uuid('country_id')->nullable()->index('companies_country_idx');
            });
        }

        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('country_id');
                $table->string('code', 80);
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('logo_path')->nullable();
                $table->string('logo_url', 2048)->nullable();
                $table->string('status', 32)->default('active');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'country_id', 'code'], 'brands_company_country_code_uq');
                $table->index(['company_id', 'status'], 'brands_company_status_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('country_id')->references('id')->on('countries')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('complexes')) {
            Schema::create('complexes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('country_id');
                $table->uuid('brand_id');
                $table->string('code', 80);
                $table->string('name');
                $table->string('address')->nullable();
                $table->string('city')->nullable();
                $table->string('timezone', 80)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('status', 32)->default('active');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['brand_id', 'code'], 'complexes_brand_code_uq');
                $table->index(['company_id', 'country_id', 'status'], 'complexes_tenant_status_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('country_id')->references('id')->on('countries')->restrictOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('shops')) {
            Schema::create('shops', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('country_id');
                $table->uuid('brand_id');
                $table->uuid('complex_id');
                // company_branches remains the compatibility and financial
                // scope while the application migrates to explicit shops.
                $table->uuid('branch_id')->unique('shops_branch_uq');
                $table->string('code', 100);
                $table->string('name');
                $table->string('address')->nullable();
                $table->string('city')->nullable();
                $table->string('timezone', 80)->nullable();
                $table->string('pos_login_mode', 32)->default('code');
                $table->unsignedBigInteger('configuration_version')->default(1);
                $table->string('status', 32)->default('active');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'code'], 'shops_company_code_uq');
                $table->index(['company_id', 'brand_id', 'status'], 'shops_company_brand_status_idx');
                $table->index(['complex_id', 'status'], 'shops_complex_status_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('country_id')->references('id')->on('countries')->restrictOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
                $table->foreign('complex_id')->references('id')->on('complexes')->cascadeOnDelete();
                $table->foreign('branch_id')->references('id')->on('company_branches')->cascadeOnDelete();
            });
        }
    }

    private function extendExistingTenantTables(): void
    {
        if (Schema::hasTable('pos_tills')) {
            Schema::table('pos_tills', function (Blueprint $table): void {
                if (! Schema::hasColumn('pos_tills', 'shop_id')) {
                    $table->uuid('shop_id')->nullable()->index('pos_tills_shop_idx');
                }
                if (! Schema::hasColumn('pos_tills', 'configuration_version')) {
                    $table->unsignedBigInteger('configuration_version')->default(1);
                }
            });
        }

        foreach (['sync_nodes', 'sync_local_states'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'shop_id')) {
                Schema::table($name, function (Blueprint $table) use ($name): void {
                    $table->uuid('shop_id')->nullable()->index($name.'_shop_idx');
                });
            }
        }
    }

    private function createHospitalityCatalogue(): void
    {
        if (! Schema::hasTable('brand_products')) {
            Schema::create('brand_products', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('brand_id');
                $table->uuid('product_id');
                $table->string('item_kind', 32); // raw_material, menu_item, production_item, packaging, modifier
                $table->string('external_code', 120)->nullable();
                $table->string('legacy_code', 120)->nullable();
                $table->string('market', 80)->nullable();
                $table->string('department', 120)->nullable();
                $table->string('flavour', 120)->nullable();
                $table->string('size_label', 120)->nullable();
                $table->string('stock_group', 120)->nullable();
                $table->json('source_metadata')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['brand_id', 'product_id'], 'brand_products_brand_product_uq');
                $table->unique(['brand_id', 'external_code'], 'brand_products_brand_external_uq');
                $table->index(['company_id', 'brand_id', 'item_kind', 'is_active'], 'brand_products_catalog_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('product_uom_conversions')) {
            Schema::create('product_uom_conversions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('product_id');
                $table->uuid('from_uom_id');
                $table->uuid('to_uom_id');
                $table->decimal('conversion_factor', 20, 8);
                $table->string('source_key', 190)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['product_id', 'from_uom_id', 'to_uom_id'], 'product_uom_conversion_uq');
                $table->index(['company_id', 'product_id'], 'product_uom_company_product_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('from_uom_id')->references('id')->on('uoms')->restrictOnDelete();
                $table->foreign('to_uom_id')->references('id')->on('uoms')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('menu_item_channels')) {
            Schema::create('menu_item_channels', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('brand_id');
                $table->uuid('product_id');
                $table->string('channel_code', 80);
                $table->decimal('price_exclusive', 18, 4)->nullable();
                $table->decimal('price_inclusive', 18, 4)->nullable();
                $table->char('currency_code', 3);
                $table->boolean('is_available')->default(true);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['brand_id', 'product_id', 'channel_code'], 'menu_channel_item_uq');
                $table->index(['company_id', 'brand_id', 'channel_code', 'is_available'], 'menu_channel_scope_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('menu_item_trading_schedules')) {
            Schema::create('menu_item_trading_schedules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('brand_id');
                $table->uuid('product_id');
                $table->string('schedule_code', 80)->default('DEFAULT');
                $table->json('weekdays')->nullable();
                $table->time('starts_at')->nullable();
                $table->time('ends_at')->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('source_metadata')->nullable();
                $table->timestamps();

                $table->unique(['brand_id', 'product_id', 'schedule_code'], 'menu_schedule_item_uq');
                $table->index(['company_id', 'brand_id', 'is_active'], 'menu_schedule_scope_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('modifier_groups')) {
            Schema::create('modifier_groups', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('brand_id');
                $table->string('code', 120);
                $table->string('name');
                $table->unsignedInteger('minimum_selections')->default(0);
                $table->unsignedInteger('maximum_selections')->nullable();
                $table->boolean('is_required')->default(false);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['brand_id', 'code'], 'modifier_groups_brand_code_uq');
                $table->index(['company_id', 'brand_id', 'is_active'], 'modifier_groups_scope_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('modifier_group_options')) {
            Schema::create('modifier_group_options', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('brand_id');
                $table->uuid('modifier_group_id');
                $table->uuid('modifier_product_id');
                $table->string('external_code', 120)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->decimal('price_delta', 18, 4)->default(0);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['modifier_group_id', 'modifier_product_id'], 'modifier_group_option_uq');
                $table->index(['company_id', 'brand_id', 'is_active'], 'modifier_options_scope_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
                $table->foreign('modifier_group_id')->references('id')->on('modifier_groups')->cascadeOnDelete();
                $table->foreign('modifier_product_id')->references('id')->on('products')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('menu_item_modifier_groups')) {
            Schema::create('menu_item_modifier_groups', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('brand_id');
                $table->uuid('menu_product_id');
                $table->uuid('modifier_group_id');
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedInteger('minimum_selections')->nullable();
                $table->unsignedInteger('maximum_selections')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['menu_product_id', 'modifier_group_id'], 'menu_item_modifier_group_uq');
                $table->index(['company_id', 'brand_id', 'is_active'], 'menu_modifier_groups_scope_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
                $table->foreign('menu_product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('modifier_group_id')->references('id')->on('modifier_groups')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('menu_item_modifier_exclusions')) {
            Schema::create('menu_item_modifier_exclusions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('brand_id');
                $table->uuid('menu_product_id');
                $table->uuid('modifier_option_id');
                $table->string('reason')->nullable();
                $table->timestamps();

                $table->unique(['menu_product_id', 'modifier_option_id'], 'menu_modifier_exclusion_uq');
                $table->index(['company_id', 'brand_id'], 'menu_modifier_exclusions_scope_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
                $table->foreign('menu_product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('modifier_option_id')->references('id')->on('modifier_group_options')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('shop_menu_item_overrides')) {
            Schema::create('shop_menu_item_overrides', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('brand_id');
                $table->uuid('shop_id');
                $table->uuid('product_id');
                $table->boolean('is_available')->default(true);
                $table->string('channel_code', 80)->nullable();
                // MySQL permits multiple NULL values in a unique key. Keep a
                // non-null canonical key so the all-channel override remains
                // idempotent under concurrent imports and API requests.
                $table->string('channel_key', 80)->default('*');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->string('reason')->nullable();
                $table->unsignedBigInteger('configuration_version')->default(1);
                $table->timestamps();

                $table->unique(['shop_id', 'product_id', 'channel_key'], 'shop_menu_override_uq');
                $table->index(['company_id', 'shop_id', 'is_available'], 'shop_menu_visibility_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
                $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('hospitality_catalog_imports')) {
            Schema::create('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('brand_id');
                $table->string('source_system', 80)->default('gaap');
                $table->string('source_name');
                $table->char('source_sha256', 64);
                $table->string('source_version', 80)->nullable();
                $table->string('status', 32)->default('pending');
                $table->boolean('dry_run')->default(false);
                $table->json('sheet_manifest')->nullable();
                $table->json('statistics')->nullable();
                $table->json('errors')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'brand_id', 'source_sha256', 'dry_run'], 'catalog_import_source_uq');
                $table->index(['company_id', 'brand_id', 'status'], 'catalog_import_status_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('hospitality_external_keys')) {
            Schema::create('hospitality_external_keys', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('brand_id');
                $table->string('source_system', 80);
                $table->string('entity_type', 80);
                $table->uuid('entity_id');
                $table->string('external_key', 190);
                $table->string('legacy_code', 190)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(
                    ['company_id', 'brand_id', 'source_system', 'entity_type', 'external_key'],
                    'hospitality_external_key_uq'
                );
                $table->index(['entity_type', 'entity_id'], 'hospitality_external_entity_idx');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
            });
        }
    }

    private function extendRecipes(): void
    {
        if (Schema::hasTable('food_recipes')) {
            Schema::table('food_recipes', function (Blueprint $table): void {
                if (! Schema::hasColumn('food_recipes', 'brand_id')) {
                    $table->uuid('brand_id')->nullable()->index('food_recipes_brand_idx');
                }
                if (! Schema::hasColumn('food_recipes', 'external_code')) {
                    $table->string('external_code', 120)->nullable();
                }
                if (! Schema::hasColumn('food_recipes', 'source_key')) {
                    $table->string('source_key', 190)->nullable()->index('food_recipes_source_key_idx');
                }
                if (! Schema::hasColumn('food_recipes', 'effective_from')) {
                    $table->date('effective_from')->nullable();
                }
                if (! Schema::hasColumn('food_recipes', 'effective_to')) {
                    $table->date('effective_to')->nullable();
                }
                if (! Schema::hasColumn('food_recipes', 'is_current')) {
                    $table->boolean('is_current')->default(true)->index('food_recipes_current_idx');
                }
            });
        }

        if (Schema::hasTable('food_recipe_ingredients')) {
            Schema::table('food_recipe_ingredients', function (Blueprint $table): void {
                if (! Schema::hasColumn('food_recipe_ingredients', 'child_recipe_id')) {
                    $table->uuid('child_recipe_id')->nullable()->index('food_recipe_lines_child_idx');
                }
                if (! Schema::hasColumn('food_recipe_ingredients', 'source_line_key')) {
                    $table->string('source_line_key', 190)->nullable()->index('food_recipe_lines_source_idx');
                }
                if (! Schema::hasColumn('food_recipe_ingredients', 'sequence_no')) {
                    $table->unsignedInteger('sequence_no')->default(0);
                }
                if (! Schema::hasColumn('food_recipe_ingredients', 'unit_cost')) {
                    $table->decimal('unit_cost', 18, 6)->nullable();
                }
                if (! Schema::hasColumn('food_recipe_ingredients', 'line_total')) {
                    $table->decimal('line_total', 18, 6)->nullable();
                }
            });
        }
    }

    /**
     * The earlier Hospitality option tables were scoped only to company and
     * branch. A company can operate several brands in the same country, so a
     * nullable brand discriminator is added without invalidating legacy rows.
     * New writes/imports always populate it; unresolved legacy globals remain
     * usable only through a brand-owned product assignment.
     */
    private function extendLegacyHospitalityOptionScopes(): void
    {
        foreach ([
            'product_prep_messages',
            'product_prep_message_assignments',
            'product_modifiers',
            'product_modifier_assignments',
        ] as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'brand_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->uuid('brand_id')->nullable()->index($name.'_brand_idx');
            });
        }
    }

    private function backfillLegacyHierarchy(): void
    {
        if (! Schema::hasTable('companies')
            || ! Schema::hasTable('company_branches')
            || ! Schema::hasTable('countries')
            || ! Schema::hasTable('brands')
            || ! Schema::hasTable('complexes')
            || ! Schema::hasTable('shops')) {
            return;
        }

        $companyColumns = ['id'];
        foreach (['company_name', 'country', 'city'] as $column) {
            if (Schema::hasColumn('companies', $column)) {
                $companyColumns[] = $column;
            }
        }

        DB::table('companies')->orderBy('id')->get($companyColumns)->each(function (object $company): void {
            $countryName = trim((string) ($company->country ?? '')) ?: 'Unassigned';
            $countryId = $this->deterministicUuid('country:'.mb_strtolower($countryName));
            $countryCode = $this->uniqueCountryCode($countryName, $countryId);
            DB::table('countries')->updateOrInsert(
                ['id' => $countryId],
                [
                    'code' => $countryCode,
                    'name' => $countryName,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            if (Schema::hasColumn('companies', 'country_id')) {
                DB::table('companies')->where('id', $company->id)->update(['country_id' => $countryId]);
            }

            $brandId = $this->deterministicUuid('brand:'.$company->id.':default');
            DB::table('brands')->updateOrInsert(
                ['id' => $brandId],
                [
                    'company_id' => $company->id,
                    'country_id' => $countryId,
                    'code' => 'DEFAULT',
                    'name' => trim((string) ($company->company_name ?? '')) ?: 'Default Brand',
                    'status' => 'active',
                    'metadata' => json_encode(['legacy_backfill' => true], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $branchColumns = ['id', 'company_id'];
            foreach (['branch_name', 'branch_code', 'branch_address', 'branch_city', 'status'] as $column) {
                if (Schema::hasColumn('company_branches', $column)) {
                    $branchColumns[] = $column;
                }
            }
            DB::table('company_branches')
                ->where('company_id', $company->id)
                ->orderBy('id')
                ->get($branchColumns)
                ->each(function (object $branch) use ($company, $countryId, $brandId): void {
                    $complexId = $this->deterministicUuid('complex:'.$branch->id);
                    $code = trim((string) ($branch->branch_code ?? '')) ?: 'SHOP-'.substr(str_replace('-', '', (string) $branch->id), 0, 12);
                    $name = trim((string) ($branch->branch_name ?? '')) ?: $code;

                    DB::table('complexes')->updateOrInsert(
                        ['id' => $complexId],
                        [
                            'company_id' => $company->id,
                            'country_id' => $countryId,
                            'brand_id' => $brandId,
                            'code' => $code,
                            'name' => $name,
                            'address' => $branch->branch_address ?? null,
                            'city' => $branch->branch_city ?? ($company->city ?? null),
                            'status' => $branch->status ?? 'active',
                            'metadata' => json_encode(['legacy_backfill' => true], JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                    DB::table('shops')->updateOrInsert(
                        ['id' => $branch->id],
                        [
                            'company_id' => $company->id,
                            'country_id' => $countryId,
                            'brand_id' => $brandId,
                            'complex_id' => $complexId,
                            'branch_id' => $branch->id,
                            'code' => $code,
                            'name' => $name,
                            'address' => $branch->branch_address ?? null,
                            'city' => $branch->branch_city ?? ($company->city ?? null),
                            'pos_login_mode' => 'code',
                            'configuration_version' => 1,
                            'status' => $branch->status ?? 'active',
                            'metadata' => json_encode(['legacy_backfill' => true], JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                });
        });

        foreach (['pos_tills', 'sync_nodes', 'sync_local_states'] as $table) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, 'shop_id')
                && Schema::hasColumn($table, 'branch_id')) {
                DB::table($table)
                    ->whereNull('shop_id')
                    ->whereIn('branch_id', DB::table('shops')->select('branch_id'))
                    ->update(['shop_id' => DB::raw('branch_id')]);
            }
        }
    }

    private function backfillLegacyHospitalityOptionScopes(): void
    {
        if (! Schema::hasTable('shops')) {
            return;
        }

        foreach ([
            'product_prep_messages',
            'product_prep_message_assignments',
            'product_modifiers',
            'product_modifier_assignments',
        ] as $table) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, 'brand_id')
                || ! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            DB::table($table)
                ->whereNull('brand_id')
                ->whereNotNull('branch_id')
                ->orderBy('id')
                ->each(function (object $row) use ($table): void {
                    $brandId = DB::table('shops')
                        ->where('company_id', $row->company_id)
                        ->where('branch_id', $row->branch_id)
                        ->value('brand_id');
                    if ($brandId) {
                        DB::table($table)->where('id', $row->id)->update(['brand_id' => $brandId]);
                    }
                });
        }
    }

    private function addIntegrityConstraints(): void
    {
        $constraints = [
            ['companies', 'country_id', 'countries', 'companies_country_fk'],
            ['pos_tills', 'shop_id', 'shops', 'pos_tills_shop_fk'],
            ['sync_nodes', 'shop_id', 'shops', 'sync_nodes_shop_fk'],
            ['sync_local_states', 'shop_id', 'shops', 'sync_local_states_shop_fk'],
            ['product_prep_messages', 'brand_id', 'brands', 'product_prep_messages_brand_fk'],
            ['product_prep_message_assignments', 'brand_id', 'brands', 'product_prep_message_assignments_brand_fk'],
            ['product_modifiers', 'brand_id', 'brands', 'product_modifiers_brand_fk'],
            ['product_modifier_assignments', 'brand_id', 'brands', 'product_modifier_assignments_brand_fk'],
        ];

        foreach ($constraints as [$table, $column, $target, $name]) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, $column)
                || $this->hasForeignKey($table, $name, $column)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($column, $target, $name): void {
                $blueprint->foreign($column, $name)
                    ->references('id')
                    ->on($target)
                    ->restrictOnDelete();
            });
        }
    }

    private function uniqueCountryCode(string $name, string $countryId): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?: 'COUNTRY');
        $base = substr($base, 0, 16);
        $candidate = $base;
        $suffix = 0;
        while (DB::table('countries')->where('code', $candidate)->where('id', '!=', $countryId)->exists()) {
            $suffix++;
            $tail = (string) $suffix;
            $candidate = substr($base, 0, 16 - strlen($tail)).$tail;
        }

        return $candidate;
    }

    private function deterministicUuid(string $seed): string
    {
        $hex = substr(hash('sha256', 'servnest:'.$seed), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function dropColumnIfPresent(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
    }

    private function dropIndexIfPresent(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $index)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
    }

    private function dropForeignIfPresent(string $table, string $foreign, string $column): void
    {
        if (! Schema::hasTable($table) || ! $this->hasForeignKey($table, $foreign, $column)) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($foreign, $column): void {
            // SQLite does not expose foreign-key constraint names. Laravel's
            // SQLite blueprint therefore resolves a drop by its constrained
            // columns, while MySQL/PostgreSQL use the explicit stable name.
            DB::getDriverName() === 'sqlite'
                ? $blueprint->dropForeign([$column])
                : $blueprint->dropForeign($foreign);
        });
    }

    private function hasForeignKey(string $table, string $foreign, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(
            static fn (array $definition): bool => ($definition['name'] ?? null) === $foreign
                || ($definition['columns'] ?? []) === [$column]
        );
    }
};
