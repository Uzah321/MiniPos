<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Modules\Sales\Http\Controllers\CashupController;
use ReflectionClass;
use Tests\TestCase;

class CashupHospitalityOnlyContractTest extends TestCase
{
    public function test_cashup_write_rejects_every_client_selected_business_mode(): void
    {
        $rules = (new ReflectionClass(CashupController::class))
            ->getConstant('CASHUP_BUSINESS_MODE_VALIDATION_RULES');

        $this->assertSame(['prohibited'], $rules);
        $this->assertFalse(Validator::make([], ['business_mode' => $rules])->fails());

        foreach (['retail', 'fast_food_boh'] as $clientValue) {
            $validator = Validator::make(
                ['business_mode' => $clientValue],
                ['business_mode' => $rules]
            );

            $this->assertTrue($validator->fails());
            $this->assertArrayHasKey('business_mode', $validator->errors()->toArray());
        }
    }

    public function test_cashup_write_resolves_mode_from_the_company_and_branch_only(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(CashupController::class))->getFileName()
        );

        $this->assertStringContainsString(
            '$this->resolveCashupBusinessMode($company->id, $till->branch_id);',
            $source
        );
        $this->assertStringNotContainsString(
            '$this->resolveCashupBusinessMode($company->id, $till->branch_id, $request->input(\'business_mode\'))',
            $source
        );
    }
}
