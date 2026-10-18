<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ServeNestIdentityAndMysqlRuntimeContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
    }

    public function test_runtime_defaults_and_distribution_examples_use_mysql(): void
    {
        $databaseConfig = $this->read('config/database.php');
        $composer = $this->read('composer.json');

        $this->assertStringContainsString("'default' => env('DB_CONNECTION', 'mysql')", $databaseConfig);
        $this->assertStringNotContainsString("touch('database/database.sqlite')", $composer);

        foreach (['.env.example', '.env.cloud.example', '.env.edge.example'] as $environment) {
            $contents = $this->read($environment);
            $this->assertMatchesRegularExpression(
                '/^DB_CONNECTION=mysql$/m',
                $contents,
                "{$environment} must provision a MySQL-compatible database."
            );
            $this->assertDoesNotMatchRegularExpression(
                '/^SALEXA_[A-Z0-9_]*=/m',
                $contents,
                "{$environment} should expose only canonical ServeNest settings."
            );
        }

        $syncConfig = $this->read('config/salexa_sync.php');
        $this->assertStringContainsString("'SERVENEST_SYNC_'.\$name", $syncConfig);
    }

    public function test_server_generated_customer_and_management_documents_use_servenest_identity(): void
    {
        foreach ([
            'app/Services/Reports/EnterpriseReportDownloadService.php',
            'Modules/Product/resources/views/reports/product-management-pdf.blade.php',
            'Modules/Sales/resources/views/enterprise-receipt.blade.php',
            'Modules/Product/Exports/MenuRecipeDsrSpreadsheetExport.php',
            'Modules/Product/Exports/StockWastageSpreadsheetExport.php',
            'Modules/Product/Exports/ClosingStockMovementSpreadsheetExport.php',
            'Modules/Product/Exports/ProductManagementSpreadsheetExport.php',
            'Modules/Product/Exports/StockIssuedToFrontSpreadsheetExport.php',
            'Modules/Sales/Exports/FoodIndustrySalesSpreadsheetExport.php',
        ] as $path) {
            $source = $this->read($path);

            $this->assertStringContainsString('ServeNest', $source, "{$path} should identify ServeNest.");
            $this->assertDoesNotMatchRegularExpression('/Salexa\s+POS/i', $source, "{$path} exposes legacy branding.");
        }

        $this->assertStringNotContainsString(
            'salexaposfront',
            strtolower($this->read('config/cors.php')),
            'CORS defaults must not advertise the retired frontend identity.'
        );
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        $this->assertNotFalse($contents, "Unable to read {$relativePath}.");

        return $contents;
    }
}
