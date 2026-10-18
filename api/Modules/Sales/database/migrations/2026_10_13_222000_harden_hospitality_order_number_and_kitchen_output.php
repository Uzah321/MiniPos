<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fast_food_order_sequences')) {
            Schema::table('fast_food_order_sequences', function (Blueprint $table): void {
                if (! Schema::hasColumn('fast_food_order_sequences', 'start_number')) {
                    $table->unsignedInteger('start_number')->nullable()->after('prefix');
                }
                if (! Schema::hasColumn('fast_food_order_sequences', 'start_minimum')) {
                    $table->unsignedInteger('start_minimum')->nullable()->after('start_number');
                }
                if (! Schema::hasColumn('fast_food_order_sequences', 'start_maximum')) {
                    $table->unsignedInteger('start_maximum')->nullable()->after('start_minimum');
                }
                if (! Schema::hasColumn('fast_food_order_sequences', 'number_width')) {
                    $table->unsignedTinyInteger('number_width')->default(4)->after('start_maximum');
                }
            });

            // Historical installations can still have a till-level unique
            // key whose nullable till_id permits multiple rows for the same
            // branch/day on MySQL. Merge those rows before enforcing the
            // branch-wide serialization boundary used by every POS till.
            $this->consolidateDailySequences();
            if (! $this->hasUniqueIndex(
                'fast_food_order_sequences',
                ['company_id', 'branch_id', 'business_date']
            )) {
                Schema::table('fast_food_order_sequences', function (Blueprint $table): void {
                    $table->unique(
                        ['company_id', 'branch_id', 'business_date'],
                        'ff_order_seq_branch_day_unique'
                    );
                });
            }
            $this->dropObsoleteTillDayUniqueIndexes();
        }

        if (Schema::hasTable('kitchen_print_jobs')) {
            Schema::table('kitchen_print_jobs', function (Blueprint $table): void {
                if (Schema::hasColumn('kitchen_print_jobs', 'sales_transaction_id')) {
                    $table->uuid('sales_transaction_id')->nullable()->change();
                }
                if (! Schema::hasColumn('kitchen_print_jobs', 'hospitality_table_order_id')) {
                    $table->uuid('hospitality_table_order_id')->nullable()->index()->after('sales_transaction_id');
                }
                if (! Schema::hasColumn('kitchen_print_jobs', 'dispatch_revision')) {
                    $table->unsignedBigInteger('dispatch_revision')->nullable()->after('hospitality_table_order_id');
                }
                if (! Schema::hasColumn('kitchen_print_jobs', 'dispatch_key')) {
                    $table->char('dispatch_key', 64)->nullable()->unique('kitchen_print_dispatch_key_uq')->after('dispatch_revision');
                }
                if (! Schema::hasColumn('kitchen_print_jobs', 'command_id')) {
                    $table->uuid('command_id')->nullable()->after('dispatch_key');
                }
                if (! Schema::hasColumn('kitchen_print_jobs', 'ticket_kind')) {
                    $table->string('ticket_kind', 32)->default('new_order')->after('command_id');
                }
                if (! Schema::hasColumn('kitchen_print_jobs', 'idempotency_key')) {
                    $table->string('idempotency_key', 96)->nullable()->index()->after('target_printing');
                }
                if (! Schema::hasColumn('kitchen_print_jobs', 'attempt_count')) {
                    $table->unsignedInteger('attempt_count')->default(0)->after('status');
                }
                if (! Schema::hasColumn('kitchen_print_jobs', 'last_attempt_at')) {
                    $table->timestamp('last_attempt_at')->nullable()->after('attempt_count');
                }
                if (! Schema::hasColumn('kitchen_print_jobs', 'failed_at')) {
                    $table->timestamp('failed_at')->nullable()->after('printed_at');
                }
                if (! Schema::hasColumn('kitchen_print_jobs', 'error_message')) {
                    $table->text('error_message')->nullable()->after('failed_at');
                }
                if (! Schema::hasColumn('kitchen_print_jobs', 'acknowledged_by')) {
                    $table->uuid('acknowledged_by')->nullable()->after('error_message');
                }
            });

            if (Schema::hasTable('hospitality_table_orders')) {
                Schema::table('kitchen_print_jobs', function (Blueprint $table): void {
                    $table->foreign(
                        'hospitality_table_order_id',
                        'kitchen_jobs_table_order_fk'
                    )->references('id')
                        ->on('hospitality_table_orders')
                        ->nullOnDelete();
                });
            }

            Schema::table('kitchen_print_jobs', function (Blueprint $table): void {
                $table->index(
                    ['hospitality_table_order_id', 'dispatch_revision', 'target_printing'],
                    'kitchen_jobs_table_revision_idx'
                );
                $table->index(
                    ['company_id', 'hospitality_table_order_id', 'command_id'],
                    'kitchen_jobs_table_command_idx'
                );
            });
        }

        if (Schema::hasTable('hospitality_table_orders')) {
            Schema::table('hospitality_table_orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('hospitality_table_orders', 'order_number')) {
                    $table->string('order_number', 50)->nullable()->after('client_request_id');
                }
                if (! Schema::hasColumn('hospitality_table_orders', 'order_sequence')) {
                    $table->unsignedInteger('order_sequence')->nullable()->after('order_number');
                }
                if (! Schema::hasColumn('hospitality_table_orders', 'order_business_date')) {
                    $table->date('order_business_date')->nullable()->after('order_sequence');
                }
                if (! Schema::hasColumn('hospitality_table_orders', 'trading_day_id')) {
                    $table->uuid('trading_day_id')->nullable()->after('order_business_date');
                }
            });

            Schema::table('hospitality_table_orders', function (Blueprint $table): void {
                $table->index(
                    ['company_id', 'branch_id', 'order_business_date', 'order_number'],
                    'hospitality_table_order_number_idx'
                );
            });
        }

        if (! Schema::hasTable('hospitality_output_configurations')) {
            Schema::create('hospitality_output_configurations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('branch_id');
                $table->uuid('till_id')->unique('hospitality_output_till_unique');
                $table->string('mode', 32)->default('kitchen_printer');
                $table->boolean('auto_print')->default(true);
                $table->unsignedTinyInteger('copies')->default(1);
                $table->string('dispatch_on', 32)->default('order_submit');
                $table->unsignedBigInteger('version')->default(1);
                $table->uuid('configured_by')->nullable();
                $table->timestamps();

                $table->index(
                    ['company_id', 'branch_id', 'till_id'],
                    'hospitality_output_scope_idx'
                );

                $table->foreign('company_id')
                    ->references('id')
                    ->on('companies')
                    ->cascadeOnDelete();
                $table->foreign('branch_id')
                    ->references('id')
                    ->on('company_branches')
                    ->cascadeOnDelete();
                $table->foreign('till_id')
                    ->references('id')
                    ->on('pos_tills')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hospitality_output_configurations');

        if (Schema::hasTable('hospitality_table_orders')) {
            Schema::table('hospitality_table_orders', function (Blueprint $table): void {
                try {
                    $table->dropIndex('hospitality_table_order_number_idx');
                } catch (Throwable) {
                    // A partially-applied upgrade may not have created it.
                }

                foreach ([
                    'trading_day_id',
                    'order_business_date',
                    'order_sequence',
                    'order_number',
                ] as $column) {
                    if (Schema::hasColumn('hospitality_table_orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('kitchen_print_jobs')) {
            if (Schema::hasColumn('kitchen_print_jobs', 'hospitality_table_order_id')
                && Schema::hasTable('hospitality_table_orders')) {
                Schema::table('kitchen_print_jobs', function (Blueprint $table): void {
                    $table->dropForeign('kitchen_jobs_table_order_fk');
                });
            }

            Schema::table('kitchen_print_jobs', function (Blueprint $table): void {
                foreach ([
                    'acknowledged_by',
                    'error_message',
                    'failed_at',
                    'last_attempt_at',
                    'attempt_count',
                    'idempotency_key',
                    'ticket_kind',
                    'command_id',
                    'dispatch_key',
                    'dispatch_revision',
                    'hospitality_table_order_id',
                ] as $column) {
                    if (Schema::hasColumn('kitchen_print_jobs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('fast_food_order_sequences')) {
            Schema::table('fast_food_order_sequences', function (Blueprint $table): void {
                foreach ([
                    'number_width',
                    'start_maximum',
                    'start_minimum',
                    'start_number',
                ] as $column) {
                    if (Schema::hasColumn('fast_food_order_sequences', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function consolidateDailySequences(): void
    {
        $duplicates = DB::table('fast_food_order_sequences')
            ->select(['company_id', 'branch_id', 'business_date'])
            ->selectRaw('COUNT(*) AS sequence_count')
            ->groupBy(['company_id', 'branch_id', 'business_date'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::transaction(function () use ($duplicate): void {
                $rows = DB::table('fast_food_order_sequences')
                    ->where('company_id', $duplicate->company_id)
                    ->where('branch_id', $duplicate->branch_id)
                    ->where('business_date', $duplicate->business_date)
                    ->orderByDesc('last_number')
                    ->orderByDesc('updated_at')
                    ->lockForUpdate()
                    ->get();
                if ($rows->count() < 2) {
                    return;
                }

                $winner = $rows->first();
                $startNumber = $rows->pluck('start_number')
                    ->filter(fn ($value): bool => $value !== null)
                    ->map(fn ($value): int => (int) $value)
                    ->min();
                $lastNumber = (int) $rows->max('last_number');
                if ($startNumber !== null) {
                    $lastNumber = max($lastNumber, $startNumber - 1);
                }

                DB::table('fast_food_order_sequences')
                    ->where('id', $winner->id)
                    ->update([
                        'till_id' => null,
                        'trading_day_id' => $rows->pluck('trading_day_id')->first(
                            fn ($value): bool => $value !== null && $value !== ''
                        ),
                        'prefix' => $rows->pluck('prefix')->first(
                            fn ($value): bool => trim((string) $value) !== ''
                        ) ?: 'ORD',
                        'start_number' => $startNumber,
                        'start_minimum' => $rows->pluck('start_minimum')
                            ->filter(fn ($value): bool => $value !== null)
                            ->map(fn ($value): int => (int) $value)
                            ->min(),
                        'start_maximum' => $rows->pluck('start_maximum')
                            ->filter(fn ($value): bool => $value !== null)
                            ->map(fn ($value): int => (int) $value)
                            ->max(),
                        'number_width' => max(3, (int) $rows->max('number_width')),
                        'last_number' => $lastNumber,
                        'updated_at' => now(),
                    ]);

                DB::table('fast_food_order_sequences')
                    ->where('company_id', $duplicate->company_id)
                    ->where('branch_id', $duplicate->branch_id)
                    ->where('business_date', $duplicate->business_date)
                    ->where('id', '<>', $winner->id)
                    ->delete();
            }, 3);
        }
    }

    /** @param array<int, string> $columns */
    private function hasUniqueIndex(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['unique'] ?? false)
                && array_map('strtolower', $index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function dropObsoleteTillDayUniqueIndexes(): void
    {
        $obsoleteColumns = ['company_id', 'branch_id', 'till_id', 'business_date'];

        foreach (Schema::getIndexes('fast_food_order_sequences') as $index) {
            if (! ($index['unique'] ?? false)
                || array_map('strtolower', $index['columns'] ?? []) !== $obsoleteColumns) {
                continue;
            }

            Schema::table('fast_food_order_sequences', function (Blueprint $table) use ($index): void {
                $table->dropUnique($index['name']);
            });
        }
    }
};
