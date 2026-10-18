<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $receipt->grn_number }} - Goods Received Note</title>
    <style>
        @page { margin: 28px; }
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 11px; }
        h1 { margin: 0 0 5px; font-size: 21px; color: #0f766e; }
        .muted { color: #64748b; }
        .header { width: 100%; margin-bottom: 22px; }
        .header td { vertical-align: top; }
        .right { text-align: right; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .meta td { border: 1px solid #dbe3ea; padding: 7px 9px; width: 25%; }
        table.lines { width: 100%; border-collapse: collapse; }
        .lines th { background: #0f766e; color: #fff; padding: 8px 6px; text-align: left; }
        .lines td { border-bottom: 1px solid #dbe3ea; padding: 8px 6px; }
        .number { text-align: right; white-space: nowrap; }
        .totals { width: 42%; margin: 16px 0 0 auto; border-collapse: collapse; }
        .totals td { padding: 5px 7px; }
        .totals .grand td { border-top: 2px solid #0f766e; font-size: 13px; font-weight: bold; }
        .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #dbe3ea; font-size: 9px; color: #64748b; }
    </style>
</head>
<body>
<table class="header"><tr>
    <td><h1>Goods Received Note</h1><div class="muted">{{ $company->company_name }}</div></td>
    <td class="right"><strong>{{ $receipt->grn_number }}</strong><br><span class="muted">Generated {{ now()->format('d M Y H:i') }}</span></td>
</tr></table>

<table class="meta">
    <tr>
        <td><span class="muted">Received date</span><br><strong>{{ optional($receipt->received_date)->format('d M Y') }}</strong></td>
        <td><span class="muted">Branch</span><br><strong>{{ $receipt->branch?->branch_name ?? $receipt->branch?->branch_code ?? '—' }}</strong></td>
        <td><span class="muted">Supplier</span><br><strong>{{ $receipt->supplier?->name ?? 'Quick / direct receive' }}</strong></td>
        <td><span class="muted">Source</span><br><strong>{{ ucwords(str_replace('_', ' ', $receipt->source ?? 'purchase_order')) }}</strong></td>
    </tr>
</table>

<table class="lines">
    <thead><tr><th>#</th><th>Item</th><th>Warehouse / location</th><th>Lot / expiry</th><th class="number">Qty</th><th class="number">Unit cost</th><th class="number">Total</th></tr></thead>
    <tbody>
    @foreach($receipt->lines as $index => $line)
        <tr>
            <td>{{ $index + 1 }}</td>
            <td><strong>{{ $line->product?->name ?? 'Unknown item' }}</strong><br><span class="muted">{{ $line->product?->code ?? $line->product?->sku }}</span></td>
            <td>{{ $line->warehouse?->name ?? '—' }}<br><span class="muted">{{ $line->location?->name ?? '' }}</span></td>
            <td>{{ $line->lot_number ?: '—' }}<br><span class="muted">{{ $line->expires_at ? \Carbon\Carbon::parse($line->expires_at)->format('d M Y') : '' }}</span></td>
            <td class="number">{{ number_format((float) $line->quantity, 4) }}</td>
            <td class="number">{{ $line->currency_code }} {{ number_format((float) $line->unit_cost, 4) }}</td>
            <td class="number">{{ $line->currency_code }} {{ number_format((float) $line->line_total, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal</td><td class="number">{{ $receipt->currency_code }} {{ number_format((float) $receipt->subtotal, 2) }}</td></tr>
    <tr><td>Tax</td><td class="number">{{ $receipt->currency_code }} {{ number_format((float) $receipt->tax_total, 2) }}</td></tr>
    <tr class="grand"><td>Total received</td><td class="number">{{ $receipt->currency_code }} {{ number_format((float) $receipt->grand_total, 2) }}</td></tr>
</table>

@if($receipt->notes)<p><strong>Notes:</strong> {{ $receipt->notes }}</p>@endif
<div class="footer">This document is the permanent receipt voucher for all items listed above. Voucher ID: {{ $receipt->id }}</div>
</body>
</html>
