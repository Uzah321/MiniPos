<?php

namespace Tests\Unit;

use App\Services\Hospitality\HospitalityCatalogWorkbookReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class HospitalityCatalogWorkbookReaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_it_normalises_the_pizza_inn_layout(): void
    {
        $book = $this->baseWorkbook();
        $raw = $book->getSheetByName('Raw Materials');
        $raw->fromArray([
            ['Old Description', 'Old Code', 'New Code', 'Description', 'New Description', 'UoM', 'Stock Group', 'Category', 'Manufactured', 'Quantity/Case Size', 'Case Cost', 'Cost Per Unit'],
            ['Pizza Flour', '10', 'ZWPZR01FLOUR', 'Flour', 'Flour - KG', 'KG', 'Dry Goods', 'Ingredients', 'No', 25, 50, 2],
        ], null, 'A1');
        $raw->setCellValue('S1', 'Market')->setCellValue('T1', 'Brand');
        $raw->setCellValue('S2', 'ZW')->setCellValue('T2', 'PZ');

        $menu = $book->getSheetByName('Menu Items');
        $menu->fromArray([
            ['Code', 'New Description', 'Old Code', 'Old Description', 'Flavour', 'Trading Days', 'Department', 'Department 2', 'Size', 'Channel', 'Instore Selling Price (Incl. Taxes)', 'Instore Selling Price (Excl. Taxes)', 'Modifiers', 'Market', 'Brand'],
            ['ZWPZC01P001S01', 'Small Classic', '100', 'Classic', 'Classic', 'Daily', 'Pizza', 'Small', 'Small', 'Take Away / Sit In', 5.93, 5, '1', 'ZW', 'PZ'],
        ], null, 'A1');

        $modifiers = $book->getSheetByName('Modifiers');
        $modifiers->fromArray([
            ['Code', 'Menu Items', 'Modifiers/Upsell Code', 1, 'Special Request'],
            ['ZWPZC75P001S00', 'No Cheese', 1],
        ], null, 'A1');

        $recipes = $book->getSheetByName('Recipes');
        $recipes->fromArray([
            ['New Code', 'New Menu Item Description', 'Code', 'Old Raw Description', 'Old Code', 'New Code', 'Description', 'New Description', 'Category', 'Qty', 'Cost/Item', 'Total Cost'],
            ['ZWPZC01P001S01', 'Small Classic', '100', 'Flour', '10', 'ZWPZR01FLOUR', 'Pizza Flour', 'Flour - KG', 'KG', 0.2, 2, 0.4],
        ], null, 'A1');

        $productionItems = $book->getSheetByName('Production Items List');
        $productionItems->fromArray([
            ['New Code', 'New Description', 'Old Code', 'Old Description', 'UOM', 'YIELD', 'TOTAL BATCH COST', 'Total Cost per Unit'],
            ['ZWPZPR01SAUCE', 'Pizza Sauce - KG', '20', 'Pizza Sauce', 'KG', 2, 4, 2],
        ], null, 'A1');
        $productionRecipes = $book->getSheetByName('Production Recipes');
        $productionRecipes->fromArray([
            ['New Code', 'New Description', 'New Raw Material Code', 'New Raw Material Description', 'UoM', 'Qty', 'Cost/Item', 'Total Cost'],
            ['ZWPZPR01SAUCE', 'Pizza Sauce - KG', 'ZWPZR01FLOUR', 'Flour - KG', 'KG', 1, 2, 2],
        ], null, 'A1');

        $path = $this->save($book);
        $reader = new HospitalityCatalogWorkbookReader();
        $catalog = $reader->read($path);

        self::assertSame('pizza_inn_gaap', $catalog['format']);
        self::assertSame('PZ', $catalog['brand_hint']);
        self::assertSame(2, $catalog['statistics']['recipes']);
        self::assertSame(2, $catalog['statistics']['recipe_lines']);
        self::assertSame(1, $catalog['statistics']['modifier_groups']);
        self::assertSame(0, $catalog['statistics']['prep_messages']);
        self::assertContains(
            'PREP_MESSAGES_NOT_PRESENT',
            array_column($catalog['issues'], 'code')
        );
        self::assertSame(1, $catalog['statistics']['pos_channel_aliases']);
        self::assertSame(['TAKEAWAY', 'DINE_IN'], array_column($catalog['menu_items'][0]['channels'], 'code'));

        $uploadPath = tempnam(sys_get_temp_dir(), 'servnest-upload-');
        self::assertNotFalse($uploadPath);
        self::assertTrue(copy($path, $uploadPath));
        $this->temporaryFiles[] = $uploadPath;
        $uploadedCatalog = $reader->read($uploadPath, 'pizza-upload.xlsx');
        self::assertSame('pizza-upload.xlsx', $uploadedCatalog['source']['name']);
        self::assertSame(2, $uploadedCatalog['statistics']['recipes']);
    }

    public function test_it_normalises_chicken_option_allowlists_into_exclusions(): void
    {
        $book = $this->baseWorkbook();
        $raw = $book->getSheetByName('Raw Materials');
        $raw->fromArray([
            [],
            ['Old', 'Name', 'Description', 'Size', 'New Description', 'Stock Group', 'Quantity/ Case Size', 'Cost per Case', 'Cost Per Unit', 'VAT'],
            ['Chicken Fillet', 'Chicken', 'Fillet', 'Kg', 'Chicken Fillet - Kg', 'Food', '1*10', 20, 2, 0],
            ['Toy', 'Toy', 'Toy', 'Ea', 'Toy - Ea', 'Packaging', 1, 1, 1, 0],
        ], null, 'A1');
        $raw->setCellValue('M3', 'Chicken Inn')->setCellValue('R3', 'ZWCHR01FILLET');
        $raw->setCellValue('M4', 'Chicken Inn')->setCellValue('R4', 'ZWCHR01TOY');

        $menu = $book->getSheetByName('Menu Items');
        $menu->setCellValue('A2', 'Old')->setCellValue('F2', 'Code')->setCellValue('G2', 'Menu Items');
        $menu->setCellValue('F3', 'ZWCHC01BURGER')->setCellValue('G3', 'Chicken Burger');
        $menu->setCellValue('I3', 'Daily')->setCellValue('J3', 'Burgers')->setCellValue('L3', 5);
        $menu->setCellValue('R3', 'ZWCHC17NOMAYO')->setCellValue('U3', '1');
        $menu->setCellValue('V3', 'Zimbabwe')->setCellValue('W3', 'Chicken Inn');
        $menu->setCellValue('F4', 'ZWCHC17NOMAYO')->setCellValue('G4', 'No Mayo');
        $menu->setCellValue('I4', 'Daily')->setCellValue('J4', 'Modifiers')->setCellValue('L4', 0.5);
        $menu->setCellValue('V4', 'Zimbabwe')->setCellValue('W4', 'Chicken Inn');

        $modifiers = $book->getSheetByName('Modifiers');
        $modifiers->fromArray([
            ['Code', 'Menu Items', 'Modifiers/Upsell Code', 1, 'All Exclusions'],
            ['ZWCHC17NOMAYO', 'No Mayo', 1],
            ['ZWCHC17NOONION', 'No Onion', 1],
        ], null, 'A1');

        $recipes = $book->getSheetByName('Recipes');
        $recipes->fromArray([
            ['Code', 'New Menu Code', 'Description', 'New Description', 'Code', 'New Item Code', 'Description', 'New Stock Description', 'Category', 'Qty', 'Cost/Item', 'Cost/Item', 'Total Cost', 'Total Cost'],
            [1, 'ZWCHC01BURGER', 'Chicken Burger', 'Chicken Burger', 2, 'ZWCHR01FILLET', 'Chicken Fillet', 'Chicken Fillet - Kg', 'Kg', 0.2, 2, 2, 0.4, 0.4],
            [null, null, null, null, 3, 'ZWCHR01TOY', 'Toy', 'Toy - Ea', 'Kg', 1, 1, 1, 1, 1],
        ], null, 'A1');
        $recipes->setCellValue('P2', 99);

        $catalog = (new HospitalityCatalogWorkbookReader())->read($this->save($book));

        self::assertSame('chicken_inn_gaap', $catalog['format']);
        self::assertSame(1, $catalog['statistics']['menu_modifier_groups']);
        self::assertSame(1, $catalog['statistics']['menu_modifier_exclusions']);
        self::assertSame('ZWCHC17NOONION', $catalog['menu_modifier_exclusions'][0]['modifier_option_external_code']);
        self::assertSame(0, $catalog['statistics']['prep_messages']);
        self::assertSame(2, $catalog['statistics']['pos_channel_aliases']);
        // The toy row is labelled Kg but costed at the toy's per-each cost, so
        // the workbook's own arithmetic proves the quantity is in EACH.
        self::assertSame(0, $catalog['statistics']['recipe_lines_skipped_uom']);
        self::assertSame(1, $catalog['statistics']['recipe_lines_uom_label_corrected']);
        self::assertContains(
            'RECIPE_UOM_LABEL_CORRECTED',
            array_column($catalog['issues'], 'code')
        );
        self::assertContains(
            'RECIPE_TOTAL_RECALCULATED',
            array_column($catalog['issues'], 'code')
        );
        self::assertSame(1.4, $catalog['recipes'][0]['total_cost']);
        $toy = collect($catalog['recipes'][0]['ingredients'])->firstWhere('external_code', 'ZWCHR01TOY');
        self::assertSame('EACH', $toy['uom']);
        $noMayo = collect($catalog['modifier_options'])
            ->firstWhere('external_code', 'ZWCHC17NOMAYO');
        self::assertSame(0.5, $noMayo['price_delta']);
    }

    public function test_it_extracts_dirty_chicken_option_lists_without_creating_fragment_tokens(): void
    {
        $reader = new HospitalityCatalogWorkbookReader();
        $method = (new ReflectionClass($reader))->getMethod('modifierOptionTokens');

        $tokens = $method->invoke(
            $reader,
            'Tub Of Love, Half Ltr Tub, ZWCHC16CHX271S00    ZWCHC16CHX272S00, '
                .'Kariba Cooler, Sundae ZWCHC16CHX283S00'
        );

        self::assertSame([
            'Tub Of Love',
            'Half Ltr Tub',
            'ZWCHC16CHX271S00',
            'ZWCHC16CHX272S00',
            'Kariba Cooler',
            'ZWCHC16CHX283S00',
        ], $tokens);
        self::assertNotContains('Sundae', $tokens);
    }

    public function test_invalid_recipe_targets_do_not_inherit_and_latest_duplicate_block_wins(): void
    {
        $book = $this->baseWorkbook();
        $raw = $book->getSheetByName('Raw Materials');
        $raw->fromArray([
            [],
            ['Old', 'Name', 'Description', 'Size', 'New Description', 'Stock Group', 'Quantity/ Case Size', 'Cost per Case', 'Cost Per Unit', 'VAT'],
            ['First', 'First', 'Ingredient', 'Kg', 'First Ingredient - Kg', 'Food', 1, 1, 1, 0],
        ], null, 'A1');
        $raw->setCellValue('M3', 'Chicken Inn')->setCellValue('R3', 'ZWCHR01FIRST');

        $menu = $book->getSheetByName('Menu Items');
        $menu->setCellValue('A2', 'Old')->setCellValue('F2', 'Code')->setCellValue('G2', 'Menu Items');
        $menu->setCellValue('F3', 'ZWCHC01BURGER')->setCellValue('G3', 'Chicken Burger');
        $menu->setCellValue('L3', 5)->setCellValue('V3', 'Zimbabwe')->setCellValue('W3', 'Chicken Inn');

        $recipes = $book->getSheetByName('Recipes');
        $recipes->fromArray([
            ['Old', 'Target', 'Description', 'Target Name', 'Old Ingredient', 'Ingredient', 'Ingredient Name', 'New Ingredient Name', 'UOM', 'Qty', 'Old Cost', 'Unit Cost', 'Old Total', 'Line Total'],
            [1, 'ZWCHC01BURGER', 'Burger', 'Chicken Burger', 10, 'ZWCHR01FIRST', 'First', 'First Ingredient', 'KG', 1, 1, 1, 1, 1],
            [],
            [2, '#N/A', 'Promo', 'Invalid Promo', 11, 'ZWCHR01WRONG', 'Wrong', 'Wrong Ingredient', 'KG', 1, 1, 1, 1, 1],
            [null, null, null, null, 12, 'ZWCHR01WRONG2', 'Wrong 2', 'Wrong Ingredient 2', 'KG', 1, 1, 1, 1, 1],
            [],
            [3, 'ZWCHC01BURGER', 'Burger', 'Chicken Burger', 13, 'ZWCHR01LATEST', 'Latest', 'Latest Ingredient', 'KG', 2, 1, 1, 2, 2],
        ], null, 'A1');

        $catalog = (new HospitalityCatalogWorkbookReader())->read($this->save($book));
        $recipe = collect($catalog['recipes'])->firstWhere('external_code', 'ZWCHC01BURGER');

        self::assertNotNull($recipe);
        self::assertCount(1, $recipe['ingredients']);
        self::assertSame('ZWCHR01LATEST', $recipe['ingredients'][0]['external_code']);
        self::assertSame(2.0, $recipe['ingredients'][0]['quantity']);
        self::assertNotContains('ZWCHR01WRONG', array_column($catalog['products'], 'external_code'));
        self::assertContains(
            'DUPLICATE_RECIPE_REPLACED',
            array_column($catalog['issues'], 'code')
        );
    }

    public function test_pizza_modifier_deltas_mislabelled_units_and_sized_options_follow_the_workbook(): void
    {
        $book = $this->baseWorkbook();
        $raw = $book->getSheetByName('Raw Materials');
        $raw->fromArray([
            ['Old Description', 'Old Code', 'New Code', 'Description', 'New Description', 'UoM', 'Stock Group', 'Category', 'Manufactured', 'Quantity/Case Size', 'Case Cost', 'Cost Per Unit'],
            ['Pizza Flour', '10', 'ZWPZR01FLOUR', 'Flour', 'Flour - KG', 'KG', 'Dry Goods', 'Ingredients', 'No', 25, 50, 2],
            ['Feta', '11', 'ZWPZR07FETA', 'Feta', 'Feta Cheese - KG', 'KG', 'Dairy', 'Ingredients', 'No', 1, 10, 10],
            ['Box', '12', 'ZWPZR14BOX', 'Box', 'Small Box - EA', 'EACH', 'Packaging', 'Packaging', 'No', 100, 36, 0.36],
        ], null, 'A1');
        $raw->setCellValue('S1', 'Market')->setCellValue('T1', 'Brand');
        $raw->setCellValue('S2', 'ZW')->setCellValue('T2', 'PZ');

        $menu = $book->getSheetByName('Menu Items');
        $menu->fromArray([
            ['Code', 'New Description', 'Old Code', 'Old Description', 'Flavour', 'Trading Days', 'Department', 'Department 2', 'Size', 'Channel', 'Instore Selling Price (Incl. Taxes)', 'Instore Selling Price (Excl. Taxes)', 'Modifiers', 'Market', 'Brand'],
            ['ZWPZC01P001S01', 'Small Classic', '100', 'Classic', 'Classic', 'Daily', 'Pizza', 'Small', 'Small', 'Take Away / Sit In', 5, 4.2, '1', 'ZW', 'PZ'],
            ['ZWPZC01P001S03', 'Medium Classic', '101', 'Classic', 'Classic', 'Daily', 'Pizza', 'Medium', 'Medium', 'Take Away / Sit In', 8, 6.7, '1', 'ZW', 'PZ'],
            ['ZWPZC02P002S00', 'Garlic Bread', '102', 'Garlic Bread', 'Garlic', 'Daily', 'Starters', 'NA', 'NA', 'Take Away / Sit In', 3, 2.5, '', 'ZW', 'PZ'],
        ], null, 'A1');

        $modifiers = $book->getSheetByName('Modifiers');
        $modifiers->fromArray([
            ['Code', 'Menu Items', 'Modifiers/Upsell Code', 1, 'All Exclusions'],
            ['ZWPZC75P252S01', 'No Feta Cheese - Small', 1],
            ['ZWPZC75P252S03', 'No Feta Cheese - Medium', 1],
        ], null, 'A1');

        $recipes = $book->getSheetByName('Recipes');
        $recipes->fromArray([
            ['New Code', 'New Menu Item Description', 'Code', 'Old Raw Description', 'Old Code', 'New Code', 'Description', 'New Description', 'Category', 'Qty', 'Cost/Item', 'Total Cost'],
            ['ZWPZC01P001S01', 'Small Classic', '100', 'Flour', '10', 'ZWPZR01FLOUR', 'Pizza Flour', 'Flour - KG', 'KG', 0.2, 2, 0.4],
            [null, null, null, null, '11', 'ZWPZR07FETA', 'Feta', 'Feta Cheese - KG', 'KG', 0.007, 10, 0.07],
            // Mislabelled LTR, but costed at the box's base cost: kept in EACH.
            [null, null, null, null, '12', 'ZWPZR14BOX', 'Box', 'Small Box - EA', 'LTR', 1, 0.36, 0.36],
            [],
            ['ZWPZC01P001S03', 'Medium Classic', '101', 'Flour', '10', 'ZWPZR01FLOUR', 'Pizza Flour', 'Flour - KG', 'KG', 0.3, 2, 0.6],
            [null, null, null, null, '11', 'ZWPZR07FETA', 'Feta', 'Feta Cheese - KG', 'KG', 0.025, 10, 0.25],
            // Mislabelled LTR and not costed at the base cost: cannot be proven.
            [null, null, null, null, '12', 'ZWPZR14BOX', 'Box', 'Small Box - EA', 'LTR', 1, 0.5, 0.5],
            [],
            ['ZWPZC75P252S01', 'No Feta Cheese - Small', '200', 'Feta', '11', 'ZWPZR07FETA', 'Feta', 'Feta Cheese - KG', 'KG', -0.007, 10, -0.07],
            [],
            ['ZWPZC75P252S03', 'No Feta Cheese - Medium', '201', 'Feta', '11', 'ZWPZR07FETA', 'Feta', 'Feta Cheese - KG', 'KG', -0.025, 10, -0.25],
            [],
            // A sellable menu item cannot remove stock.
            ['ZWPZC02P002S00', 'Garlic Bread', '102', 'Flour', '10', 'ZWPZR01FLOUR', 'Pizza Flour', 'Flour - KG', 'KG', -0.1, 2, -0.2],
        ], null, 'A1');

        $catalog = (new HospitalityCatalogWorkbookReader())->read($this->save($book));
        $recipes = collect($catalog['recipes'])->keyBy('external_code');
        $issueCodes = array_column($catalog['issues'], 'code');

        $small = $recipes->get('ZWPZC01P001S01');
        self::assertCount(3, $small['ingredients']);
        self::assertSame('EACH', collect($small['ingredients'])->firstWhere('external_code', 'ZWPZR14BOX')['uom']);
        self::assertEqualsWithDelta(0.83, $small['total_cost'], 0.0001);
        self::assertContains('RECIPE_UOM_LABEL_CORRECTED', $issueCodes);

        $medium = $recipes->get('ZWPZC01P001S03');
        self::assertCount(2, $medium['ingredients']);
        self::assertContains('RECIPE_UOM_CONVERSION_REQUIRED', $issueCodes);

        $noFetaSmall = $recipes->get('ZWPZC75P252S01');
        self::assertNotNull($noFetaSmall);
        self::assertSame(-0.007, $noFetaSmall['ingredients'][0]['quantity']);
        self::assertEqualsWithDelta(-0.07, $noFetaSmall['total_cost'], 0.0001);
        self::assertSame(2, $catalog['statistics']['recipe_lines_negative_modifier_deltas']);

        self::assertFalse($recipes->has('ZWPZC02P002S00'));
        self::assertContains('NEGATIVE_RECIPE_LINE_SKIPPED', $issueCodes);

        $exclusions = collect($catalog['menu_modifier_exclusions'])
            ->map(fn (array $row): string => $row['menu_external_code'].'>'.$row['modifier_option_external_code'])
            ->sort()->values()->all();
        self::assertSame([
            'ZWPZC01P001S01>ZWPZC75P252S03',
            'ZWPZC01P001S03>ZWPZC75P252S01',
        ], $exclusions);
        self::assertSame(2, $catalog['statistics']['menu_modifier_size_exclusions']);
    }

    public function test_bom_master_data_gaps_are_reported_without_rewriting_the_recipe(): void
    {
        $book = $this->baseWorkbook();
        $raw = $book->getSheetByName('Raw Materials');
        $raw->fromArray([
            ['Old Description', 'Old Code', 'New Code', 'Description', 'New Description', 'UoM', 'Stock Group', 'Category', 'Manufactured', 'Quantity/Case Size', 'Case Cost', 'Cost Per Unit'],
            ['Pizza Flour', '10', 'ZWPZR01FLOUR', 'Flour', 'Flour - KG', 'KG', 'Dry Goods', 'Ingredients', 'No', 25, 50, 2],
        ], null, 'A1');
        $raw->setCellValue('S1', 'Market')->setCellValue('T1', 'Brand');
        $raw->setCellValue('S2', 'ZW')->setCellValue('T2', 'PZ');

        $book->getSheetByName('Production Items List')->fromArray([
            ['New Code', 'New Description', 'Old Code', 'Old Description', 'UOM', 'YIELD', 'TOTAL BATCH COST', 'Total Cost per Unit'],
            ['ZWPZPR08PPK', 'Prep Pineapple - KG', '20', 'Pz Prep Pineapple', 'KG', 1, 2.68, 2.68],
        ], null, 'A1');

        $book->getSheetByName('Menu Items')->fromArray([
            ['Code', 'New Description', 'Old Code', 'Old Description', 'Flavour', 'Trading Days', 'Department', 'Department 2', 'Size', 'Channel', 'Instore Selling Price (Incl. Taxes)', 'Instore Selling Price (Excl. Taxes)', 'Modifiers', 'Market', 'Brand'],
            ['ZWPZC01P003S01', 'Small Hawaiian', '140', 'Small Hawaiian', 'Hawaiian', 'Daily', 'Pizza', 'Small', 'Small', 'Take Away / Sit In', 3, 2.5, '1', 'ZW', 'PZ'],
        ], null, 'A1');
        $book->getSheetByName('Modifiers')->fromArray([
            ['Code', 'Menu Items', 'Modifiers/Upsell Code', 1, 'All Exclusions'],
            ['ZWPZC75P262S01', 'No Pineapple - Small', 1],
            ['ZWPZC75P247S01', 'No BBQ Chicken - Small', 1],
        ], null, 'A1');
        $book->getSheetByName('Recipes')->fromArray([
            ['New Code', 'New Menu Item Description', 'Code', 'Old Raw Description', 'Old Code', 'New Code', 'Description', 'New Description', 'Category', 'Qty', 'Cost/Item', 'Total Cost'],
            ['ZWPZC01P003S01', 'Small Hawaiian', '140', 'Pineapple', '20', 'ZWPZPR08PPK', 'Pz Prep Pineapple', 'Prep Pineapple - KG', 'KG', 0.025, 2.68, 0.067],
            [],
            // The exclusion points at an undefined twin code of the Prep item.
            ['ZWPZC75P262S01', 'No Pineapple - Small', '300', 'Pineapple', '21', 'ZWPZR08PPK', 'Pz Prep Pineapple', 'Prep Pineapple - KG', 'KG', -0.025, 8.52, -0.213],
            [],
            // An exclusion that adds stock is a sign error in the workbook.
            ['ZWPZC75P247S01', 'No BBQ Chicken - Small', '301', 'Flour', '10', 'ZWPZR01FLOUR', 'Pizza Flour', 'Flour - KG', 'KG', 0.16, 2, 0.32],
        ], null, 'A1');

        $catalog = (new HospitalityCatalogWorkbookReader())->read($this->save($book));
        $issues = collect($catalog['issues']);

        $undefined = $issues->firstWhere('code', 'RECIPE_INGREDIENT_NOT_IN_MASTER_DATA');
        self::assertNotNull($undefined);
        self::assertSame('ZWPZR08PPK', $undefined['entity_code']);
        self::assertStringContainsString('A defined item with the same name exists: ZWPZPR08PPK.', $undefined['message']);
        self::assertSame(1, $issues->where('code', 'EXCLUSION_OPTION_ADDS_STOCK')->count());

        // The recipe is imported exactly as written; nothing is remapped.
        $noPineapple = collect($catalog['recipes'])->firstWhere('external_code', 'ZWPZC75P262S01');
        self::assertSame('ZWPZR08PPK', $noPineapple['ingredients'][0]['external_code']);
        self::assertSame(-0.025, $noPineapple['ingredients'][0]['quantity']);
    }

    private function baseWorkbook(): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Raw Materials');
        foreach (['Menu Items', 'Modifiers', 'Recipes', 'Production Items List', 'Production Recipes', 'Production Item', 'Production Items Summary'] as $name) {
            $book->createSheet()->setTitle($name);
        }

        return $book;
    }

    private function save(Spreadsheet $book): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'servnest-bom-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
