@php
    $payload = $payload ?? [];
    $paperSize = strtolower($settings['paper_size'] ?? '80mm');
    $is58 = str_contains($paperSize, '58');
    $width = $is58 ? '48mm' : '72mm';
    $font = $is58 ? '10.5px' : '12px';
    $titleFont = $is58 ? '16px' : '21px';
    $orderNo = $payload['order_number'] ?? '-';
    $ticketTitle = ($payload['ticket_kind'] ?? 'new_order') === 'order_update'
        ? 'KITCHEN UPDATE'
        : 'KITCHEN ORDER';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; size: {{ $is58 ? '58mm auto' : '80mm auto' }}; }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { margin: 0; padding: 0; background: #ffffff; color: #000000; font-family: "DejaVu Sans Mono", "Courier New", monospace; font-size: {{ $font }}; line-height: 1.35; font-weight: 800; }
        .ticket { width: {{ $width }}; max-width: {{ $width }}; margin: 0 auto; padding: 3mm 2.5mm 2.5mm 3mm; }
        .center { text-align: center; }
        .title { border: 2px solid #000; padding: 5px 2px; font-size: {{ $titleFont }}; font-weight: 900; letter-spacing: 1px; }
        .order { font-size: {{ $titleFont }}; font-weight: 900; padding: 5px 0 3px 0; }
        .line { border-top: 1.4px dashed #000; margin: 7px 0; }
        .kv { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .kv td { padding: 2px 0; vertical-align: top; word-break: break-word; }
        .k { width: 36%; font-weight: 900; }
        .v { width: 64%; text-align: right; font-weight: 900; }
        .item { page-break-inside: avoid; margin-bottom: 10px; }
        .item-name { font-size: {{ $is58 ? '12.5px' : '15px' }}; font-weight: 900; text-transform: uppercase; }
        .sub { padding-left: 8px; font-weight: 900; text-transform: uppercase; }
        .note { border: 1.4px solid #000; padding: 4px; margin-top: 4px; font-weight: 900; text-transform: uppercase; }
        .footer { text-align: center; font-weight: 900; margin-top: 8px; }
    </style>
</head>
<body>
<div class="ticket">
    <div class="title center">{{ $ticketTitle }}</div>
    <div class="order center">{{ $orderNo }}</div>

    <table class="kv">
        <tr><td class="k">Invoice</td><td class="v">{{ $payload['invoice_number'] ?? '-' }}</td></tr>
        <tr><td class="k">Service</td><td class="v">{{ strtoupper($payload['service_type'] ?? 'takeaway') }}</td></tr>
        @if(!empty($payload['table']['name']))
            <tr><td class="k">Table</td><td class="v">{{ strtoupper($payload['table']['name']) }}</td></tr>
        @endif
        <tr><td class="k">Time</td><td class="v">{{ $payload['transaction_date'] ?? now()->format('Y-m-d H:i') }}</td></tr>
        <tr><td class="k">Till</td><td class="v">{{ trim(($payload['till']['code'] ?? '-') . ' ' . ($payload['till']['name'] ?? '')) }}</td></tr>
        <tr><td class="k">Cashier</td><td class="v">{{ $payload['cashier'] ?? '-' }}</td></tr>
    </table>

    <div class="line"></div>

    @foreach(($payload['items'] ?? []) as $item)
        <div class="item">
            <div class="item-name">{{ number_format((float)($item['quantity'] ?? 0), 0) }} x {{ $item['name'] ?? 'ITEM' }}</div>

            @foreach(($item['modifiers'] ?? []) as $modifier)
                <div class="sub">+ {{ $modifier['name'] ?? 'MODIFIER' }} x {{ number_format((float)($modifier['quantity'] ?? 1), 0) }}</div>
            @endforeach

            @foreach(($item['prep_messages'] ?? []) as $message)
                <div class="sub">* {{ is_array($message) ? ($message['message'] ?? '') : $message }}</div>
            @endforeach

            @if(!empty($item['prep_note']))
                <div class="note">NOTE: {{ $item['prep_note'] }}</div>
            @endif
        </div>
        @if(!$loop->last)<div class="line"></div>@endif
    @endforeach

    @if(!empty($payload['notes']))
        <div class="line"></div>
        <div class="note">SALE NOTE: {{ $payload['notes'] }}</div>
    @endif

    <div class="line"></div>
    <div class="footer">END ORDER {{ $orderNo }}</div>
</div>
</body>
</html>
