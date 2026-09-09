<?php

namespace App\Items;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    /**
     * Search and select item: look up sellable items by SKU, barcode or name.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string'],
        ]);

        $items = Item::query()
            ->where('active', true)
            ->when($data['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json(['items' => $items]);
    }

    /**
     * Price enquiry: show current price, tax and availability without
     * creating a sale line, by scanning or searching a SKU/barcode.
     */
    public function priceEnquiry(string $code)
    {
        $item = Item::query()
            ->where('active', true)
            ->where(function ($query) use ($code) {
                $query->where('sku', $code)->orWhere('barcode', $code);
            })
            ->first();

        if (! $item) {
            return response()->json(['message' => 'Item not found or not available for sale.'], 404);
        }

        return response()->json([
            'item' => [
                'id' => $item->id,
                'sku' => $item->sku,
                'barcode' => $item->barcode,
                'name' => $item->name,
                'price' => $item->price,
                'tax_rate' => $item->tax_rate,
                'active' => $item->active,
            ],
        ]);
    }
}
