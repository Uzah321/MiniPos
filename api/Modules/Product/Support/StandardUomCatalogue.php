<?php

namespace Modules\Product\Support;

use Ramsey\Uuid\Uuid;

final class StandardUomCatalogue
{
    /**
     * Keep these names stable. The UUIDv5 values are shared by cloud, edge and
     * fresh installations, so configuration snapshots can upsert by ID without
     * colliding with a differently generated local UOM row.
     */
    private const UUID_NAME_PREFIX = 'salexapos.com/uom/';

    /** @return array<int, array{name:string, code:string, quantity_type:string, decimal_places:int}> */
    public static function definitions(): array
    {
        return [
            ['name' => 'Each', 'code' => 'EACH', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Piece', 'code' => 'PCS', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Unit', 'code' => 'UNIT', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Dozen', 'code' => 'DOZEN', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Tonne', 'code' => 'TONNE', 'quantity_type' => 'decimal', 'decimal_places' => 3],
            ['name' => 'Kilogram', 'code' => 'KG', 'quantity_type' => 'decimal', 'decimal_places' => 3],
            ['name' => 'Gram', 'code' => 'G', 'quantity_type' => 'decimal', 'decimal_places' => 3],
            ['name' => 'Milligram', 'code' => 'MG', 'quantity_type' => 'decimal', 'decimal_places' => 4],
            ['name' => 'Kilolitre', 'code' => 'KL', 'quantity_type' => 'decimal', 'decimal_places' => 3],
            ['name' => 'Litre', 'code' => 'L', 'quantity_type' => 'decimal', 'decimal_places' => 3],
            ['name' => 'Millilitre', 'code' => 'ML', 'quantity_type' => 'decimal', 'decimal_places' => 2],
            ['name' => 'Cubic Metre', 'code' => 'M3', 'quantity_type' => 'decimal', 'decimal_places' => 3],
            ['name' => 'Cubic Centimetre', 'code' => 'CM3', 'quantity_type' => 'decimal', 'decimal_places' => 3],
            ['name' => 'Portion', 'code' => 'PORTION', 'quantity_type' => 'decimal', 'decimal_places' => 2],
            ['name' => 'Serving', 'code' => 'SERVING', 'quantity_type' => 'decimal', 'decimal_places' => 2],
            ['name' => 'Scoop', 'code' => 'SCOOP', 'quantity_type' => 'decimal', 'decimal_places' => 2],
            ['name' => 'Slice', 'code' => 'SLICE', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Tray', 'code' => 'TRAY', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Batch', 'code' => 'BATCH', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Case', 'code' => 'CASE', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Pack', 'code' => 'PACK', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Box', 'code' => 'BOX', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Carton', 'code' => 'CARTON', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Bag', 'code' => 'BAG', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Bottle', 'code' => 'BOTTLE', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Can', 'code' => 'CAN', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Jar', 'code' => 'JAR', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Roll', 'code' => 'ROLL', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Pallet', 'code' => 'PALLET', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Metre', 'code' => 'M', 'quantity_type' => 'decimal', 'decimal_places' => 3],
            ['name' => 'Centimetre', 'code' => 'CM', 'quantity_type' => 'decimal', 'decimal_places' => 3],
            ['name' => 'Millimetre', 'code' => 'MM', 'quantity_type' => 'decimal', 'decimal_places' => 3],
            ['name' => 'Square Metre', 'code' => 'M2', 'quantity_type' => 'decimal', 'decimal_places' => 3],
            ['name' => 'Hour', 'code' => 'HR', 'quantity_type' => 'decimal', 'decimal_places' => 2],
            ['name' => 'Minute', 'code' => 'MIN', 'quantity_type' => 'decimal', 'decimal_places' => 2],
            ['name' => 'Shift', 'code' => 'SHIFT', 'quantity_type' => 'decimal', 'decimal_places' => 2],
            ['name' => 'Man Hour', 'code' => 'MAN_HR', 'quantity_type' => 'decimal', 'decimal_places' => 2],
            ['name' => 'Machine Hour', 'code' => 'MACHINE_HR', 'quantity_type' => 'decimal', 'decimal_places' => 2],
            ['name' => 'Cycle', 'code' => 'CYCLE', 'quantity_type' => 'integer', 'decimal_places' => 0],
            ['name' => 'Run', 'code' => 'RUN', 'quantity_type' => 'integer', 'decimal_places' => 0],
        ];
    }

    public static function idForCode(string $code): string
    {
        return Uuid::uuid5(
            Uuid::NAMESPACE_DNS,
            self::UUID_NAME_PREFIX . strtoupper(trim($code))
        )->toString();
    }
}
