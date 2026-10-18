<?php

namespace App\Services\Hospitality;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;
use ZipArchive;

final class HospitalityCatalogWorkbookReader
{
    private const MAX_FILE_BYTES = 100 * 1024 * 1024;

    private const MAX_UNCOMPRESSED_BYTES = 300 * 1024 * 1024;

    private const MAX_ARCHIVE_ENTRIES = 20_000;

    private const RELEVANT_SHEETS = [
        'Raw Materials',
        'Menu Items',
        'Modifiers',
        'Recipes',
        'Production Items List',
        'Production Recipes',
        'Production Item',
        'Production Items Summary',
        'Units of Measure',
    ];

    /**
     * Read the supported GAAP BOM formats into a database-neutral catalogue.
     * Formula cells intentionally prefer Excel's saved value: the workbooks
     * contain XLOOKUP/TEXTJOIN formulas that cannot be safely recalculated on
     * a server without Excel's calculation engine.
     *
     * @return array<string, mixed>
     */
    public function read(string $path, ?string $sourceName = null): array
    {
        $realPath = $this->validatePath($path, $sourceName);
        $reader = IOFactory::createReaderForFile($realPath);
        $sheetNames = $reader->listWorksheetNames($realPath);
        $loadSheets = array_values(array_intersect(self::RELEVANT_SHEETS, $sheetNames));

        foreach (['Raw Materials', 'Menu Items', 'Modifiers', 'Recipes'] as $required) {
            if (! in_array($required, $loadSheets, true)) {
                throw new RuntimeException("The workbook is missing the required '{$required}' sheet.");
            }
        }

        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        if (method_exists($reader, 'setIncludeCharts')) {
            $reader->setIncludeCharts(false);
        }
        $reader->setLoadSheetsOnly($loadSheets);

        $spreadsheet = $reader->load($realPath);
        try {
            $format = $this->detectFormat($spreadsheet);
            $catalog = $this->emptyCatalog($realPath, $sourceName, $format, $spreadsheet, $loadSheets);

            if ($format === 'pizza_inn_gaap') {
                $this->parsePizza($spreadsheet, $catalog);
            } else {
                $this->parseChicken($spreadsheet, $catalog);
            }

            return $this->finalize($catalog);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    private function validatePath(string $path, ?string $sourceName = null): string
    {
        if ($path === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            throw new InvalidArgumentException('A local .xlsx file path is required.');
        }

        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new InvalidArgumentException('The BOM workbook does not exist or is not readable.');
        }
        $extensionSource = trim((string) $sourceName) !== '' ? (string) $sourceName : $realPath;
        if (strtolower(pathinfo($extensionSource, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new InvalidArgumentException('Only .xlsx BOM workbooks are accepted.');
        }

        $size = filesize($realPath);
        if ($size === false || $size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('The BOM workbook is empty or exceeds the 100 MiB safety limit.');
        }

        $this->validateArchive($realPath);

        return $realPath;
    }

    private function validateArchive(string $path): void
    {
        if (! class_exists(ZipArchive::class)) {
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('The .xlsx file is not a readable ZIP workbook.');
        }

        try {
            if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new InvalidArgumentException('The workbook contains too many archive entries.');
            }

            $uncompressed = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    continue;
                }
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                    throw new InvalidArgumentException('The workbook contains an unsafe archive path.');
                }
                $uncompressed += (int) ($stat['size'] ?? 0);
                if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new InvalidArgumentException('The workbook exceeds the uncompressed safety limit.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function detectFormat(Spreadsheet $spreadsheet): string
    {
        $raw = $spreadsheet->getSheetByName('Raw Materials');
        $menu = $spreadsheet->getSheetByName('Menu Items');
        if (! $raw || ! $menu) {
            throw new RuntimeException('The workbook does not contain the supported GAAP catalogue sheets.');
        }

        if ($this->text($this->value($raw, 1, 1)) === 'Old Description'
            && $this->text($this->value($menu, 1, 2)) === 'New Description') {
            return 'pizza_inn_gaap';
        }

        if ($this->text($this->value($raw, 2, 1)) === 'Old'
            && $this->text($this->value($menu, 2, 6)) === 'Code') {
            return 'chicken_inn_gaap';
        }

        throw new RuntimeException('Unsupported BOM layout. Expected the Pizza Inn or Chicken Inn GAAP workbook format.');
    }

    /** @return array<string, mixed> */
    private function emptyCatalog(
        string $path,
        ?string $sourceName,
        string $format,
        Spreadsheet $spreadsheet,
        array $loadedSheets
    ): array {
        $manifest = [];
        foreach ($loadedSheets as $name) {
            $sheet = $spreadsheet->getSheetByName($name);
            if (! $sheet) {
                continue;
            }
            $manifest[] = [
                'name' => $name,
                'rows' => $sheet->getHighestDataRow(),
                'state' => $sheet->getSheetState(),
            ];
        }

        return [
            'format' => $format,
            'source' => [
                'path' => $path,
                'name' => basename(trim((string) $sourceName) !== '' ? (string) $sourceName : $path),
                'sha256' => hash_file('sha256', $path),
                'version' => pathinfo(
                    trim((string) $sourceName) !== '' ? (string) $sourceName : $path,
                    PATHINFO_FILENAME
                ),
            ],
            'sheet_manifest' => $manifest,
            'brand_hint' => null,
            'market_hint' => null,
            'products_by_code' => [],
            'menu_items_by_code' => [],
            'modifier_groups_by_code' => [],
            'modifier_options' => [],
            'recipes_by_key' => [],
            'prep_messages_by_code' => [],
            'warnings' => [],
            'issues' => [],
        ];
    }

    /** @param array<string, mixed> $catalog */
    private function parsePizza(Spreadsheet $book, array &$catalog): void
    {
        $this->parsePizzaRawMaterials($book->getSheetByName('Raw Materials'), $catalog);
        $this->parsePizzaProductionItems($book->getSheetByName('Production Items List'), $catalog);
        $this->parsePizzaMenuItems($book->getSheetByName('Menu Items'), $catalog);
        $this->parseModifiers($book->getSheetByName('Modifiers'), $catalog, 1);
        $this->parsePizzaMenuRecipes($book->getSheetByName('Recipes'), $catalog);
        $this->parsePizzaProductionRecipes($book->getSheetByName('Production Recipes'), $catalog);
    }

    /** @param array<string, mixed> $catalog */
    private function parseChicken(Spreadsheet $book, array &$catalog): void
    {
        $this->parseChickenRawMaterials($book->getSheetByName('Raw Materials'), $catalog);
        $this->parseChickenProductionItems($book->getSheetByName('Production Items Summary'), $catalog);
        $this->parseChickenMenuItems($book->getSheetByName('Menu Items'), $catalog);
        $this->parseModifiers($book->getSheetByName('Modifiers'), $catalog, 1);
        $this->parseChickenMenuRecipes($book->getSheetByName('Recipes'), $catalog);
        $this->parseChickenProductionRecipes($book->getSheetByName('Production Item'), $catalog);
    }

    /** @param array<string, mixed> $catalog */
    private function parsePizzaRawMaterials(?Worksheet $sheet, array &$catalog): void
    {
        if (! $sheet) {
            return;
        }

        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $externalCode = $this->code($this->value($sheet, $row, 3));
            if (! $externalCode) {
                continue;
            }

            $brand = $this->text($this->value($sheet, $row, 20));
            $market = $this->text($this->value($sheet, $row, 19));
            $catalog['brand_hint'] ??= $brand;
            $catalog['market_hint'] ??= $market;
            $manufactured = $this->boolean($this->value($sheet, $row, 9));

            $this->addProduct($catalog, [
                'external_code' => $externalCode,
                'legacy_code' => $this->code($this->value($sheet, $row, 2)),
                'name' => $this->text($this->value($sheet, $row, 5))
                    ?: $this->text($this->value($sheet, $row, 4))
                    ?: $this->text($this->value($sheet, $row, 1)),
                'item_kind' => $manufactured ? 'production_item' : 'raw_material',
                'uom' => $this->normaliseUom($this->value($sheet, $row, 6)),
                'category' => $this->text($this->value($sheet, $row, 8))
                    ?: $this->text($this->value($sheet, $row, 7)),
                'stock_group' => $this->text($this->value($sheet, $row, 7)),
                'market' => $market,
                'brand' => $brand,
                'case_size' => $this->caseSize($this->value($sheet, $row, 10)),
                'case_cost' => $this->number($this->value($sheet, $row, 11)),
                'unit_cost' => $this->number($this->value($sheet, $row, 12)),
                'tax_rate' => $this->number($this->value($sheet, $row, 29)),
                'supplier' => $this->text($this->value($sheet, $row, 24)),
                'supplier_code' => $this->code($this->value($sheet, $row, 25)),
                'stock_counts' => [
                    'daily' => $this->boolean($this->value($sheet, $row, 26)),
                    'weekly' => $this->boolean($this->value($sheet, $row, 27)),
                    'monthly' => $this->boolean($this->value($sheet, $row, 28)),
                ],
                'source' => ['sheet' => $sheet->getTitle(), 'row' => $row, 'manufactured' => $manufactured],
            ]);
        }
    }

    /** @param array<string, mixed> $catalog */
    private function parseChickenRawMaterials(?Worksheet $sheet, array &$catalog): void
    {
        if (! $sheet) {
            return;
        }

        for ($row = 3; $row <= $sheet->getHighestDataRow(); $row++) {
            $externalCode = $this->code($this->value($sheet, $row, 18));
            if (! $externalCode) {
                continue;
            }

            $brand = $this->text($this->value($sheet, $row, 13));
            $catalog['brand_hint'] ??= $brand;
            $catalog['market_hint'] ??= 'ZW';
            $stockGroup = $this->text($this->value($sheet, $row, 6));

            $this->addProduct($catalog, [
                'external_code' => $externalCode,
                'legacy_code' => $this->code($this->value($sheet, $row, 1)),
                'name' => $this->text($this->value($sheet, $row, 5))
                    ?: trim(implode(' ', array_filter([
                        $this->text($this->value($sheet, $row, 2)),
                        $this->text($this->value($sheet, $row, 3)),
                    ]))),
                'item_kind' => stripos((string) $stockGroup, 'manufactur') !== false
                    ? 'production_item'
                    : 'raw_material',
                'uom' => $this->normaliseUom($this->value($sheet, $row, 4)),
                'category' => $stockGroup,
                'stock_group' => $stockGroup,
                'market' => 'ZW',
                'brand' => $brand,
                'case_size' => $this->caseSize($this->value($sheet, $row, 7)),
                'case_cost' => $this->number($this->value($sheet, $row, 8)),
                'unit_cost' => $this->number($this->value($sheet, $row, 9)),
                'tax_rate' => $this->number($this->value($sheet, $row, 10)),
                'supplier' => $this->text($this->value($sheet, $row, 19)),
                'supplier_code' => $this->code($this->value($sheet, $row, 20)),
                'stock_counts' => [
                    'daily' => $this->boolean($this->value($sheet, $row, 21)),
                    'weekly' => $this->boolean($this->value($sheet, $row, 22)),
                    'monthly' => $this->boolean($this->value($sheet, $row, 23)),
                ],
                'source' => ['sheet' => $sheet->getTitle(), 'row' => $row],
            ]);
        }
    }

    /** @param array<string, mixed> $catalog */
    private function parsePizzaProductionItems(?Worksheet $sheet, array &$catalog): void
    {
        if (! $sheet) {
            return;
        }

        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $externalCode = $this->code($this->value($sheet, $row, 1));
            if (! $externalCode) {
                continue;
            }
            $this->addProduct($catalog, [
                'external_code' => $externalCode,
                'legacy_code' => $this->code($this->value($sheet, $row, 3)),
                'name' => $this->text($this->value($sheet, $row, 2))
                    ?: $this->text($this->value($sheet, $row, 4)),
                'item_kind' => 'production_item',
                'uom' => $this->normaliseUom($this->value($sheet, $row, 5)),
                'category' => 'Manufacturing',
                'stock_group' => 'Manufacturing',
                'yield_quantity' => $this->number($this->value($sheet, $row, 6)),
                'total_cost' => $this->number($this->value($sheet, $row, 7)),
                'unit_cost' => $this->number($this->value($sheet, $row, 8)),
                'source' => ['sheet' => $sheet->getTitle(), 'row' => $row],
            ]);
        }
    }

    /** @param array<string, mixed> $catalog */
    private function parseChickenProductionItems(?Worksheet $sheet, array &$catalog): void
    {
        if (! $sheet) {
            return;
        }

        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $externalCode = $this->code($this->value($sheet, $row, 17));
            if (! $externalCode) {
                continue;
            }
            $this->addProduct($catalog, [
                'external_code' => $externalCode,
                'legacy_code' => $this->code($this->value($sheet, $row, 1)),
                'name' => $this->text($this->value($sheet, $row, 2))
                    ?: $this->text($this->value($sheet, $row, 1)),
                'item_kind' => 'production_item',
                'uom' => $this->normaliseUom($this->value($sheet, $row, 11)),
                'category' => $this->text($this->value($sheet, $row, 3)) ?: 'Manufacturing',
                'stock_group' => $this->text($this->value($sheet, $row, 3)) ?: 'Manufacturing',
                'market' => $this->text($this->value($sheet, $row, 13)),
                'brand' => $this->text($this->value($sheet, $row, 10)),
                'case_size' => $this->caseSize($this->value($sheet, $row, 4)),
                'case_cost' => $this->number($this->value($sheet, $row, 5)),
                'unit_cost' => $this->number($this->value($sheet, $row, 6)),
                'tax_rate' => $this->number($this->value($sheet, $row, 7)),
                'yield_quantity' => $this->number($this->value($sheet, $row, 25)),
                'supplier' => $this->text($this->value($sheet, $row, 20)),
                'supplier_code' => $this->code($this->value($sheet, $row, 21)),
                'stock_counts' => [
                    'daily' => $this->boolean($this->value($sheet, $row, 22)),
                    'weekly' => $this->boolean($this->value($sheet, $row, 23)),
                    'monthly' => $this->boolean($this->value($sheet, $row, 24)),
                ],
                'source' => ['sheet' => $sheet->getTitle(), 'row' => $row],
            ]);
        }
    }

