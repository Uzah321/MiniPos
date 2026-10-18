<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_user_shift_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->uuid('pos_user_id')->index();
            $table->uuid('shift_configuration_id')->index();
            $table->string('shift_name', 80);
            $table->timestamps();

            $table->unique(
                ['pos_user_id', 'shift_configuration_id', 'shift_name'],
                'pos_user_shift_assignment_unique'
            );
        });

        $users = DB::table('pos_users')
            ->where('can_access_front_office', true)
            ->get(['id', 'company_id', 'branch_id']);

        foreach ($users as $user) {
            $branchIds = DB::table('user_branches')
                ->where('pos_user_id', $user->id)
                ->pluck('branch_id')
                ->push($user->branch_id)
                ->filter()
                ->unique()
                ->values();

            $configs = DB::table('shiftconfigurations')
                ->where('company_id', $user->company_id)
                ->where('enable_shifts', true)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->where(function ($query) use ($branchIds) {
                    $query->whereNull('branch_id');
                    if ($branchIds->isNotEmpty()) {
                        $query->orWhereIn('branch_id', $branchIds);
                    }
                })
                ->get();

            foreach ($configs as $config) {
                $shiftNames = [];
                if ($config->shift_mode === 'manual') {
                    $shiftNames[] = 'manual';
                } else {
                    foreach (['morning', 'afternoon', 'night'] as $name) {
                        if ($config->{$name . '_shift_start'} && $config->{$name . '_shift_end'}) {
                            $shiftNames[] = $name;
                        }
                    }
                }

                if ($config->allow_unscheduled_shifts) {
                    $shiftNames[] = 'unscheduled';
                }

                foreach (array_unique($shiftNames) as $shiftName) {
                    DB::table('pos_user_shift_assignments')->insert([
                        'id' => (string) Str::uuid(),
                        'company_id' => $user->company_id,
                        'branch_id' => $config->branch_id,
                        'pos_user_id' => $user->id,
                        'shift_configuration_id' => $config->id,
                        'shift_name' => $shiftName,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_user_shift_assignments');
    }
};
