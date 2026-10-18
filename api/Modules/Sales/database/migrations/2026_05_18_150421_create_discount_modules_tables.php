<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('discount_types')) {
            Schema::create('discount_types', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36)->index();
                $table->string('code')->index();
                $table->string('name');
                $table->enum('scope', ['line', 'cart', 'customer', 'employee', 'coupon', 'promotion', 'manual'])->default('line');
                $table->enum('calculation_type', ['percentage', 'fixed_amount', 'fixed_price', 'buy_x_get_y', 'bundle', 'tiered'])->default('percentage');
                $table->boolean('requires_manager_approval')->default(false);
                $table->boolean('requires_reason')->default(false);
                $table->boolean('allow_stacking')->default(false);
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->json('rules')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'code']);
            });
        }

        // Product's earlier migration already owns `discounts` and
        // `product_discounts` has a foreign key to its existing IDs. Extend
        // that table in place instead of recreating or replacing it. The
        // table guards also let an interrupted MySQL migration resume: DDL
        // commits even when Laravel has not recorded this migration yet.
        if (Schema::hasTable('discounts')) {
            $columns = array_flip(Schema::getColumnListing('discounts'));
            $newColumns = [
                'branch_id', 'discount_type_id', 'code', 'description',
                'discount_basis', 'max_discount_amount', 'min_sale_amount',
                'min_quantity', 'applies_to_all_products',
                'applies_to_all_categories', 'requires_customer',
                'requires_coupon_code', 'coupon_code', 'usage_limit',
                'usage_count', 'per_customer_limit',
                'requires_manager_approval', 'requires_reason',
                'allow_stacking', 'priority', 'rules', 'created_by',
            ];

            if (array_diff($newColumns, array_keys($columns))) {
                Schema::table('discounts', function (Blueprint $table) use ($columns) {
                    if (! isset($columns['branch_id'])) {
                        $table->char('branch_id', 36)->nullable()->index();
                    }
                    if (! isset($columns['discount_type_id'])) {
                        $table->char('discount_type_id', 36)->nullable()->index();
                    }
                    // Existing Product discounts have no code or type. Keep
                    // these nullable at the database level; new Sales
                    // discounts must supply both through validation.
                    if (! isset($columns['code'])) {
                        $table->string('code')->nullable()->index();
                    }
                    if (! isset($columns['description'])) {
                        $table->text('description')->nullable();
                    }
                    if (! isset($columns['discount_basis'])) {
                        $table->enum('discount_basis', ['percentage', 'fixed_amount', 'fixed_price'])->default('percentage');
                    }
                    if (! isset($columns['max_discount_amount'])) {
                        $table->decimal('max_discount_amount', 15, 4)->nullable();
                    }
                    if (! isset($columns['min_sale_amount'])) {
                        $table->decimal('min_sale_amount', 15, 4)->nullable();
                    }
                    if (! isset($columns['min_quantity'])) {
                        $table->decimal('min_quantity', 15, 4)->nullable();
                    }
                    if (! isset($columns['applies_to_all_products'])) {
                        $table->boolean('applies_to_all_products')->default(true);
                    }
                    if (! isset($columns['applies_to_all_categories'])) {
                        $table->boolean('applies_to_all_categories')->default(true);
                    }
                    if (! isset($columns['requires_customer'])) {
                        $table->boolean('requires_customer')->default(false);
                    }
                    if (! isset($columns['requires_coupon_code'])) {
                        $table->boolean('requires_coupon_code')->default(false);
                    }
                    if (! isset($columns['coupon_code'])) {
                        $table->string('coupon_code')->nullable()->index();
                    }
                    if (! isset($columns['usage_limit'])) {
                        $table->integer('usage_limit')->nullable();
                    }
                    if (! isset($columns['usage_count'])) {
                        $table->integer('usage_count')->default(0);
                    }
                    if (! isset($columns['per_customer_limit'])) {
                        $table->integer('per_customer_limit')->nullable();
                    }
                    if (! isset($columns['requires_manager_approval'])) {
                        $table->boolean('requires_manager_approval')->default(false);
                    }
                    if (! isset($columns['requires_reason'])) {
                        $table->boolean('requires_reason')->default(false);
                    }
                    if (! isset($columns['allow_stacking'])) {
                        $table->boolean('allow_stacking')->default(false);
                    }
                    if (! isset($columns['priority'])) {
                        $table->integer('priority')->default(100);
                    }
                    if (! isset($columns['rules'])) {
                        $table->json('rules')->nullable();
                    }
                    if (! isset($columns['created_by'])) {
                        $table->char('created_by', 36)->nullable();
                    }
                });
            }

            // Backfill only legacy rows. An ID-derived code is stable and
            // unique without changing any existing names or relationships.
            DB::table('discounts')->whereNull('code')->orderBy('id')->chunkById(250, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('discounts')->where('id', $row->id)->update([
                        'code' => 'LEGACY-'.$row->id,
                        'discount_basis' => ($row->type ?? null) === 'fixed' ? 'fixed_amount' : 'percentage',
                    ]);
                }
            });

            if (! Schema::hasIndex('discounts', 'discounts_company_id_code_unique')) {
                Schema::table('discounts', function (Blueprint $table) {
                    $table->unique(['company_id', 'code']);
                });
            }
        } else {
            Schema::create('discounts', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36)->index();
                $table->char('branch_id', 36)->nullable()->index();
                $table->char('discount_type_id', 36)->index();
                $table->string('code')->index();
                $table->string('name');
                $table->text('description')->nullable();
                $table->enum('discount_basis', ['percentage', 'fixed_amount', 'fixed_price'])->default('percentage');
                $table->decimal('value', 15, 4)->default(0);
                $table->decimal('max_discount_amount', 15, 4)->nullable();
                $table->decimal('min_sale_amount', 15, 4)->nullable();
                $table->decimal('min_quantity', 15, 4)->nullable();
                $table->boolean('applies_to_all_products')->default(true);
                $table->boolean('applies_to_all_categories')->default(true);
                $table->boolean('requires_customer')->default(false);
                $table->boolean('requires_coupon_code')->default(false);
                $table->string('coupon_code')->nullable()->index();
                $table->integer('usage_limit')->nullable();
                $table->integer('usage_count')->default(0);
                $table->integer('per_customer_limit')->nullable();
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('ends_at')->nullable();
                $table->boolean('requires_manager_approval')->default(false);
                $table->boolean('requires_reason')->default(false);
                $table->boolean('allow_stacking')->default(false);
                $table->integer('priority')->default(100);
                $table->json('rules')->nullable();
                $table->boolean('is_active')->default(true);
                $table->char('created_by', 36)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'code']);
            });
        }

        if (! Schema::hasTable('discount_products')) {
            Schema::create('discount_products', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36)->index();
                $table->char('discount_id', 36)->index();
                $table->char('product_id', 36)->index();
                $table->timestamps();

                $table->unique(['discount_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('discount_categories')) {
            Schema::create('discount_categories', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36)->index();
                $table->char('discount_id', 36)->index();
                $table->char('category_id', 36)->index();
                $table->timestamps();

                $table->unique(['discount_id', 'category_id']);
            });
        }

        if (! Schema::hasTable('discount_role_permissions')) {
            Schema::create('discount_role_permissions', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36)->index();
                $table->char('discount_id', 36)->index();
                $table->char('role_id', 36)->index();
                $table->decimal('max_percentage', 8, 4)->nullable();
                $table->decimal('max_amount', 15, 4)->nullable();
                $table->boolean('can_apply_without_manager')->default(false);
                $table->timestamps();

                $table->unique(['discount_id', 'role_id']);
            });
        }

        if (! Schema::hasTable('sale_discounts')) {
            Schema::create('sale_discounts', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36)->index();
                $table->char('branch_id', 36)->nullable()->index();
                $table->char('sales_transaction_id', 36)->nullable()->index();
                $table->char('receipt_line_id', 36)->nullable()->index();
                $table->char('discount_id', 36)->nullable()->index();
                $table->char('discount_type_id', 36)->nullable()->index();
                $table->string('discount_code')->nullable();
                $table->string('discount_name')->nullable();
                $table->enum('scope', ['line', 'cart'])->default('line');
                $table->enum('discount_basis', ['percentage', 'fixed_amount', 'fixed_price'])->default('percentage');
                $table->decimal('discount_value', 15, 4)->default(0);
                $table->decimal('gross_amount', 15, 4)->default(0);
                $table->decimal('discount_amount', 15, 4)->default(0);
                $table->decimal('net_amount', 15, 4)->default(0);
                $table->string('currency', 10)->default('USD');
                $table->char('cashier_id', 36)->nullable()->index();
                $table->char('approved_by', 36)->nullable()->index();
                $table->char('manager_approval_id', 36)->nullable()->index();
                $table->text('reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_discounts');
        Schema::dropIfExists('discount_role_permissions');
        Schema::dropIfExists('discount_categories');
        Schema::dropIfExists('discount_products');
        // `discounts` is owned by the earlier Product migration. Never drop
        // it here: it may contain products' active discount assignments.
        Schema::dropIfExists('discount_types');
    }
};