    /** @param array<string, mixed> $catalog */
    private function parsePizzaMenuItems(?Worksheet $sheet, array &$catalog): void
    {
        if (! $sheet) {
            return;
        }

        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $externalCode = $this->code($this->value($sheet, $row, 1));
            if (! $externalCode) {
                continue;
            }

            $brand = $this->text($this->value($sheet, $row, 15));
            $market = $this->text($this->value($sheet, $row, 14));
            $catalog['brand_hint'] ??= $brand;
            $catalog['market_hint'] ??= $market;
            $name = $this->text($this->value($sheet, $row, 2))
                ?: $this->text($this->value($sheet, $row, 4));
            $this->addProduct($catalog, [
                'external_code' => $externalCode,
                'legacy_code' => $this->code($this->value($sheet, $row, 3)),
                'name' => $name,
                'item_kind' => 'menu_item',
                'uom' => 'EACH',
                'category' => $this->text($this->value($sheet, $row, 7)),
                'department' => $this->text($this->value($sheet, $row, 7)),
                'flavour' => $this->text($this->value($sheet, $row, 5)),
                'size_label' => $this->text($this->value($sheet, $row, 9)),
                'market' => $market,
                'brand' => $brand,
                'source' => ['sheet' => $sheet->getTitle(), 'row' => $row],
            ]);

            $channels = [];
            foreach ($this->channels($this->value($sheet, $row, 10)) as $channel) {
                $channels[] = [
                    'code' => $channel,
                    'price_inclusive' => $this->number($this->value($sheet, $row, 11)),
                    'price_exclusive' => $this->number($this->value($sheet, $row, 12)),
                ];
            }
            $catalog['menu_items_by_code'][$externalCode] = [
                'external_code' => $externalCode,
                'name' => $name,
                'trading_days' => $this->text($this->value($sheet, $row, 6)),
                'channels' => $channels,
                'modifier_groups' => $this->groupCodes($this->value($sheet, $row, 13)),
                'source' => ['sheet' => $sheet->getTitle(), 'row' => $row],
            ];
        }
    }

    /** @param array<string, mixed> $catalog */
    private function parseChickenMenuItems(?Worksheet $sheet, array &$catalog): void
    {
        if (! $sheet) {
            return;
        }

        for ($row = 3; $row <= $sheet->getHighestDataRow(); $row++) {
            $externalCode = $this->code($this->value($sheet, $row, 6))
                ?: $this->code($this->value($sheet, $row, 27));
            if (! $externalCode) {
                continue;
            }

            $brand = $this->text($this->value($sheet, $row, 23));
            $market = $this->text($this->value($sheet, $row, 22));
            $catalog['brand_hint'] ??= $brand;
            $catalog['market_hint'] ??= $market;
            $name = $this->text($this->value($sheet, $row, 7))
                ?: $this->text($this->value($sheet, $row, 1));
            $this->addProduct($catalog, [
                'external_code' => $externalCode,
                'legacy_code' => $this->code($this->value($sheet, $row, 1)),
                'name' => $name,
                'item_kind' => 'menu_item',
                'uom' => 'EACH',
                'category' => $this->text($this->value($sheet, $row, 10)),
                'department' => $this->text($this->value($sheet, $row, 11))
                    ?: $this->text($this->value($sheet, $row, 10)),
                'size_label' => $this->text($this->value($sheet, $row, 8)),
                'market' => $market,
                'brand' => $brand,
                'unit_cost' => $this->number($this->value($sheet, $row, 16)),
                'source' => ['sheet' => $sheet->getTitle(), 'row' => $row],
            ]);

            $channels = [];
            $inStoreInclusive = $this->number($this->value($sheet, $row, 12));
            $inStoreExclusive = $this->number($this->value($sheet, $row, 13));
            if ($inStoreInclusive !== null || $inStoreExclusive !== null) {
                $channels[] = [
                    'code' => 'INSTORE',
                    'price_inclusive' => $inStoreInclusive,
                    'price_exclusive' => $inStoreExclusive,
                ];
            }
            $deliveryInclusive = $this->number($this->value($sheet, $row, 14));
            $deliveryExclusive = $this->number($this->value($sheet, $row, 15));
            if ($deliveryInclusive !== null || $deliveryExclusive !== null) {
                $channels[] = [
                    'code' => 'DELIVERY',
                    'price_inclusive' => $deliveryInclusive,
                    'price_exclusive' => $deliveryExclusive,
                ];
            }

            $catalog['menu_items_by_code'][$externalCode] = [
                'external_code' => $externalCode,
                'name' => $name,
                'trading_days' => $this->text($this->value($sheet, $row, 9)),
                'channels' => $channels,
                // Column 21 contains the group IDs. Columns 18 and 20 are
                // explicit option-code allowlists (modifier and upsell), not
                // group IDs; any omitted group option becomes an exclusion.
                'modifier_groups' => $this->groupCodes($this->value($sheet, $row, 21)),
                'allowed_modifier_options' => array_values(array_unique([
                    ...$this->modifierOptionTokens($this->value($sheet, $row, 18)),
                    ...$this->modifierOptionTokens($this->value($sheet, $row, 20)),
                ])),
                'source' => ['sheet' => $sheet->getTitle(), 'row' => $row],
            ];
        }
    }

    /** @param array<string, mixed> $catalog */
    private function parseModifiers(?Worksheet $sheet, array &$catalog, int $startRow): void
    {
        if (! $sheet) {
            return;
        }

        for ($row = $startRow; $row <= $sheet->getHighestDataRow(); $row++) {
            $groupCode = $this->code($this->value($sheet, $row, 4));
            $groupName = $this->text($this->value($sheet, $row, 5));
            if ($groupCode && $groupName) {
                $catalog['modifier_groups_by_code'][$groupCode] = [
                    'code' => $groupCode,
                    'name' => $groupName,
                    'source' => ['sheet' => $sheet->getTitle(), 'row' => $row],
                ];
            }
        }

        $sort = [];
        for ($row = $startRow; $row <= $sheet->getHighestDataRow(); $row++) {
            $externalCode = $this->code($this->value($sheet, $row, 1));
            $name = $this->text($this->value($sheet, $row, 2));
            $groupCode = $this->code($this->value($sheet, $row, 3));
            if (! $externalCode || ! $name || ! $groupCode || strtolower($externalCode) === 'code') {
                continue;
            }

            $sort[$groupCode] = ($sort[$groupCode] ?? 0) + 1;
            $this->addProduct($catalog, [
                'external_code' => $externalCode,
                'legacy_code' => null,
                'name' => $name,
                'item_kind' => 'modifier',
                'uom' => 'EACH',
                'category' => $catalog['modifier_groups_by_code'][$groupCode]['name'] ?? 'Modifiers',
                'department' => 'Modifiers',
                'source' => ['sheet' => $sheet->getTitle(), 'row' => $row],
            ]);
            $catalog['modifier_options'][] = [
                'group_code' => $groupCode,
                'external_code' => $externalCode,
                'name' => $name,
                'sort_order' => $sort[$groupCode],
                'price_delta' => 0.0,
                'source' => ['sheet' => $sheet->getTitle(), 'row' => $row],
            ];
        }
    }

    /** @param array<string, mixed> $catalog */
    private function parsePizzaMenuRecipes(?Worksheet $sheet, array &$catalog): void
    {
        if (! $sheet) {
            return;
        }
        $currentCode = null;
        $currentName = null;
        $replaceOnNextLine = false;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $rawTarget = $this->value($sheet, $row, 1);
            $rawIngredient = $this->value($sheet, $row, 6);
            $ingredient = $this->code($rawIngredient);
            if (! $this->activateRecipeLine(
                $catalog,
                'menu',
                $rawTarget,
                $this->text($this->value($sheet, $row, 2)),
                $rawIngredient,
                $ingredient,
                $sheet->getTitle(),
                $row,
                $currentCode,
                $currentName,
                $replaceOnNextLine
            )) {
                continue;
            }
            $this->addRecipeLine($catalog, 'menu', $currentCode, $currentName, [
                'external_code' => $ingredient,
                'legacy_code' => $this->code($this->value($sheet, $row, 5)),
                'name' => $this->text($this->value($sheet, $row, 8))
                    ?: $this->text($this->value($sheet, $row, 7)),
                'uom' => $this->normaliseUom($this->value($sheet, $row, 9)),
                'quantity' => $this->number($this->value($sheet, $row, 10)),
                'unit_cost' => $this->number($this->value($sheet, $row, 11)),
                'line_total' => $this->number($this->value($sheet, $row, 12)),
                'source_line_key' => $sheet->getTitle().':'.$row,
            ]);
        }
    }

    /** @param array<string, mixed> $catalog */
    private function parseChickenMenuRecipes(?Worksheet $sheet, array &$catalog): void
    {
        if (! $sheet) {
            return;
        }
        $currentCode = null;
        $currentName = null;
        $replaceOnNextLine = false;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $rawTarget = $this->value($sheet, $row, 2);
            $rawIngredient = $this->value($sheet, $row, 6);
            $ingredient = $this->code($rawIngredient);
            if (! $this->activateRecipeLine(
                $catalog,
                'menu',
                $rawTarget,
                $this->text($this->value($sheet, $row, 4))
                    ?: $this->text($this->value($sheet, $row, 3)),
                $rawIngredient,
                $ingredient,
                $sheet->getTitle(),
                $row,
                $currentCode,
                $currentName,
                $replaceOnNextLine
            )) {
                continue;
            }
            $this->addRecipeLine($catalog, 'menu', $currentCode, $currentName, [
                'external_code' => $ingredient,
                'legacy_code' => $this->code($this->value($sheet, $row, 5)),
                'name' => $this->text($this->value($sheet, $row, 8))
                    ?: $this->text($this->value($sheet, $row, 7)),
                'uom' => $this->normaliseUom($this->value($sheet, $row, 9)),
                'quantity' => $this->number($this->value($sheet, $row, 10)),
                'unit_cost' => $this->number($this->value($sheet, $row, 12))
                    ?? $this->number($this->value($sheet, $row, 11)),
                'line_total' => $this->number($this->value($sheet, $row, 14))
                    ?? $this->number($this->value($sheet, $row, 13)),
                'source_line_key' => $sheet->getTitle().':'.$row,
                'recipe_total' => $this->number($this->value($sheet, $row, 16)),
            ]);
        }
    }

    /** @param array<string, mixed> $catalog */
    private function parsePizzaProductionRecipes(?Worksheet $sheet, array &$catalog): void
    {
        if (! $sheet) {
            return;
        }
        $currentCode = null;
        $currentName = null;
        $replaceOnNextLine = false;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $rawTarget = $this->value($sheet, $row, 1);
            $rawIngredient = $this->value($sheet, $row, 3);
            $ingredient = $this->code($rawIngredient);
            if (! $this->activateRecipeLine(
                $catalog,
                'production',
                $rawTarget,
                $this->text($this->value($sheet, $row, 2)),
                $rawIngredient,
                $ingredient,
                $sheet->getTitle(),
                $row,
                $currentCode,
                $currentName,
                $replaceOnNextLine
            )) {
                continue;
            }
            $this->addRecipeLine($catalog, 'production', $currentCode, $currentName, [
                'external_code' => $ingredient,
                'legacy_code' => $this->code($this->value($sheet, $row, 11)),
                'name' => $this->text($this->value($sheet, $row, 4))
                    ?: $this->text($this->value($sheet, $row, 12)),
                'uom' => $this->normaliseUom($this->value($sheet, $row, 5)),
                'quantity' => $this->number($this->value($sheet, $row, 6)),
                'unit_cost' => $this->number($this->value($sheet, $row, 7)),
                'line_total' => $this->number($this->value($sheet, $row, 8)),
                'source_line_key' => $sheet->getTitle().':'.$row,
            ]);
        }
    }

    /** @param array<string, mixed> $catalog */
    private function parseChickenProductionRecipes(?Worksheet $sheet, array &$catalog): void
    {
        if (! $sheet) {
            return;
        }
        $currentCode = null;
        $currentName = null;
        $replaceOnNextLine = false;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $rawTarget = $this->value($sheet, $row, 2);
            $rawIngredient = $this->value($sheet, $row, 6);
            $ingredient = $this->code($rawIngredient);
            if (! $this->activateRecipeLine(
                $catalog,
                'production',
                $rawTarget,
                $this->text($this->value($sheet, $row, 4))
                    ?: $this->text($this->value($sheet, $row, 3)),
                $rawIngredient,
                $ingredient,
                $sheet->getTitle(),
                $row,
                $currentCode,
                $currentName,
                $replaceOnNextLine
            )) {
                continue;
            }
            $this->addRecipeLine($catalog, 'production', $currentCode, $currentName, [
                'external_code' => $ingredient,
                'legacy_code' => $this->code($this->value($sheet, $row, 5)),
                'name' => $this->text($this->value($sheet, $row, 8))
                    ?: $this->text($this->value($sheet, $row, 7)),
                'uom' => $this->normaliseUom($this->value($sheet, $row, 9)),
                'quantity' => $this->number($this->value($sheet, $row, 10)),
                'unit_cost' => $this->number($this->value($sheet, $row, 12))
                    ?? $this->number($this->value($sheet, $row, 11)),
                'line_total' => $this->number($this->value($sheet, $row, 14))
                    ?? $this->number($this->value($sheet, $row, 13)),
                'source_line_key' => $sheet->getTitle().':'.$row,
                'recipe_total' => $this->number($this->value($sheet, $row, 16)),
            ]);
        }
    }

    /**
     * Maintain explicit recipe block state. Invalid non-empty targets and
     * fully blank separator rows close the active block, so continuation rows
     * can never inherit an unrelated recipe. If the same recipe is defined in
     * a later non-empty block, that latest block replaces the earlier one.
     *
     * @param array<string, mixed> $catalog
     */
    private function activateRecipeLine(
        array &$catalog,
        string $type,
        mixed $rawTarget,
        ?string $targetName,
        mixed $rawIngredient,
        ?string $ingredientCode,
        string $sheet,
        int $row,
        ?string &$currentCode,
        ?string &$currentName,
        bool &$replaceOnNextLine
    ): bool {
        $rawTargetText = $this->text($rawTarget);
        if ($rawTargetText !== null) {
            $targetCode = $this->code($rawTarget);
            if ($targetCode === null) {
                $currentCode = null;
                $currentName = null;
                $replaceOnNextLine = false;

                return false;
            }

            if ($currentCode !== $targetCode) {
                $currentCode = $targetCode;
                $replaceOnNextLine = true;
            }
            if ($targetName !== null && $targetName !== '') {
                $currentName = $targetName;
            }
        }

        if ($ingredientCode === null) {
            if ($rawTargetText === null && $this->text($rawIngredient) === null) {
                $currentCode = null;
                $currentName = null;
                $replaceOnNextLine = false;
            }

            return false;
        }
        if ($currentCode === null) {
            return false;
        }

        if ($replaceOnNextLine) {
            $key = $type.':'.$currentCode;
            if (isset($catalog['recipes_by_key'][$key])) {
                $message = "Recipe {$currentCode} is defined more than once; the latest non-empty block was used.";
                $catalog['warnings'][] = $message;
                $this->addIssue(
                    $catalog,
                    'warning',
                    'DUPLICATE_RECIPE_REPLACED',
                    $message,
                    ['sheet' => $sheet, 'row' => $row],
                    $currentCode,
                    'recipe'
                );
                unset($catalog['recipes_by_key'][$key]);
            }
            $replaceOnNextLine = false;
        }

        return true;
    }

    /** @param array<string, mixed> $catalog @param array<string, mixed> $product */
    private function addProduct(array &$catalog, array $product): void
    {
        $code = $this->code($product['external_code'] ?? null);
        if (! $code) {
            return;
        }
        $product['external_code'] = $code;
        $product['name'] = $this->text($product['name'] ?? null) ?: $code;
        $product['uom'] = $this->normaliseUom($product['uom'] ?? null) ?: 'EACH';

        $existing = $catalog['products_by_code'][$code] ?? null;
        if (! is_array($existing)) {
            $catalog['products_by_code'][$code] = $product;

            return;
        }

        $priority = ['raw_material' => 1, 'menu_item' => 2, 'modifier' => 3, 'production_item' => 4];
        $existingPriority = $priority[$existing['item_kind'] ?? 'raw_material'] ?? 0;
        $incomingPriority = $priority[$product['item_kind'] ?? 'raw_material'] ?? 0;
        $primary = $incomingPriority >= $existingPriority ? $product : $existing;
        $secondary = $incomingPriority >= $existingPriority ? $existing : $product;
        foreach ($secondary as $key => $value) {
            if ((! array_key_exists($key, $primary) || $primary[$key] === null || $primary[$key] === '')
                && $value !== null && $value !== '') {
                $primary[$key] = $value;
            }
        }
        $primary['source'] = array_values(array_filter([
            $existing['source'] ?? null,
            $product['source'] ?? null,
        ]));
        $catalog['products_by_code'][$code] = $primary;
    }

    /** @param array<string, mixed> $catalog @param array<string, mixed> $line */
    private function addRecipeLine(
        array &$catalog,
        string $type,
        string $targetCode,
        ?string $targetName,
        array $line
    ): void {
        $targetCode = $this->code($targetCode) ?? '';
        $ingredientCode = $this->code($line['external_code'] ?? null);
        $quantity = $this->number($line['quantity'] ?? null);
        if ($targetCode === '' || ! $ingredientCode || $quantity === null || $quantity == 0.0) {
            return;
        }
        // Negative lines are recipe deltas: an exclusion ("No Mushrooms") or a
        // substitution ("Deep Pan Dough" replaces the standard dough) removes
        // ingredients from the base item. They are only meaningful on modifier
        // options, which are applied on top of a menu item's own recipe.
        if ($quantity < 0
            && ($catalog['products_by_code'][$targetCode]['item_kind'] ?? null) !== 'modifier') {
            $message = "Recipe {$targetCode} ingredient {$ingredientCode} has a negative quantity, "
                .'but only modifier options may remove ingredients; the row was skipped.';
            $catalog['warnings'][] = $message;
            $this->addIssue(
                $catalog,
                'warning',
                'NEGATIVE_RECIPE_LINE_SKIPPED',
                $message,
                $this->sourceFromLineKey($line['source_line_key'] ?? null),
                $targetCode,
                'quantity'
            );

            return;
        }

        $line['external_code'] = $ingredientCode;
        $line['name'] = $this->text($line['name'] ?? null) ?: $ingredientCode;
        $line['uom'] = $this->normaliseUom($line['uom'] ?? null) ?: 'EACH';
        $line['quantity'] = $quantity;
        $recipeTotal = $this->number($line['recipe_total'] ?? null);
        unset($line['recipe_total']);

        $targetProduct = $catalog['products_by_code'][$targetCode] ?? null;
        if (! is_array($targetProduct)) {
            $this->addProduct($catalog, [
                'external_code' => $targetCode,
                'name' => $targetName ?: $targetCode,
                'item_kind' => $type === 'production' ? 'production_item' : 'menu_item',
                'uom' => $type === 'production' ? 'KG' : 'EACH',
                'category' => $type === 'production' ? 'Manufacturing' : 'Menu',
                'source' => ['sheet' => 'recipe_reference'],
            ]);
            $targetProduct = $catalog['products_by_code'][$targetCode];
        }
        if (! isset($catalog['products_by_code'][$ingredientCode])) {
            $this->addProduct($catalog, [
                'external_code' => $ingredientCode,
                'legacy_code' => $line['legacy_code'] ?? null,
                'name' => $line['name'],
                'item_kind' => 'raw_material',
                'uom' => $line['uom'],
                'category' => 'Imported Ingredients',
                'source' => ['sheet' => 'recipe_reference'],
            ]);
        }

        $key = $type.':'.$targetCode;
        if (! isset($catalog['recipes_by_key'][$key])) {
            $catalog['recipes_by_key'][$key] = [
                'external_code' => $targetCode,
                'name' => $targetName ?: ($targetProduct['name'] ?? $targetCode),
                'recipe_type' => $type === 'production' ? 'manufacturing' : 'menu',
                'yield_quantity' => $type === 'production'
                    ? ($this->number($targetProduct['yield_quantity'] ?? null) ?: 1.0)
                    : 1.0,
                'yield_uom' => $targetProduct['uom'] ?? ($type === 'production' ? 'KG' : 'EACH'),
                'total_cost' => $recipeTotal,
                'ingredients' => [],
            ];
        }
        if ($recipeTotal !== null) {
            $catalog['recipes_by_key'][$key]['total_cost'] = $recipeTotal;
        }
        $catalog['recipes_by_key'][$key]['ingredients'][] = $line;
    }

    /** @param array<string, mixed> $catalog @return array<string, mixed> */
    private function finalize(array $catalog): array
    {
        foreach ($catalog['recipes_by_key'] as &$recipe) {
            $sourceTotal = $this->number($recipe['total_cost'] ?? null);
            $computedTotal = $this->recipeLineTotal($recipe['ingredients'] ?? []);
            if ($sourceTotal !== null && abs($sourceTotal - $computedTotal) > 0.0001) {
                $message = "Recipe {$recipe['external_code']} source total {$sourceTotal} did not match its lines; "
                    ."the imported total was recalculated as {$computedTotal}.";
                $catalog['warnings'][] = $message;
                $this->addIssue(
                    $catalog,
                    'warning',
                    'RECIPE_TOTAL_RECALCULATED',
                    $message,
                    $this->sourceFromLineKey($recipe['ingredients'][0]['source_line_key'] ?? null),
                    (string) $recipe['external_code'],
                    'total_cost'
                );
            }
            $recipe['total_cost'] = $computedTotal;
            $yield = (float) ($recipe['yield_quantity'] ?: 1);
            $recipe['cost_per_unit'] = $yield > 0 ? (float) $recipe['total_cost'] / $yield : 0.0;
        }
        unset($recipe);

        // These GAAP workbooks do not contain a dedicated prep-message
        // dataset. Modifier labels such as "No Cheese" remain modifier
        // options; promoting them to prep messages would invent a second
        // meaning and duplicate terminal choices.
        $prepGroups = [];
        $prepWarning = 'No dedicated prep-message sheet was found; no prep messages were imported.';
        $catalog['warnings'][] = $prepWarning;
        $this->addIssue(
            $catalog,
            'warning',
            'PREP_MESSAGES_NOT_PRESENT',
            $prepWarning,
            null,
            null,
            'prep_messages'
        );

        $menuAssignments = [];
        $menuExclusions = [];
        $sizeExclusions = 0;
        $optionsByGroup = [];
        $optionCodeByName = [];
        foreach ($catalog['modifier_options'] as &$option) {
            $menuItem = $catalog['menu_items_by_code'][(string) $option['external_code']] ?? null;
            if ($menuItem) {
                $option['price_delta'] = $this->modifierOptionPrice($menuItem['channels'] ?? []);
            }
            $optionsByGroup[(string) $option['group_code']][] = (string) $option['external_code'];
            $optionCodeByName[$this->optionLookupKey((string) $option['name'])] = (string) $option['external_code'];
        }
        unset($option);
        $productCodeByName = [];
        $ambiguousProductNames = [];
        foreach ($catalog['products_by_code'] as $product) {
            $lookupKey = $this->optionLookupKey((string) ($product['name'] ?? ''));
            $productCode = (string) ($product['external_code'] ?? '');
            if ($lookupKey === '' || $productCode === '') {
                continue;
            }
            if (isset($productCodeByName[$lookupKey]) && $productCodeByName[$lookupKey] !== $productCode) {
                unset($productCodeByName[$lookupKey]);
                $ambiguousProductNames[$lookupKey] = true;
                continue;
            }
            if (! isset($ambiguousProductNames[$lookupKey])) {
                $productCodeByName[$lookupKey] = $productCode;
            }
        }
        foreach ($catalog['menu_items_by_code'] as $menu) {
            $explicitOptionList = array_key_exists('allowed_modifier_options', $menu);
            $allowedOptions = [];
            $resolvedOptionList = $this->resolveModifierOptionTokens(
                $menu['allowed_modifier_options'] ?? [],
                $catalog['products_by_code'],
                $optionCodeByName,
                $productCodeByName
            );
            foreach ($resolvedOptionList['resolved'] as $resolved) {
                $allowedOptions[$resolved] = true;
            }
            foreach ($resolvedOptionList['unknown'] as $token) {
                    $message = "Menu item {$menu['external_code']} references unknown modifier option {$token}.";
                    $catalog['warnings'][] = $message;
                    $this->addIssue(
                        $catalog,
                        'warning',
                        'UNKNOWN_MODIFIER_OPTION',
                        $message,
                        $menu['source'] ?? null,
                        (string) $menu['external_code'],
                        'allowed_modifier_options'
                    );
            }
            foreach ($menu['modifier_groups'] as $position => $groupCode) {
                if (! isset($catalog['modifier_groups_by_code'][$groupCode])) {
                    $message = "Menu item {$menu['external_code']} references unknown modifier group {$groupCode}.";
                    $catalog['warnings'][] = $message;
                    $this->addIssue(
                        $catalog,
                        'warning',
                        'UNKNOWN_MODIFIER_GROUP',
                        $message,
                        $menu['source'] ?? null,
                        (string) $menu['external_code'],
                        'modifier_groups'
                    );
                    continue;
                }
                $menuAssignments[] = [
                    'menu_external_code' => $menu['external_code'],
                    'group_code' => $groupCode,
                    'sort_order' => $position + 1,
                ];
                if ($explicitOptionList) {
                    foreach ($optionsByGroup[$groupCode] ?? [] as $optionCode) {
                        if (! isset($allowedOptions[$optionCode])) {
                            $menuExclusions[] = [
                                'menu_external_code' => $menu['external_code'],
                                'modifier_option_external_code' => $optionCode,
                                'reason' => 'Not included in the source workbook option allowlist.',
                            ];
                        }
                    }
                } else {
                    $mismatched = $this->sizeMismatchedOptions(
                        (string) $menu['external_code'],
                        $optionsByGroup[$groupCode] ?? []
                    );
                    foreach ($mismatched as $optionCode => $reason) {
                        $sizeExclusions++;
                        $menuExclusions[] = [
                            'menu_external_code' => $menu['external_code'],
                            'modifier_option_external_code' => $optionCode,
                            'reason' => $reason,
                        ];
                    }
                }
            }
        }

        $prepAssignments = [];
        foreach ($menuAssignments as $assignment) {
            if (! isset($prepGroups[$assignment['group_code']])) {
                continue;
            }
            foreach ($catalog['prep_messages_by_code'] as $message) {
                if ($message['group_code'] === $assignment['group_code']) {
                    $prepAssignments[] = [
                        'menu_external_code' => $assignment['menu_external_code'],
                        'prep_message_code' => $message['code'],
                        'sort_order' => $assignment['sort_order'],
                    ];
                }
            }
        }

        ksort($catalog['products_by_code']);
        ksort($catalog['menu_items_by_code']);
        ksort($catalog['modifier_groups_by_code']);
        ksort($catalog['recipes_by_key']);

        $catalog['products'] = array_values($catalog['products_by_code']);
        $catalog['menu_items'] = array_values($catalog['menu_items_by_code']);
        $catalog['modifier_groups'] = array_values($catalog['modifier_groups_by_code']);
        $catalog['menu_modifier_groups'] = $menuAssignments;
        $catalog['menu_modifier_exclusions'] = $menuExclusions;
        $catalog['recipes'] = array_values($catalog['recipes_by_key']);
        [$catalog['recipes'], $skippedRecipeUomLines, $correctedRecipeUomLines] = $this->removeUnsafeRecipeUomLines(
            $catalog,
            $catalog['recipes']
        );
        $this->flagRecipeMasterDataGaps($catalog, $catalog['recipes']);
        $catalog['prep_messages'] = array_values($catalog['prep_messages_by_code']);
        $catalog['prep_message_assignments'] = $prepAssignments;
        $catalog['warnings'] = array_values(array_unique($catalog['warnings']));
        $catalog['issues'] = array_values(array_reduce(
            $catalog['issues'],
            static function (array $carry, array $issue): array {
                $key = implode('|', [
                    $issue['severity'] ?? '',
                    $issue['code'] ?? '',
                    $issue['sheet'] ?? '',
                    $issue['row'] ?? '',
                    $issue['entity_code'] ?? '',
                    $issue['message'] ?? '',
                ]);
                $carry[$key] = $issue;

                return $carry;
            },
            []
        ));
        $catalog['brand_hints'] = array_values(array_unique(array_filter(array_map(
            fn (array $product): ?string => $this->text($product['brand'] ?? null),
            $catalog['products']
        ))));
        $catalog['statistics'] = [
            'products' => count($catalog['products']),
            'menu_items' => count($catalog['menu_items']),
            // Every supported workbook channel already contains a validated
            // selling amount. The importer will project one canonical POS
            // alias for each priced item while retaining these source rows.
            'pos_channel_aliases' => count(array_filter(
                $catalog['menu_items'],
                fn (array $menu): bool => ($menu['channels'] ?? []) !== []
            )),
            'production_items' => count(array_filter(
                $catalog['products'],
                fn (array $product): bool => $product['item_kind'] === 'production_item'
            )),
            'modifier_groups' => count($catalog['modifier_groups']),
            'modifier_options' => count($catalog['modifier_options']),
            'menu_modifier_groups' => count($menuAssignments),
            'menu_modifier_exclusions' => count($menuExclusions),
            'recipes' => count($catalog['recipes']),
            'recipe_lines' => array_sum(array_map(
                fn (array $recipe): int => count($recipe['ingredients']),
                $catalog['recipes']
            )),
            'recipe_lines_skipped_uom' => $skippedRecipeUomLines,
            'recipe_lines_uom_label_corrected' => $correctedRecipeUomLines,
            'recipe_lines_negative_modifier_deltas' => array_sum(array_map(
                fn (array $recipe): int => count(array_filter(
                    $recipe['ingredients'],
                    fn (array $line): bool => (float) $line['quantity'] < 0
                )),
                $catalog['recipes']
            )),
            'menu_modifier_size_exclusions' => $sizeExclusions,
            'prep_messages' => count($catalog['prep_messages']),
            'warnings' => count($catalog['warnings']),
        ];

        unset(
            $catalog['products_by_code'],
            $catalog['menu_items_by_code'],
            $catalog['modifier_groups_by_code'],
            $catalog['recipes_by_key'],
            $catalog['prep_messages_by_code']
        );

        return $catalog;
    }

    /**
     * The manufacturing service consumes recipe quantities directly in the
     * ingredient product's base UOM. Never retain a dimension-changing source
     * UOM unless an explicit conversion is available; these workbooks provide
     * no density/conversion factor for such rows.
     *
     * The recipe UoM column is often a mislabelled lookup (for example
     * "Mozzarella Cheese - KG" labelled LTR). When the workbook itself costs
     * the row at the ingredient's base-unit cost (unit cost = base cost and
     * total = quantity x unit cost), the quantity is in the base UoM and the
     * row is kept in that unit instead of being dropped.
     *
     * @param  array<string, mixed>  $catalog
     * @param  array<int, array<string, mixed>>  $recipes
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int}
     */
    private function removeUnsafeRecipeUomLines(array &$catalog, array $recipes): array
    {
        $baseUoms = [];
        foreach ($catalog['products_by_code'] as $code => $product) {
            $baseUoms[(string) $code] = $this->normaliseUom($product['uom'] ?? null) ?: 'EACH';
        }

        $safeRecipes = [];
        $skipped = 0;
        $corrected = 0;
        foreach ($recipes as $recipe) {
            $safeLines = [];
            foreach ($recipe['ingredients'] ?? [] as $line) {
                $ingredientCode = (string) ($line['external_code'] ?? '');
                $sourceUom = $this->normaliseUom($line['uom'] ?? null) ?: 'EACH';
                $baseUom = $baseUoms[$ingredientCode] ?? $sourceUom;
                if ($this->uomsAreEquivalent($sourceUom, $baseUom)) {
                    $line['uom'] = $baseUom;
                    $safeLines[] = $line;
                    continue;
                }

                $baseCost = $this->number($catalog['products_by_code'][$ingredientCode]['unit_cost'] ?? null);
                if ($this->isCostedInBaseUom($line, $baseCost)) {
                    $corrected++;
                    $message = "Recipe {$recipe['external_code']} ingredient {$ingredientCode} is labelled {$sourceUom}, "
                        ."but the workbook costs it per {$baseUom} at {$baseCost}; the row was imported in {$baseUom}.";
                    $catalog['warnings'][] = $message;
                    $this->addIssue(
                        $catalog,
                        'warning',
                        'RECIPE_UOM_LABEL_CORRECTED',
                        $message,
                        $this->sourceFromLineKey($line['source_line_key'] ?? null),
                        $ingredientCode,
                        'uom'
                    );
                    $line['uom'] = $baseUom;
                    $safeLines[] = $line;
                    continue;
                }

                $skipped++;
                $message = "Recipe {$recipe['external_code']} ingredient {$ingredientCode} uses {$sourceUom}, "
                    ."but the product base UOM is {$baseUom}; the row was skipped pending an explicit conversion.";
                $catalog['warnings'][] = $message;
                $this->addIssue(
                    $catalog,
                    'warning',
                    'RECIPE_UOM_CONVERSION_REQUIRED',
                    $message,
                    $this->sourceFromLineKey($line['source_line_key'] ?? null),
                    $ingredientCode,
                    'uom'
                );
            }

            if ($safeLines === []) {
                continue;
            }
            $recipe['ingredients'] = $safeLines;
            $total = $this->recipeLineTotal($safeLines);
            $recipe['total_cost'] = $total;
            $yield = (float) ($recipe['yield_quantity'] ?? 1);
            $recipe['cost_per_unit'] = $yield > 0 ? $total / $yield : 0.0;
            $safeRecipes[] = $recipe;
        }

        return [$safeRecipes, $skipped, $corrected];
    }

    /**
     * Report BOM inconsistencies the importer must not silently "correct":
     * recipe lines that point at codes defined nowhere in the master-data
     * sheets (often a twin of a same-named Prep item), and exclusion options
     * ("No ...") whose recipe adds stock instead of removing it.
     *
     * @param  array<string, mixed>  $catalog
     * @param  array<int, array<string, mixed>>  $recipes
     */
    private function flagRecipeMasterDataGaps(array &$catalog, array $recipes): void
    {
        $isUndefined = static function (?array $product): bool {
            $sources = $product['source'] ?? [];
            $sources = array_is_list($sources) ? $sources : [$sources];

            return $sources !== [] && array_reduce(
                $sources,
                static fn (bool $all, $source): bool => $all && (($source['sheet'] ?? null) === 'recipe_reference'),
                true
            );
        };
        $definedByName = [];
        foreach ($catalog['products_by_code'] as $code => $product) {
            if (! $isUndefined($product)) {
                $definedByName[$this->optionLookupKey((string) ($product['name'] ?? ''))][] = (string) $code;
            }
        }

        foreach ($recipes as $recipe) {
            $target = $catalog['products_by_code'][$recipe['external_code']] ?? null;
            $isExclusionOption = ($target['item_kind'] ?? null) === 'modifier'
                && preg_match('/^\s*no\s/i', (string) ($target['name'] ?? '')) === 1;

            foreach ($recipe['ingredients'] as $line) {
                $code = (string) $line['external_code'];
                $product = $catalog['products_by_code'][$code] ?? null;
                $source = $this->sourceFromLineKey($line['source_line_key'] ?? null);

                if ($isUndefined($product)) {
                    $twins = array_values(array_diff(
                        $definedByName[$this->optionLookupKey((string) ($product['name'] ?? ''))] ?? [],
                        [$code]
                    ));
                    $hint = count($twins) === 1 ? " A defined item with the same name exists: {$twins[0]}." : '';
                    $message = "Recipe {$recipe['external_code']} uses ingredient {$code} ({$product['name']}), "
                        ."which is not defined on the Raw Materials or Production sheets.{$hint}";
                    $catalog['warnings'][] = $message;
                    $this->addIssue($catalog, 'warning', 'RECIPE_INGREDIENT_NOT_IN_MASTER_DATA', $message, $source, $code, 'external_code');
                }

                if ($isExclusionOption && (float) $line['quantity'] > 0) {
                    $message = "Exclusion option {$recipe['external_code']} ({$target['name']}) adds {$line['quantity']} "
                        ."of {$code}; an exclusion is expected to remove stock.";
                    $catalog['warnings'][] = $message;
                    $this->addIssue($catalog, 'warning', 'EXCLUSION_OPTION_ADDS_STOCK', $message, $source, (string) $recipe['external_code'], 'quantity');
                }
            }
        }
    }

    /**
     * True when the workbook prices the row with the ingredient's base-unit
     * cost, which proves the quantity is expressed in that base unit.
     *
     * @param  array<string, mixed>  $line
     */
    private function isCostedInBaseUom(array $line, ?float $baseCost): bool
    {
        $unitCost = $this->number($line['unit_cost'] ?? null);
        $quantity = $this->number($line['quantity'] ?? null);
        if ($baseCost === null || $baseCost <= 0 || $unitCost === null || $quantity === null) {
            return false;
        }
        if (abs($unitCost - $baseCost) > max(0.0001, $baseCost * 0.005)) {
            return false;
        }

        $lineTotal = $this->number($line['line_total'] ?? null);

        return $lineTotal === null
            || abs($lineTotal - ($quantity * $unitCost)) <= max(0.0005, abs($lineTotal) * 0.005);
    }

    /**
     * GAAP item codes end in a size segment (ZWPZC01P003S01 is a Small pizza,
     * ZWPZC75P252S01 the Small "No Feta Cheese" option). When a group offers
     * an option in the menu item's exact size, options sized for other menu
     * sizes are excluded so a Small pizza cannot remove a Mega portion. Groups
     * without an exact-size option, and unsized (S00) options, are unchanged.
     *
     * @param  array<int, string>  $optionCodes
     * @return array<string, string> option code => exclusion reason
     */
    private function sizeMismatchedOptions(string $menuCode, array $optionCodes): array
    {
        $menuSize = $this->sizeCode($menuCode);
        if ($menuSize === null || $menuSize === 'S00') {
            return [];
        }

        $optionSizes = [];
        foreach ($optionCodes as $optionCode) {
            $size = $this->sizeCode((string) $optionCode);
            if ($size !== null && $size !== 'S00') {
                $optionSizes[(string) $optionCode] = $size;
            }
        }
        if (! in_array($menuSize, $optionSizes, true)) {
            return [];
        }

        $mismatched = [];
        foreach ($optionSizes as $optionCode => $size) {
            if ($size !== $menuSize) {
                $mismatched[$optionCode] = "Size-specific option ({$size}) does not match the menu item size ({$menuSize}).";
            }
        }

        return $mismatched;
    }

    private function sizeCode(string $code): ?string
    {
        return preg_match('/^[A-Z]{4}C\d{2}[A-Z0-9]+(S\d{2})$/', $code, $match) === 1 ? $match[1] : null;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function recipeLineTotal(array $lines): float
    {
        return array_reduce($lines, function (float $sum, array $line): float {
            $lineTotal = $this->number($line['line_total'] ?? null);
            if ($lineTotal === null) {
                $quantity = $this->number($line['quantity'] ?? null) ?? 0.0;
                $unitCost = $this->number($line['unit_cost'] ?? null) ?? 0.0;
                $lineTotal = $quantity * $unitCost;
            }

            return $sum + $lineTotal;
        }, 0.0);
    }

    private function uomsAreEquivalent(string $sourceUom, string $baseUom): bool
    {
        if ($sourceUom === $baseUom) {
            return true;
        }

        return in_array($sourceUom, ['EACH', 'PCS'], true)
            && in_array($baseUom, ['EACH', 'PCS'], true);
    }

    /** @return array{sheet: string|null, row: int|null} */
    private function sourceFromLineKey(mixed $sourceLineKey): array
    {
        $parts = explode(':', (string) $sourceLineKey, 2);

        return [
            'sheet' => $parts[0] !== '' ? $parts[0] : null,
            'row' => isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null,
        ];
    }

    /**
     * @param array<string, mixed> $catalog
     * @param array<string, mixed>|array<int, array<string, mixed>>|null $source
     */
    private function addIssue(
        array &$catalog,
        string $severity,
        string $code,
        string $message,
        mixed $source = null,
        ?string $entityCode = null,
        ?string $field = null
    ): void {
        if (is_array($source) && array_is_list($source)) {
            $source = $source[0] ?? null;
        }
        $catalog['issues'][] = [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'sheet' => is_array($source) ? ($source['sheet'] ?? null) : null,
            'row' => is_array($source) && isset($source['row']) ? (int) $source['row'] : null,
            'entity_code' => $entityCode,
            'field' => $field,
        ];
    }

    private function value(Worksheet $sheet, int $row, int $column): mixed
    {
        $cell = $sheet->getCell([$column, $row]);
        $value = $cell->getValue();
        if ($cell->isFormula()) {
            $cached = $this->oldCalculatedValue($cell);
            if ($cached !== null && $cached !== '') {
                return $cached;
            }
            // Never ask PhpSpreadsheet to recalculate these workbooks. Their
            // XLOOKUP/TEXTJOIN formulas are Excel-specific and a single blank
            // cache entry can trigger a very expensive dependency walk.
            return null;
        }

        return $value instanceof RichText ? $value->getPlainText() : $value;
    }

    private function oldCalculatedValue(Cell $cell): mixed
    {
        try {
            return $cell->getOldCalculatedValue();
        } catch (Throwable) {
            return null;
        }
    }

    private function text(mixed $value): ?string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if ($value === null || $value === false || is_array($value) || is_object($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function code(mixed $value): ?string
    {
        $code = $this->text($value);
        if ($code === null || in_array(strtoupper($code), ['NF', 'N/F', 'FALSE', '#N/A', '#VALUE!'], true)) {
            return null;
        }

        return strtoupper(preg_replace('/\s+/', '', $code));
    }

    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        $text = $this->text($value);
        if ($text === null) {
            return null;
        }
        $normal = str_replace([',', '$', 'USD', 'ZWL'], '', strtoupper($text));

        return is_numeric($normal) && is_finite((float) $normal) ? (float) $normal : null;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $text = strtolower((string) ($this->text($value) ?? ''));

        return in_array($text, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private function caseSize(mixed $value): ?float
    {
        $number = $this->number($value);
        if ($number !== null) {
            return $number > 0 ? $number : null;
        }
        $text = strtolower((string) ($this->text($value) ?? ''));
        if ($text === '' || ! preg_match_all('/\d+(?:\.\d+)?/', $text, $matches)) {
            return null;
        }
        $numbers = array_map('floatval', $matches[0]);
        $result = (str_contains($text, '*') || str_contains($text, 'x'))
            ? array_product($numbers)
            : end($numbers);

        return $result > 0 ? (float) $result : null;
    }

    private function normaliseUom(mixed $value): ?string
    {
        $uom = strtoupper(trim((string) ($this->text($value) ?? '')));
        $uom = preg_replace('/[^A-Z0-9]+/', '_', $uom);
        $uom = trim((string) $uom, '_');

        return match ($uom) {
            '' => null,
            'EA', 'EACHES' => 'EACH',
            'PC', 'PCS', 'PIECE', 'PIECES' => 'PCS',
            'KGS', 'KILOGRAM', 'KILOGRAMS' => 'KG',
            'GRAM', 'GRAMS', 'GMS' => 'G',
            'LI', 'LT', 'LTR', 'LTRS', 'LITRE', 'LITRES', 'LITER', 'LITERS' => 'L',
            'MILLILITRE', 'MILLILITRES', 'MILLILITER', 'MILLILITERS' => 'ML',
            default => substr($uom, 0, 50),
        };
    }

    /** @return array<int, string> */
    private function groupCodes(mixed $value): array
    {
        $text = $this->text($value);
        if ($text === null) {
            return [];
        }
        $tokens = preg_split('/[,;|\/]+/', $text) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (string $token): ?string => $this->code(trim($token)),
            $tokens
        ))));
    }

    /**
     * Chicken Inn's cached formula output can mix display names and multiple
     * product codes in one comma-delimited cell. Extract every embedded code
     * before preserving code-free segments as exact-name lookup candidates.
     *
     * @return array<int, string>
     */
    private function modifierOptionTokens(mixed $value): array
    {
        $text = $this->text($value);
        if ($text === null) {
            return [];
        }

        $tokens = [];
        foreach (preg_split('/[,;|\/]+/', $text) ?: [] as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            preg_match_all(
                '/(?=(ZW(?:CH|PZ)[A-Z0-9]{6,}?)(?=ZW(?:CH|PZ)|[^A-Z0-9]|$))/iu',
                strtoupper($segment),
                $matches
            );
            $codes = array_values(array_filter(array_map(
                fn (string $candidate): ?string => $this->code($candidate),
                $matches[1] ?? []
            )));
            if ($codes !== []) {
                array_push($tokens, ...$codes);
                continue;
            }

            $name = preg_replace('/\s+/u', ' ', $segment);
            if ($name !== null && $name !== '') {
                $tokens[] = trim($name);
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $productsByCode
     * @param  array<string, string>  $optionCodeByName
     * @param  array<string, string>  $productCodeByName
     * @return array{resolved: array<int, string>, unknown: array<int, string>}
     */
    private function resolveModifierOptionTokens(
        array $tokens,
        array $productsByCode,
        array $optionCodeByName,
        array $productCodeByName
    ): array {
        $resolved = [];
        $unknown = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = (string) $tokens[$index];
            $lookupKey = $this->optionLookupKey($token);
            $code = isset($productsByCode[$token])
                ? $token
                : ($optionCodeByName[$lookupKey] ?? $productCodeByName[$lookupKey] ?? null);
            if ($code) {
                $resolved[] = $code;
                continue;
            }

            // Some cached formula values split one product display name over
            // adjacent comma fragments (for example "Tub Of Love, Half Ltr
            // Tub"). Rejoin only adjacent name fragments and require the
            // resulting full name to match exactly.
            $combined = $token;
            $matchedAt = null;
            for ($candidateIndex = $index + 1; $candidateIndex < min($count, $index + 4); $candidateIndex++) {
                $candidate = (string) $tokens[$candidateIndex];
                if (isset($productsByCode[$candidate])
                    || preg_match('/^ZW(?:CH|PZ)[A-Z0-9]{6,}$/i', $candidate)) {
                    break;
                }
                $combined .= ' '.$candidate;
                $combinedKey = $this->optionLookupKey($combined);
                $code = $optionCodeByName[$combinedKey] ?? $productCodeByName[$combinedKey] ?? null;
                if ($code) {
                    $matchedAt = $candidateIndex;
                    break;
                }
            }
            if ($code && $matchedAt !== null) {
                $resolved[] = $code;
                $index = $matchedAt;
                continue;
            }

            $unknown[] = $token;
        }

        return [
            'resolved' => array_values(array_unique($resolved)),
            'unknown' => array_values(array_unique($unknown)),
        ];
    }

    /** @param array<int, array<string, mixed>> $channels */
    private function modifierOptionPrice(array $channels): float
    {
        $priorities = ['POS', 'INSTORE', 'TAKEAWAY', 'DINE_IN', 'DELIVERY'];
        foreach (['price_inclusive', 'price_exclusive'] as $field) {
            foreach ($priorities as $priority) {
                foreach ($channels as $channel) {
                    if (strtoupper((string) ($channel['code'] ?? '')) !== $priority
                        || ! is_numeric($channel[$field] ?? null)) {
                        continue;
                    }

                    return max(0.0, (float) $channel[$field]);
                }
            }
        }

        return 0.0;
    }

    /** @return array<int, string> */
    private function channels(mixed $value): array
    {
        $text = strtolower((string) ($this->text($value) ?? 'instore'));
        $channels = [];
        if (preg_match('/take\s*away|takeout|collection/', $text)) {
            $channels[] = 'TAKEAWAY';
        }
        if (preg_match('/sit\s*in|dine\s*in/', $text)) {
            $channels[] = 'DINE_IN';
        }
        if (str_contains($text, 'delivery')) {
            $channels[] = 'DELIVERY';
        }
        if ($channels === []) {
            $channels[] = 'INSTORE';
        }

        return array_values(array_unique($channels));
    }

    private function optionLookupKey(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', trim($value)));
    }
}
