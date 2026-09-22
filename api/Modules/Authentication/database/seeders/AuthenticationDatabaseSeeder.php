<?php

namespace Modules\Authentication\Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Models\UserAccessAssignment;

class AuthenticationDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL');
        $password = env('SUPER_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('SUPER_ADMIN_EMAIL/PASSWORD are not set; no bootstrap administrator was created.');

            return;
        }

        $user = User::updateOrCreate(
            ['email' => mb_strtolower($email)],
            [
                'name' => env('SUPER_ADMIN_NAME', 'Platform Super Admin'),
                'password' => Hash::make($password),
                'authentication_mode' => 'back_office',
                'status' => 'active',
                'active' => true,
                'password_changed_at' => now(),
                'must_change_password' => true,
            ]
        );
        $role = Role::query()->where('name', 'super-admin')->firstOrFail();
        $user->syncRoles([$role]);
        UserAccessAssignment::firstOrCreate(
            ['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'global'],
            ['status' => 'active']
        );
    }
}
