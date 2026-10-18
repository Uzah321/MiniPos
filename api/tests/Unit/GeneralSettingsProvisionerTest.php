<?php

namespace Tests\Unit;

use App\Services\Settings\GeneralSettingsProvisioner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GeneralSettingsProvisionerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO driver sqlite is required for general settings provisioning tests.');
        }

        Schema::dropIfExists('general_settings');
        Schema::create('general_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('setting_key');
            $table->text('setting_value')->nullable();
            $table->string('setting_type')->nullable();
            $table->string('module')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('general_settings');
        parent::tearDown();
    }

    public function test_a_new_company_receives_every_setting_with_standard_defaults(): void
    {
        $created = (new GeneralSettingsProvisioner())->ensureCompanyDefaults('company-a');

        $this->assertSame(count(GeneralSettingsProvisioner::DEFAULTS), $created);
        $this->assertSame('true', $this->value('company-a', 'receipt_auto_print'));
        $this->assertSame('receipt', DB::table('general_settings')->where('setting_key', 'receipt_auto_print')->value('module'));
        $this->assertNotEmpty(DB::table('general_settings')->where('setting_key', 'receipt_auto_print')->value('description'));
        $this->assertSame(0, (new GeneralSettingsProvisioner())->ensureCompanyDefaults('company-a'));
    }

    public function test_a_backfill_keeps_current_behaviour_and_never_overwrites_a_value(): void
    {
        DB::table('general_settings')->insert([
            'id' => '00000000-0000-0000-0000-000000000001', 'company_id' => 'company-b',
            'setting_key' => 'touch_keyboard_enabled', 'setting_value' => 'true', 'setting_type' => 'boolean',
        ]);

        (new GeneralSettingsProvisioner())->ensureCompanyDefaults('company-b', keepCurrentBehaviour: true);

        foreach (array_keys(GeneralSettingsProvisioner::MISSING_SETTING_BEHAVIOUR) as $key) {
            $expected = $key === 'touch_keyboard_enabled' ? 'true' : 'false';
            $this->assertSame($expected, $this->value('company-b', $key), $key);
        }
        $this->assertSame(1, DB::table('general_settings')->where('company_id', 'company-b')->where('setting_key', 'touch_keyboard_enabled')->count());
    }

    private function value(string $companyId, string $key): ?string
    {
        return DB::table('general_settings')
            ->where('company_id', $companyId)
            ->whereNull('branch_id')
            ->where('setting_key', $key)
            ->value('setting_value');
    }
}
