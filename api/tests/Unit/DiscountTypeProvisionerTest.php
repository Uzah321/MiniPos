<?php

namespace Tests\Unit;

use App\Services\Sales\DiscountTypeProvisioner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Models\DiscountType;
use Tests\TestCase;

class DiscountTypeProvisionerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO driver sqlite is required for discount type provisioning tests.');
        }

        Schema::dropIfExists('discount_types');
        Schema::create('discount_types', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36)->index();
            $table->string('code')->index();
            $table->string('name');
            $table->string('scope')->default('line');
            $table->string('calculation_type')->default('percentage');
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

    protected function tearDown(): void
    {
        Schema::dropIfExists('discount_types');
        parent::tearDown();
    }

    public function test_a_company_without_types_receives_every_system_type_once(): void
    {
        $provisioner = new DiscountTypeProvisioner();

        $this->assertSame(count(DiscountTypeProvisioner::SYSTEM_TYPES), $provisioner->ensureCompanyDefaults('company-a'));
        $this->assertSame(0, $provisioner->ensureCompanyDefaults('company-a'));
        $this->assertSame(
            count(DiscountTypeProvisioner::SYSTEM_TYPES),
            DiscountType::where('company_id', 'company-a')->where('is_system', true)->where('is_active', true)->count()
        );
        $this->assertSame(0, DiscountType::where('company_id', 'company-b')->count());
    }

    public function test_a_type_an_administrator_removed_is_not_recreated(): void
    {
        $provisioner = new DiscountTypeProvisioner();
        $provisioner->ensureCompanyDefaults('company-a');
        DiscountType::where('company_id', 'company-a')->where('code', 'STAFF')->first()->delete();

        $this->assertSame(0, $provisioner->ensureCompanyDefaults('company-a'));
        $this->assertNull(DiscountType::where('company_id', 'company-a')->where('code', 'STAFF')->first());
    }
}
