<?php

namespace Tests\Unit;

use App\Services\Hospitality\HospitalityCatalogImporter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class HospitalityCatalogImporterChannelTest extends TestCase
{
    public function test_pos_alias_uses_the_deterministic_counter_channel_priority(): void
    {
        $selected = $this->select([
            ['code' => 'DINE_IN', 'price_inclusive' => 12],
            ['code' => 'TAKEAWAY', 'price_inclusive' => 11],
            ['code' => 'INSTORE', 'price_inclusive' => 10],
        ]);

        self::assertSame('INSTORE', $selected['code']);
        self::assertSame(10, $selected['price_inclusive']);
    }

    public function test_native_pos_is_authoritative_and_unpriced_or_unavailable_rows_are_rejected(): void
    {
        $selected = $this->select([
            ['code' => 'POS', 'price_inclusive' => 9],
            ['code' => 'INSTORE', 'price_inclusive' => 10],
        ]);

        self::assertSame('POS', $selected['code']);
        self::assertSame(9, $selected['price_inclusive']);
        self::assertNull($this->select([
            ['code' => 'INSTORE', 'price_inclusive' => null, 'price_exclusive' => null],
            ['code' => 'TAKEAWAY', 'price_inclusive' => 11, 'is_available' => false],
        ]));
    }

    public function test_same_name_supplier_is_reused_without_overwriting_its_established_code(): void
    {
        $existing = (object) [
            'id' => 'supplier-1',
            'name' => 'Delta Beverages',
            'code' => 'PIZZA-DELTA',
        ];

        $identity = $this->supplierIdentity(
            null,
            $existing,
            'Delta Beverages',
            'CHICKEN-DELTA'
        );

        self::assertSame($existing, $identity['existing']);
        self::assertSame('Delta Beverages', $identity['name']);
        self::assertSame('PIZZA-DELTA', $identity['code']);
    }

    public function test_exact_supplier_code_is_preferred_when_name_points_to_another_row(): void
    {
        $codeMatch = (object) [
            'id' => 'supplier-by-code',
            'name' => 'Established Name',
            'code' => 'DELTA',
        ];
        $nameMatch = (object) [
            'id' => 'supplier-by-name',
            'name' => 'Delta Beverages',
            'code' => 'OTHER',
        ];

        $identity = $this->supplierIdentity(
            $codeMatch,
            $nameMatch,
            'Delta Beverages',
            'DELTA'
        );

        self::assertSame($codeMatch, $identity['existing']);
        self::assertSame('Established Name', $identity['name']);
        self::assertSame('DELTA', $identity['code']);
    }

    public function test_recipe_each_alias_is_written_in_the_product_base_uom(): void
    {
        $importer = $this->importerWithProductUoms(['INGREDIENT' => 'EACH']);
        $method = (new ReflectionClass($importer))->getMethod('canonicalRecipeLineUom');

        self::assertSame('EACH', $method->invoke($importer, 'MENU', 'INGREDIENT', [
            'uom' => 'PCS',
            'source_line_key' => 'Recipes:12',
        ]));
    }

    public function test_recipe_dimension_mismatch_requires_an_explicit_conversion(): void
    {
        $importer = $this->importerWithProductUoms(['OLIVE-OIL' => 'L']);
        $method = (new ReflectionClass($importer))->getMethod('canonicalRecipeLineUom');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Recipes:44');
        $method->invoke($importer, 'PIZZA', 'OLIVE-OIL', [
            'uom' => 'KG',
            'source_line_key' => 'Recipes:44',
        ]);
    }

    public function test_catalogue_import_scope_is_non_nullable_and_branch_specific(): void
    {
        $reflection = new ReflectionClass(HospitalityCatalogImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('catalogImportScopeKey');

        self::assertSame('brand', $method->invoke($importer, null));
        self::assertSame('brand', $method->invoke($importer, ''));
        self::assertSame(
            'branch:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            $method->invoke($importer, 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE')
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $channels
     * @return array<string, mixed>|null
     */
    private function select(array $channels): ?array
    {
        $reflection = new ReflectionClass(HospitalityCatalogImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('canonicalPosChannel');

        return $method->invoke($importer, $channels);
    }

    /**
     * @return array{existing: object|null, name: string, code: string|null}
     */
    private function supplierIdentity(
        ?object $codeMatch,
        ?object $nameMatch,
        string $name,
        string $code
    ): array {
        $reflection = new ReflectionClass(HospitalityCatalogImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolveSupplierIdentity');

        return $method->invoke($importer, $codeMatch, $nameMatch, $name, $code);
    }

    /** @param array<string, string> $uoms */
    private function importerWithProductUoms(array $uoms): HospitalityCatalogImporter
    {
        $reflection = new ReflectionClass(HospitalityCatalogImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('productUoms')->setValue($importer, $uoms);

        return $importer;
    }
}
