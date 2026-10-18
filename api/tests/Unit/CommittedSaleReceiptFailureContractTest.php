<?php

namespace Tests\Unit;

use Modules\Sales\Http\Controllers\SalesController;
use ReflectionMethod;
use Tests\TestCase;

class CommittedSaleReceiptFailureContractTest extends TestCase
{
    public function test_committed_sale_response_does_not_fail_when_pdf_generation_fails(): void
    {
        $responseBuilder = $this->methodSource(
            SalesController::class,
            'buildSaleCreatedResponse'
        );
        $duplicateBuilder = $this->methodSource(
            SalesController::class,
            'buildDuplicateSaleCreatedResponse'
        );
        $receiptService = file_get_contents(
            app_path('Services/Receipt/ReceiptService.php')
        );

        $this->assertIsString($receiptService);
        $this->assertStringContainsString(
            "Receipt PDF generation failed after the sale was persisted.",
            $responseBuilder
        );
        $this->assertStringContainsString("'receipt_url' => null", $responseBuilder);
        $this->assertStringContainsString("'receipt_generated' => false", $responseBuilder);
        $this->assertStringContainsString("'auto_print' => false", $responseBuilder);
        $this->assertStringContainsString(
            '$receipt = $this->prepareReceiptAfterFiscalisation($saleForReceipt);',
            $responseBuilder
        );
        $this->assertStringContainsString('$duplicate ? 200 : 201', $responseBuilder);
        $this->assertStringContainsString(
            '$this->buildSaleCreatedResponse(',
            $duplicateBuilder
        );
        $this->assertStringContainsString('duplicate: true', $duplicateBuilder);

        // Explicit register/reprint operations must still receive a real
        // rendering error; only the post-commit sale response may degrade.
        $this->assertStringNotContainsString(
            "Receipt PDF generation failed after the sale was persisted.",
            $receiptService
        );
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
