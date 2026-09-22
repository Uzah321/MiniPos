<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Models\Brand;
use Modules\Authentication\Models\Complex;
use Modules\Authentication\Models\Country;
use Modules\Authentication\Models\PasswordPolicy;
use Modules\Authentication\Models\UserAccessAssignment;
use Modules\Authentication\Services\AccessService;
use Modules\Authentication\Services\CredentialPolicyService;
use Modules\Terminals\Terminal;
use Tests\TestCase;

class PhaseOneAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_organisation_foundation_uses_uuid_hierarchy(): void
    {
        [$country, $brand, $complex, $store, $terminal] = $this->organisation();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $country->id);
        $this->assertSame($country->id, $brand->country_id);
        $this->assertSame($brand->id, $complex->brand_id);
        $this->assertSame($complex->id, $store->complex_id);
        $this->assertSame($store->id, $terminal->store_id);
    }

    public function test_cashier_logs_in_with_code_and_pin_only_at_an_assigned_store(): void
    {
        [, , , $store, $terminal] = $this->organisation();
        $cashier = User::create([
            'name' => 'Cashier One', 'employee_code' => 'CSH-001',
            'pin_hash' => Hash::make('482915'), 'authentication_mode' => 'pos',
            'pin_changed_at' => now(), 'status' => 'active', 'active' => true,
        ]);
        $role = Role::query()->where('name', 'cashier')->firstOrFail();
        UserAccessAssignment::create([
            'user_id' => $cashier->id, 'role_id' => $role->id,
            'scope_type' => 'store', 'store_id' => $store->id, 'status' => 'active',
        ]);

        $this->postJson('/api/v1/auth/pos/login', [
            'employee_code' => 'CSH-001', 'pin' => '482915', 'terminal_id' => $terminal->id,
        ])->assertOk()->assertJsonStructure(['token', 'expires_at', 'session_id', 'user']);

        $this->postJson('/api/v1/auth/back-office/login', [
            'email' => 'cashier@example.com', 'password' => '482915',
        ])->assertUnprocessable();
    }

    public function test_back_office_login_never_accepts_the_pos_pin(): void
    {
        PasswordPolicy::query()->where('scope_type', 'global')->update(['require_mfa_for_back_office' => false]);
        $user = User::create([
            'name' => 'Administrator', 'email' => 'admin@example.com',
            'password' => Hash::make('Correct!Password9'), 'pin_hash' => Hash::make('482915'),
            'password_changed_at' => now(), 'pin_changed_at' => now(),
            'authentication_mode' => 'both', 'status' => 'active', 'active' => true,
        ]);

        $this->postJson('/api/v1/auth/back-office/login', [
            'email' => $user->email, 'password' => '482915',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/back-office/login', [
            'email' => $user->email, 'password' => 'Correct!Password9',
        ])->assertOk()->assertJsonStructure(['token', 'session_id']);
    }

    public function test_country_scoped_permission_applies_to_descendant_store_only(): void
    {
        [$country, , , $store] = $this->organisation();
        [, , , $otherStore] = $this->organisation('ZA', 'SOUTH', 'SOUTH-C', 'SOUTH-S');
        $permission = Permission::query()->where('name', 'stores.view')->firstOrFail();
        $role = Role::create(['name' => 'country-reader', 'guard_name' => 'web', 'status' => 'active']);
        $role->syncPermissions([$permission]);
        $user = User::create([
            'name' => 'Country Manager', 'email' => 'manager@example.com',
            'password' => Hash::make('Correct!Password9'), 'authentication_mode' => 'back_office',
            'status' => 'active', 'active' => true,
        ]);
        UserAccessAssignment::create([
            'user_id' => $user->id, 'role_id' => $role->id,
            'scope_type' => 'country', 'country_id' => $country->id, 'status' => 'active',
        ]);

        $access = app(AccessService::class);
        $this->assertTrue($access->hasPermission($user, 'stores.view', AccessContext::forStore($store)));
        $this->assertFalse($access->hasPermission($user, 'stores.view', AccessContext::forStore($otherStore)));
    }

    public function test_store_policy_overrides_global_policy(): void
    {
        [, , , $store] = $this->organisation();
        PasswordPolicy::create([
            'name' => 'Store strict policy', 'scope_type' => 'store', 'store_id' => $store->id,
            'password_min_length' => 18, 'pin_length' => 8, 'priority' => 10, 'active' => true,
        ]);

        $policy = app(CredentialPolicyService::class)->resolve(AccessContext::forStore($store));
        $this->assertSame(18, $policy->password_min_length);
        $this->assertSame(8, $policy->pin_length);
    }

    private function organisation(
        string $iso = 'ZW', string $brandCode = 'MINI', string $complexCode = 'HRE-C', string $storeCode = 'HRE-01'
    ): array {
        $country = Country::create([
            'iso_code' => $iso, 'name' => $iso.' Country', 'currency_code' => 'USD',
            'timezone' => 'Africa/Harare', 'status' => 'active',
        ]);
        $brand = Brand::create([
            'country_id' => $country->id, 'name' => $brandCode, 'code' => $brandCode, 'status' => 'active',
        ]);
        $complex = Complex::create([
            'brand_id' => $brand->id, 'name' => $complexCode, 'code' => $complexCode, 'status' => 'active',
        ]);
        $store = Store::create([
            'complex_id' => $complex->id, 'name' => $storeCode, 'code' => $storeCode,
            'timezone' => 'Africa/Harare', 'status' => 'active', 'active' => true,
        ]);
        $terminal = Terminal::create([
            'store_id' => $store->id, 'registration_code' => $storeCode.'-POS-1',
            'name' => 'POS 1', 'status' => 'active',
        ]);

        return [$country, $brand, $complex, $store, $terminal];
    }
}
