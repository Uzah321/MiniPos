<?php

namespace Tests\Unit;

use Modules\Authentication\Http\Controllers\PosUserController;
use ReflectionMethod;
use Tests\TestCase;

class PosLoginOpeningFloatContractTest extends TestCase
{
    public function test_login_exposes_a_stable_opening_float_challenge_and_forces_code_login_to_zero(): void
    {
        $method = new ReflectionMethod(PosUserController::class, 'login');
        $lines = file($method->getFileName());
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString(
            '$openingFloat = $codeLoginValid',
            $source
        );
        $this->assertMatchesRegularExpression(
            '/\$openingFloat\s*=\s*\$codeLoginValid\s*\?\s*0\s*:/',
            $source
        );
        $this->assertStringContainsString(
            "'code' => 'OPENING_FLOAT_REQUIRED'",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/'code'\s*=>\s*'OPENING_FLOAT_REQUIRED'.*?\],\s*422\);/s",
            $source
        );
    }
}
