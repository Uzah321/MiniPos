<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesTransaction;

class KitchenOrderPrintBuilder
{
    public function resolvePrinter(SalesTransaction $sale, string $target = 'kitchen'): ?array
    {
        if (! DB::getSchemaBuilder()->hasTable('printers')) {
            return null;
        }

        $printer = DB::table('printers')
            ->where('company_id', $sale->company_id)
            ->where('target_printing', $target)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($sale) {
                $query->whereNull('branch_id');
                if ($sale->branch_id) {
                    $query->orWhere('branch_id', $sale->branch_id);
                }
            })
            ->where(function ($query) use ($sale) {
                $query->whereNull('till_id');
                if ($sale->till_id) {
                    $query->orWhere('till_id', $sale->till_id);
                }
            })
            ->orderByRaw('till_id IS NULL ASC')
            ->orderByRaw('branch_id IS NULL ASC')
            ->orderByDesc('is_default')
            ->first();

        return $printer ? (array) $printer : null;
    }

    public function buildKitchenPayload(SalesTransaction $sale): array
    {
        $orderNumber = $sale->order_number ?: data_get($sale->metadata, 'fast_food_order.order_number') ?: $sale->invoice_number;

        return [
            'type' => 'kitchen_order',
            'order_number' => $orderNumber,
            'invoice_number' => $sale->invoice_number,
            'business_date' => $sale->order_business_date ?: data_get($sale->metadata, 'fast_food_order.business_date') ?: $sale->business_date,
            'transaction_date' => optional($sale->transaction_date ?: $sale->created_at)->format('Y-m-d H:i:s'),
            'branch' => [
                'id' => $sale->branch_id,
                'name' => $sale->branch->branch_name ?? $sale->branch->name ?? 'Branch',
            ],
            'till' => [
                'id' => $sale->till_id,
                'code' => data_get($sale->metadata, 'till_code') ?: ($sale->till->till_code ?? null),
                'name' => data_get($sale->metadata, 'till_name') ?: ($sale->till->till_name ?? null),
            ],
            'cashier' => data_get($sale->metadata, 'cashier_name') ?: optional($sale->cashier ?? null)->name ?: 'Cashier',
            'customer' => $sale->customer_name ?: 'Walk-in Customer',
            'service_type' => data_get($sale->metadata, 'hospitality_service_type', 'takeaway'),
            'table' => [
                'id' => data_get($sale->metadata, 'hospitality_table_id'),
                'name' => data_get($sale->metadata, 'hospitality_table_name'),
            ],
            'hospitality_table_order_id' => data_get($sale->metadata, 'hospitality_table_order_id'),
            'notes' => $sale->notes,
            'items' => $sale->receiptLines
                ->sortBy('line_no')
                ->map(function ($line) {
                    $metadata = is_array($line->metadata)
                        ? $line->metadata
                        : (json_decode($line->metadata ?? '[]', true) ?: []);

                    $prepMessages = data_get(
                        $metadata,
                        'kitchen.prep_messages',
                        data_get($metadata, 'pos_line.prep_messages', data_get($metadata, 'prep_messages', []))
                    ) ?: [];
                    $prepNote = data_get(
                        $metadata,
                        'kitchen.prep_note',
                        data_get($metadata, 'pos_line.prep_note', data_get($metadata, 'prep_note'))
                    );
                    $modifiers = collect(data_get(
                        $metadata,
                        'kitchen.modifiers',
                        data_get($metadata, 'pos_line.modifiers', data_get($metadata, 'modifiers', []))
                    ) ?: [])
                        ->filter(fn ($modifier) => is_array($modifier)
                            && ($modifier['print_on_kitchen'] ?? true) === true)
                        ->values()
                        ->all();

                    return [
                        'line_id' => $line->id,
                        'line_no' => $line->line_no,
                        'product_id' => $line->product_id,
                        'name' => $line->line_name,
                        'quantity' => (float) $line->line_quantity,
                        'uom' => data_get($metadata, 'uom_code') ?: data_get($metadata, 'uom') ?: 'Each',
                        'prep_messages' => $prepMessages,
                        'prep_note' => $prepNote,
                        'modifiers' => $modifiers,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    public function renderKitchenHtml(array $payload, array $settings = []): string
    {
        return view('sales::kitchen-order', [
            'payload' => $payload,
            'settings' => $settings,
        ])->render();
    }

    public function renderKitchenText(array $payload): string
    {
        $lines = [];
        $ticketKind = ($payload['ticket_kind'] ?? 'new_order') === 'order_update'
            ? 'KITCHEN UPDATE'
            : 'KITCHEN ORDER';
        $lines[] = '*** '.$ticketKind.' ***';
        $lines[] = 'ORDER: '.($payload['order_number'] ?? '-');
        $serviceType = strtoupper((string) ($payload['service_type'] ?? 'TAKEAWAY'));
        $tableName = trim((string) data_get($payload, 'table.name', ''));
        $lines[] = 'SERVICE: '.$serviceType.($tableName !== '' ? ' / TABLE: '.strtoupper($tableName) : '');
        $lines[] = 'DATE: '.($payload['transaction_date'] ?? '-');
        $lines[] = 'TILL: '.trim(($payload['till']['code'] ?? '-').' '.($payload['till']['name'] ?? ''));
        $lines[] = 'CASHIER: '.($payload['cashier'] ?? '-');
        $lines[] = str_repeat('-', 32);

        foreach (($payload['items'] ?? []) as $item) {
            $lines[] = number_format((float) ($item['quantity'] ?? 0), 0).' x '.strtoupper((string) ($item['name'] ?? 'ITEM'));

            foreach (($item['modifiers'] ?? []) as $modifier) {
                $lines[] = '  + '.strtoupper((string) ($modifier['name'] ?? 'MODIFIER')).' x '.number_format((float) ($modifier['quantity'] ?? 1), 0);
            }

            foreach (($item['prep_messages'] ?? []) as $message) {
                $lines[] = '  * '.strtoupper((string) (is_array($message) ? ($message['message'] ?? '') : $message));
            }

            if (! empty($item['prep_note'])) {
                $lines[] = '  NOTE: '.strtoupper((string) $item['prep_note']);
            }

            $lines[] = '';
        }

        $lines[] = str_repeat('-', 32);
        $lines[] = 'END ORDER '.($payload['order_number'] ?? '');

        return implode("\n", $lines);
    }
}
