<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_taxes')) {
            Schema::create('product_taxes', function (Blueprint $table) {
                $table->char('id', 36)->primary();

                $table->char('company_id', 36)->index();
                $table->char('branch_id', 36)->nullable()->index();

                $table->char('product_id', 36)->index();

                /*
                 * tax_id here references taxes.id, the internal UUID.
                 * FDMS numeric tax id is taxes.tax_id.
                 */
                $table->char('tax_id', 36)->index();

                /*
                 * If true, tax is included in item selling price.
                 * If false, tax is added on top of item selling price.
                 */
                $table->boolean('is_inclusive')->default(false);

                $table->boolean('is_active')->default(true);

                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['product_id', 'tax_id', 'branch_id'], 'product_taxes_product_tax_branch_unique');
            });

            return;
        }

        Schema::table('product_taxes', function (Blueprint $table) {
            if (!Schema::hasColumn('product_taxes', 'company_id')) {
                $table->char('company_id', 36)->nullable()->after('id')->index();
            }

            if (!Schema::hasColumn('product_taxes', 'branch_id')) {
                $table->char('branch_id', 36)->nullable()->after('company_id')->index();
            }

            if (!Schema::hasColumn('product_taxes', 'product_id')) {
                $table->char('product_id', 36)->nullable()->after('branch_id')->index();
            }

            if (!Schema::hasColumn('product_taxes', 'tax_id')) {
                $table->char('tax_id', 36)->nullable()->after('product_id')->index();
            }

            if (!Schema::hasColumn('product_taxes', 'is_inclusive')) {
                $table->boolean('is_inclusive')->default(false)->after('tax_id');
            }

            if (!Schema::hasColumn('product_taxes', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_inclusive');
            }

            if (!Schema::hasColumn('product_taxes', 'starts_at')) {
                $table->timestamp('starts_at')->nullable()->after('is_active');
            }

            if (!Schema::hasColumn('product_taxes', 'ends_at')) {
                $table->timestamp('ends_at')->nullable()->after('starts_at');
            }

            if (!Schema::hasColumn('product_taxes', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        try {
            Schema::table('product_taxes', function (Blueprint $table) {
                $table->unique(['product_id', 'tax_id', 'branch_id'], 'product_taxes_product_tax_branch_unique');
            });
        } catch (\Throwable $e) {
        }

        if (!Schema::hasTable('taxes')) {
            Schema::create('taxes', function (Blueprint $table) {
                $table->char('id', 36)->primary();

                $table->char('company_id', 36)->index();

                $table->string('name')->index();

                /*
                 * FDMS fields:
                 * - tax_code is the receipt tax code, for example A, B, C, VAT.
                 * - tax_id is NOT this table primary key.
                 * - tax_id is the numeric FDMS Tax ID entered when setting up tax.
                 */
                $table->string('tax_code', 3)->nullable();
                $table->integer('tax_id')->nullable()->index();

                $table->enum('type', ['percentage', 'fixed'])->default('percentage');
                $table->decimal('rate', 15, 4)->default(0);

                /*
                 * Only taxes marked obliged_to_zimra are submitted to FDMS.
                 * Other taxes can still calculate locally and appear in reports.
                 */
                $table->boolean('obliged_to_zimra')->default(false);

                $table->boolean('is_active')->default(true);

                $table->date('valid_from')->nullable();
                $table->date('valid_till')->nullable();

                $table->text('description')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'name'], 'taxes_company_name_unique');
                $table->unique(['company_id', 'tax_id'], 'taxes_company_fdms_tax_id_unique');
            });

            return;
        }

        Schema::table('taxes', function (Blueprint $table) {
            if (!Schema::hasColumn('taxes', 'company_id')) {
                $table->char('company_id', 36)->nullable()->after('id')->index();
            }

            if (!Schema::hasColumn('taxes', 'name')) {
                $table->string('name')->after('company_id')->index();
            }

            if (!Schema::hasColumn('taxes', 'tax_code')) {
                $table->string('tax_code', 3)->nullable()->after('name');
            } else {
                try {
                    DB::statement('ALTER TABLE taxes MODIFY tax_code VARCHAR(3) NULL');
                } catch (\Throwable $e) {
                    // Ignore when database driver does not allow MODIFY in this context.
                }
            }

            if (!Schema::hasColumn('taxes', 'tax_id')) {
                $table->integer('tax_id')->nullable()->after('tax_code')->index();
            }

            if (!Schema::hasColumn('taxes', 'type')) {
                $table->enum('type', ['percentage', 'fixed'])->default('percentage')->after('tax_id');
            }

            if (!Schema::hasColumn('taxes', 'rate')) {
                $table->decimal('rate', 15, 4)->default(0)->after('type');
            }

            if (!Schema::hasColumn('taxes', 'obliged_to_zimra')) {
                $table->boolean('obliged_to_zimra')->default(false)->after('rate');
            }

            if (!Schema::hasColumn('taxes', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('obliged_to_zimra');
            }

            if (!Schema::hasColumn('taxes', 'valid_from')) {
                $table->date('valid_from')->nullable()->after('is_active');
            }

            if (!Schema::hasColumn('taxes', 'valid_till')) {
                $table->date('valid_till')->nullable()->after('valid_from');
            }

            if (!Schema::hasColumn('taxes', 'description')) {
                $table->text('description')->nullable()->after('valid_till');
            }

            if (!Schema::hasColumn('taxes', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        try {
            Schema::table('taxes', function (Blueprint $table) {
                $table->unique(['company_id', 'name'], 'taxes_company_name_unique');
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('taxes', function (Blueprint $table) {
                $table->unique(['company_id', 'tax_id'], 'taxes_company_fdms_tax_id_unique');
            });
        } catch (\Throwable $e) {
        }

    }

    public function down(): void
    {
        /*
         * Upgrade-safe rollback.
         * Do not drop product_taxes automatically because the table may already exist in your system.
         */
    }
};
