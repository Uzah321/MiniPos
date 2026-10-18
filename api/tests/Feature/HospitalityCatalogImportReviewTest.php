<?php

namespace Tests\Feature;

use App\Services\Hospitality\HospitalityCatalogReviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class HospitalityCatalogImportReviewTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required.');
        }
        foreach (['shops', 'company_branches', 'brands'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('brands', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_code')->nullable();
            $table->string('branch_name')->nullable();
        });
        Schema::create('shops', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('branch_id');
            $table->timestamp('deleted_at')->nullable();
        });

        DB::table('brands')->insert([
            ['id' => 'brand-pizza', 'company_id' => 'company-1', 'code' => 'PZ', 'name' => 'Pizza Inn'],
            ['id' => 'brand-chicken', 'company_id' => 'company-1', 'code' => 'CH', 'name' => 'Chicken Inn'],
        ]);
        DB::table('company_branches')->insert([
            'id' => 'branch-1',
            'company_id' => 'company-1',
            'branch_code' => 'SHOP-1',
            'branch_name' => 'Shop 1',
        ]);
        DB::table('shops')->insert([
            'id' => 'shop-1',
            'company_id' => 'company-1',
            'brand_id' => 'brand-pizza',
            'branch_id' => 'branch-1',
        ]);
        $this->databaseReady = true;
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            foreach (['shops', 'company_branches', 'brands'] as $table) {
                Schema::dropIfExists($table);
            }
        }
        parent::tearDown();
    }

    public function test_preview_is_brand_bound_and_keeps_row_level_warnings(): void
    {
        $review = app(HospitalityCatalogReviewService::class)->review(
            $this->catalog('pizza_inn_gaap', ['PZ']),
            'company-1',
            'brand-pizza',
            'branch-1'
        );

        $this->assertTrue($review['can_commit']);
        $this->assertSame('brand-pizza', $review['brand']['id']);
        $this->assertSame('branch-1', $review['branch']['id']);
        $this->assertSame('Menu Items', $review['warnings'][0]['sheet']);
        $this->assertSame(27, $review['warnings'][0]['row']);
        $this->assertSame('UNKNOWN_MODIFIER_OPTION', $review['warnings'][0]['code']);
        $this->assertArrayNotHasKey('path', $review['source']);
    }

    public function test_preview_rejects_a_workbook_for_another_brand(): void
    {
        $review = app(HospitalityCatalogReviewService::class)->review(
            $this->catalog('chicken_inn_gaap', ['Chicken Inn']),
            'company-1',
            'brand-pizza'
        );

        $this->assertFalse($review['can_commit']);
        $this->assertSame('BRAND_MISMATCH', $review['errors'][0]['code']);
    }

    public function test_optional_branch_must_be_assigned_to_the_selected_brand(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not assigned to the selected brand');

        app(HospitalityCatalogReviewService::class)->review(
            $this->catalog('chicken_inn_gaap', ['Chicken Inn']),
            'company-1',
            'brand-chicken',
            'branch-1'
        );
    }

    /** @param array<int, string> $brandHints @return array<string, mixed> */
    private function catalog(string $format, array $brandHints): array
    {
        return [
            'format' => $format,
            'source' => [
                'path' => 'C:/secret/upload.tmp',
                'name' => 'catalog.xlsx',
                'sha256' => str_repeat('a', 64),
                'version' => 'v1',
            ],
            'sheet_manifest' => [['name' => 'Menu Items', 'rows' => 30]],
            'statistics' => ['products' => 10],
            'brand_hints' => $brandHints,
            'issues' => [[
                'severity' => 'warning',
                'code' => 'UNKNOWN_MODIFIER_OPTION',
                'message' => 'Unknown option.',
                'sheet' => 'Menu Items',
                'row' => 27,
                'entity_code' => 'MENU-1',
                'field' => 'allowed_modifier_options',
            ]],
        ];
    }
}
