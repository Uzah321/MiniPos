<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_users', function (Blueprint $table) {
            $table->boolean('can_access_front_office')->default(false)->after('can_login_pos');
            $table->boolean('can_access_back_office')->default(false)->after('can_access_front_office');
        });

        $normalise = static fn ($value) => str_replace(
            [' ', '-'],
            '_',
            strtolower(trim((string) $value))
        );

        $users = DB::table('pos_users')
            ->leftJoin('roles', 'roles.id', '=', 'pos_users.role_id')
            ->select([
                'pos_users.id',
                'pos_users.user_type',
                'roles.name as role_name',
            ])
            ->get();

        foreach ($users as $user) {
            $identities = array_filter([
                $normalise($user->user_type),
                $normalise($user->role_name),
            ]);

            $isSuperAdmin = count(array_intersect($identities, ['super_admin', 'super_administrator'])) > 0;
            $frontOffice = $isSuperAdmin
                || count(array_intersect($identities, ['cashier', 'supervisor'])) > 0;
            $backOffice = $isSuperAdmin
                || count(array_intersect($identities, ['admin', 'administrator', 'manager'])) > 0;

            DB::table('pos_users')
                ->where('id', $user->id)
                ->update([
                    'can_access_front_office' => $frontOffice,
                    'can_access_back_office' => $backOffice,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('pos_users', function (Blueprint $table) {
            $table->dropColumn([
                'can_access_front_office',
                'can_access_back_office',
            ]);
        });
    }
};
