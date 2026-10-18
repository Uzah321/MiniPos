<?php

namespace Tests\Unit;

use App\Services\Sync\SyncConfigurationService;
use Illuminate\Http\Request;
use Mockery;
use Modules\Product\Http\Controllers\ProductImportController;
use Modules\Product\Models\Product;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ReflectionMethod;
use Tests\TestCase;

class ProductImportTemplateAndValidationTest extends TestCase
{
    public function test_excel_template_is_a_real_workbook_with_an_empty_import_sheet(): void
    {
        $response = (new ProductImportController)->downloadTemplate(
            Request::create('/api/pos/products/import/template?format=xlsx', 'GET')
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->responseBody($response);
        $this->assertSame('PK', substr($body, 0, 2));

        $path = tempnam(sys_get_temp_dir(), 'salexa-import-');
        try {
            file_put_contents($path, $body);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Products Import');
            $this->assertNotNull($sheet);
            $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1')[0];
            $this->assertContains('name', $headers);
            $this->assertContains('category_name', $headers);
            $this->assertContains('opening_quantity', $headers);
            $this->assertContains('stock_usage_type', $headers);
            $this->assertContains('hs_code', $headers);
            $hsColumn = Coordinate::stringFromColumnIndex(array_search('hs_code', $headers, true) + 1);
            $this->assertSame('@', $sheet->getStyle($hsColumn.'2')->getNumberFormat()->getFormatCode());
            $this->assertNull($sheet->getCell('C2')->getValue());
            $this->assertNotNull($book->getSheetByName('Instructions'));
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_csv_template_contains_only_headers(): void
    {
        $response = (new ProductImportController)->downloadTemplate(
            Request::create('/api/pos/products/import/template?format=csv', 'GET')
        );

        $lines = preg_split('/\r\n|\n|\r/', trim($this->responseBody($response)));
        $this->assertCount(1, $lines);
        $headers = str_getcsv($lines[0]);
        $this->assertContains('name', $headers);
        $this->assertContains('category_name', $headers);
        $this->assertContains('hs_code', $headers);
    }

    public function test_invalid_amounts_and_hospitality_types_are_not_silently_coerced(): void
    {
        $controller = new ProductImportController;
        $this->assertSame('raw_material', $this->callPrivate($controller, 'normaliseProductType', ['raw_material']));
        $this->assertSame('simple', $this->callPrivate($controller, 'normaliseProductType', ['standard']));
        $this->assertSame('non_turnover', $this->callPrivate($controller, 'stockUsageType', [['product_type' => 'consumable']]));

        $this->expectException(\InvalidArgumentException::class);
        $this->callPrivate($controller, 'toNumber', ['two cases']);
    }

    public function test_hs_code_validation_preserves_leading_zeroes_and_rejects_placeholders(): void
    {
        $controller = new ProductImportController;
        $this->assertNull($this->callPrivate($controller, 'normaliseHsCode', [null]));
        $this->assertNull($this->callPrivate($controller, 'normaliseHsCode', ['']));
        $this->assertSame('0000', $this->callPrivate($controller, 'normaliseHsCode', ['0000']));
        $this->assertSame('01234567', $this->callPrivate($controller, 'normaliseHsCode', [' 01234567 ']));

        foreach (['0', '123', '12345', '123456789', '1234.0', '00001234.0', '12 34', '1.234E+7'] as $invalid) {
            try {
                $this->callPrivate($controller, 'normaliseHsCode', [$invalid]);
                $this->fail("Invalid HS code {$invalid} must be rejected.");
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('4 or 8 digits', $exception->getMessage());
            }
        }
    }

    public function test_successful_raw_product_import_signals_company_snapshot_once(): void
    {
        $sync = Mockery::mock(SyncConfigurationService::class);
        $sync->shouldReceive('markModelDirty')->once()->withArgs(fn (Product $model) =>
            $model->company_id === 'company-1'
            && $model->branch_id === null
            && $model->getTable() === 'products'
        );
        $this->app->instance(SyncConfigurationService::class, $sync);

        $this->callPrivate(new ProductImportController, 'signalImportedProducts', ['company-1']);
    }

    private function responseBody($response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function callPrivate(object $object, string $name, array $arguments): mixed
    {
        return (new ReflectionMethod($object, $name))->invokeArgs($object, $arguments);
    }
}
