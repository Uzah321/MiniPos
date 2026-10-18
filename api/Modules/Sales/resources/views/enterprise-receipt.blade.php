{{-- Modules/Sales/resources/views/enterprise-receipt.blade.php --}}

@php
    $currency = $sale->currency_code ?? 'USD';
    $branch = $sale->branch;
    $company = $sale->company;
    $till = $sale->till;

    $settings = $settings ?? [];

    $paperSize = strtolower($settings['paper_size'] ?? $settings['receipt_size'] ?? '80mm');

    $is58 = str_contains($paperSize, '58');
    $isA5 = str_contains($paperSize, 'a5');
    $isA4 = str_contains($paperSize, 'a4');

    $receiptWidth = $is58 ? '48mm' : ($isA5 ? '136mm' : ($isA4 ? '180mm' : '72mm'));
    /*
    |--------------------------------------------------------------------------
    | Receipt spacing
    |--------------------------------------------------------------------------
    | Keep left and right padding equal so the printed information sits centered.
    | Top padding is reduced to remove the big blank space before the title.
    */
    $receiptPadding = $is58 ? '1.2mm 2mm 1.6mm 2mm' : '1.5mm 3mm 2mm 3mm';

    $settingFloat = function (string $key, float $default, ?float $min = null, ?float $max = null) use ($settings) {
        $value = isset($settings[$key]) && $settings[$key] !== ''
            ? (float) $settings[$key]
            : $default;

        if ($min !== null) {
            $value = max($min, $value);
        }

        if ($max !== null) {
            $value = min($max, $value);
        }

        return $value;
    };

    $settingBool = function (string $key, bool $default = false) use ($settings) {
        if (!array_key_exists($key, $settings)) {
            return $default;
        }

        return filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
    };

    /*
    |--------------------------------------------------------------------------
    | Adjustable receipt font settings
    |--------------------------------------------------------------------------
    */
    $fontFamilySetting = strtolower($settings['receipt_font_family'] ?? 'mono');

    $fontFamilyMap = [
        'mono' => '"DejaVu Sans Mono", "Courier New", monospace',
        'courier' => '"Courier New", monospace',
        'dejavumono' => '"DejaVu Sans Mono", monospace',
        'sans' => '"DejaVu Sans", Arial, sans-serif',
        'dejavusans' => '"DejaVu Sans", Arial, sans-serif',
        'serif' => '"DejaVu Serif", Georgia, serif',
    ];

    $receiptFontFamily = $fontFamilyMap[$fontFamilySetting] ?? $fontFamilyMap['mono'];

    $bodyFontSizeValue = $settingFloat('receipt_body_font_size', $is58 ? 10.5 : 12, 8, 18);
    $tinyFontSizeValue = $settingFloat('receipt_tiny_font_size', $is58 ? 9 : 10.5, 7, 16);
    $titleFontSizeValue = $settingFloat('receipt_title_font_size', $is58 ? 12.5 : 14, 9, 22);
    $brandFontSizeValue = $settingFloat('receipt_brand_font_size', $is58 ? 15 : 18, 10, 28);
    $branchFontSizeValue = $settingFloat('receipt_branch_font_size', $is58 ? 10.5 : 12, 8, 20);
    $totalFontSizeValue = $settingFloat('receipt_total_font_size', $is58 ? 14 : 16, 10, 26);

    $bodyFontSize = $bodyFontSizeValue . 'px';
    $tinyFontSize = $tinyFontSizeValue . 'px';
    $titleFontSize = $titleFontSizeValue . 'px';
    $brandFontSize = $brandFontSizeValue . 'px';
    $branchFontSize = $branchFontSizeValue . 'px';
    $totalFontSize = $totalFontSizeValue . 'px';

    $qrSize = $is58 ? '118px' : '148px';

    /*
    |--------------------------------------------------------------------------
    | Receipt barcode settings
    |--------------------------------------------------------------------------
    */
    $barcodeEnabled = $settingBool('receipt_barcode_enabled', true);
    $barcodeShowText = $settingBool('receipt_barcode_show_text', true);
    $barcodeHeight = (int) $settingFloat('receipt_barcode_height', $is58 ? 32 : 38, 20, 80);
    $barcodeWidthFactor = $settingFloat('receipt_barcode_width_factor', $is58 ? 1.05 : 1.35, 0.8, 3);

    $receiptBarcodeNumber = $sale->invoice_number
        ?? $sale->receipt_number
        ?? $sale->id
        ?? null;

    $receiptBarcodeSvg = null;
    $receiptBarcodeImageDataUri = null;
    $receiptBarcodeError = null;

    if ($barcodeEnabled && !empty($receiptBarcodeNumber)) {
        try {
            if (!class_exists(\Picqer\Barcode\BarcodeGeneratorSVG::class)) {
                throw new \Exception('Picqer barcode package is not installed.');
            }

            $barcodeGenerator = new \Picqer\Barcode\BarcodeGeneratorSVG();

            $receiptBarcodeSvg = $barcodeGenerator->getBarcode(
                (string) $receiptBarcodeNumber,
                \Picqer\Barcode\BarcodeGenerator::TYPE_CODE_128,
                $barcodeWidthFactor,
                $barcodeHeight
            );

            $receiptBarcodeImageDataUri = 'data:image/svg+xml;base64,' . base64_encode($receiptBarcodeSvg);
        } catch (\Throwable $e) {
            $receiptBarcodeSvg = null;
            $receiptBarcodeImageDataUri = null;
            $receiptBarcodeError = $e->getMessage();
        }
    }

    $formatMoney = function ($value) use ($currency) {
        return $currency . ' ' . number_format((float) $value, 2);
    };

    $formatPlainMoney = function ($value) {
        return number_format((float) $value, 2);
    };

    $formatQty = function ($line) {
        $uom = data_get($line->metadata, 'uom_code')
            ?: data_get($line->metadata, 'uom')
            ?: 'Each';

        $qty = (float) $line->line_quantity;
        $uomText = strtolower($uom);

        if ($uomText === 'portion' || $uomText === 'kg' || $uomText === 'kilogram') {
            return number_format($qty, 3) . ' ' . strtoupper($uom);
        }

        if (floor($qty) != $qty) {
            return number_format($qty, 3) . ' ' . strtoupper($uom);
        }

        return number_format($qty, 0) . ' ' . strtoupper($uom);
    };

    $cashierName = data_get($sale->metadata, 'cashier_name', 'Cashier');
    $tillCode = data_get($sale->metadata, 'till_code', optional($till)->till_code);
    $tillName = data_get($sale->metadata, 'till_name', optional($till)->till_name);
    $changeValue = $sale->change_amount ?? $sale->change ?? 0;
    $changeCreditedAmount = (float) data_get($sale->metadata, 'change_credit_amount', 0);
    $changeCreditedCurrency = data_get($sale->metadata, 'change_credit_currency_code', $currency);
    $changeCreditAccountNumber = data_get($sale->metadata, 'change_credit_account_number');
    $changeCreditPaymentNumber = data_get($sale->metadata, 'change_credit_payment_number');
    $hasCreditedChange = data_get($sale->metadata, 'change_disposition') === 'customer_account'
        && $changeCreditedAmount > 0;

    /*
    |--------------------------------------------------------------------------
    | Fast food order number
    |--------------------------------------------------------------------------
    | Kept separate from ZIMRA fiscal receipt numbers. Fiscalisation below is
    | untouched and still controls Fiscal Tax Invoice, QR, device and counters.
    */
    $orderNumber = $sale->order_number
        ?? data_get($sale->metadata, 'fast_food_order.order_number')
        ?? null;

    $orderSequence = $sale->order_sequence
        ?? data_get($sale->metadata, 'fast_food_order.order_sequence')
        ?? null;

    $orderBusinessDate = $sale->order_business_date
        ?? data_get($sale->metadata, 'fast_food_order.business_date')
        ?? $sale->business_date
        ?? null;

    $zimraReceipt = $zimraReceipt ?? null;
    $mobileFiscalReceipt = $mobileFiscalReceipt ?? null;
    $mobileBuyer = data_get($mobileFiscalReceipt, 'payload.receipt.buyerData');
    $mobileBuyer = is_array($mobileBuyer) ? $mobileBuyer : null;
    $displayBuyerName = trim((string) data_get($mobileBuyer, 'buyerRegisterName', ''))
        ?: ($sale->customer_name ?: 'Walk-in Customer');
    $displayBuyerTin = trim((string) data_get($mobileBuyer, 'buyerTIN', ''))
        ?: trim((string) $sale->customer_tax_number);
    $mobileBuyerAddress = $mobileBuyer ? implode(', ', array_filter([
        trim((string) data_get($mobileBuyer, 'buyerAddress.houseNo', '')),
        trim((string) data_get($mobileBuyer, 'buyerAddress.street', '')),
        trim((string) data_get($mobileBuyer, 'buyerAddress.city', '')),
        trim((string) data_get($mobileBuyer, 'buyerAddress.province', '')),
    ], static fn (string $part): bool => $part !== '')) : '';
    $fiscalDevice = $fiscalDevice ?? null;
    $qrImageDataUri = $qrImageDataUri ?? null;

    $isFiscalised = (bool) ($sale->is_fiscalised ?? false)
        || in_array(strtolower((string) ($sale->fiscal_status ?? '')), ['success', 'submitted'], true)
        || !empty($zimraReceipt);
    $hasFiscalPresentation = $isFiscalised || !empty($mobileFiscalReceipt);

    $receiptCounter = $zimraReceipt->receipt_counter ?? $sale->receipt_counter ?? null;
    $receiptGlobalNo = $zimraReceipt->receipt_global_no ?? $sale->receipt_global_no ?? null;
    $fiscalDayNo = $zimraReceipt->fiscal_day_no ?? $mobileFiscalReceipt->fiscal_day_no ?? optional($sale->fiscalDay ?? null)->fiscal_day_no ?? null;

    $deviceId = $zimraReceipt->device_id
        ?? $mobileFiscalReceipt->device_id
        ?? $fiscalDevice->device_id
        ?? $sale->device_id
        ?? null;

    $deviceSerial = $fiscalDevice->device_serial_no
        ?? $mobileFiscalReceipt?->device?->device_serial_no
        ?? data_get($zimraReceipt, 'response.deviceSerialNo')
        ?? null;

    $taxpayerName = $fiscalDevice->taxpayer_name
        ?? $company->company_name
        ?? config('app.name');

    $taxpayerTin = $fiscalDevice->taxpayer_tin
        ?? $company->tin
        ?? $company->taxpayer_tin
        ?? $company->tax_number
        ?? null;

    $vatNumber = $fiscalDevice->vat_number
        ?? $company->vat_number
        ?? $company->vat_no
        ?? $company->vat
        ?? null;

    $verificationCode = $zimraReceipt->qr_code_data
        ?? data_get($sale->fiscal_response, 'qr_code.receiptQrData')
        ?? null;

    $manualVerifyUrl = $zimraReceipt->qr_url
        ?? $mobileFiscalReceipt->qr_string
        ?? $sale->qr_url
        ?? optional($fiscalDevice)->qr_url
        ?? null;

    $receiptType = $zimraReceipt->receipt_type
        ?? data_get($sale->fiscal_response, 'receipt.receiptType')
        ?? data_get($sale->metadata, 'fdms_receipt_type')
        ?? ($sale->sale_type === 'sale_cancel' ? 'CreditNote' : 'FiscalInvoice');

    $isCreditNote = in_array(strtolower((string) $receiptType), [
        'creditnote',
        'credit_note',
        'credit-note',
    ], true) || $sale->sale_type === 'sale_cancel';

    $originalZimraReceipt = null;
    $originalSale = null;

    if ($isCreditNote && !empty($zimraReceipt?->original_zimra_receipt_id) && class_exists(\Modules\ZimraIntegration\Models\ZimraReceipt::class)) {
        $originalZimraReceipt = \Modules\ZimraIntegration\Models\ZimraReceipt::query()
            ->where('id', $zimraReceipt->original_zimra_receipt_id)
            ->first();
    }

    if ($isCreditNote && !empty($zimraReceipt?->original_sales_transaction_id) && class_exists(\Modules\Sales\Models\SalesTransaction::class)) {
        $originalSale = \Modules\Sales\Models\SalesTransaction::query()
            ->where('id', $zimraReceipt->original_sales_transaction_id)
            ->first();
    }

    if ($isCreditNote && !$originalSale && !empty(data_get($sale->metadata, 'original_sale_id')) && class_exists(\Modules\Sales\Models\SalesTransaction::class)) {
        $originalSale = \Modules\Sales\Models\SalesTransaction::query()
            ->where('id', data_get($sale->metadata, 'original_sale_id'))
            ->first();
    }

    $documentTitle = $isCreditNote ? 'Credit Note' : ($isFiscalised ? 'Fiscal Tax Invoice' : ($mobileFiscalReceipt ? 'Locally Signed Fiscal Invoice' : 'Sales Receipt'));

    $customerReferenceNo = $sale->invoice_number
        ?? $sale->receipt_number
        ?? $sale->id;

    $originalCustomerReferenceNo = $originalSale->invoice_number
        ?? data_get($sale->metadata, 'original_invoice_number')
        ?? $originalZimraReceipt->invoice_no
        ?? null;

    $originalInvoiceNo = $originalZimraReceipt->receipt_counter
        ?? $originalZimraReceipt->receipt_global_no
        ?? $originalSale->receipt_counter
        ?? $originalSale->receipt_global_no
        ?? $originalSale->invoice_number
        ?? $originalZimraReceipt->invoice_no
        ?? null;

    $originalFiscalDayNo = $originalZimraReceipt->fiscal_day_no
        ?? $originalSale->fiscal_day_no
        ?? null;

    $originalDeviceSerial = optional($fiscalDevice)->device_serial_no ?? null;

    $originalDate = $originalSale
        ? \Carbon\Carbon::parse($originalSale->transaction_date ?? $originalSale->created_at)->format('d/m/Y H:i')
        : ($originalZimraReceipt ? \Carbon\Carbon::parse($originalZimraReceipt->created_at)->format('d/m/Y H:i') : null);

    $receiptTaxes = $sale->receiptLines
        ->flatMap(fn ($line) => $line->taxes ?? collect())
        ->groupBy(function ($tax) {
            return ($tax->tax_code ?? 'TAX') . '|' . number_format((float) ($tax->tax_percent ?? 0), 2);
        })
        ->map(function ($rows) {
            $first = $rows->first();

            return [
                'tax_code' => $first->tax_code ?? 'TAX',
                'tax_percent' => (float) ($first->tax_percent ?? 0),
                'tax_amount' => $rows->sum(fn ($row) => (float) ($row->tax_amount ?? 0)),
                'sales_amount_with_tax' => $rows->sum(fn ($row) => (float) ($row->sales_amount_with_tax ?? 0)),
                'is_inclusive' => (bool) ($first->is_inclusive ?? false),
                'obliged_to_zimra' => (bool) ($first->obliged_to_zimra ?? false),
            ];
        })
        ->values();

    $itemCount = $sale->receiptLines->sum(fn ($line) => abs((float) $line->line_quantity));
@endphp

    <!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">

    <style>
        @page {
            margin: 0;
            size: {{ $is58 ? '58mm auto' : ($isA5 ? 'A5' : ($isA4 ? 'A4' : '80mm auto')) }};
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            min-width: 100%;
            background: #ffffff;
            color: #000000;
            font-family: {!! $receiptFontFamily !!};
            font-size: {{ $bodyFontSize }};
            line-height: 1.32;
            font-weight: 700;
        }

        body {
            text-align: center;
        }

        .receipt {
            display: inline-block;
            width: {{ $receiptWidth }};
            max-width: {{ $receiptWidth }};
            margin: 0 auto;
            padding: {{ $receiptPadding }};
            overflow: hidden;
            background: #ffffff;
            text-align: left;
        }

        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 800; }
        .heavy { font-weight: 900; }

        .muted {
            color: #000000;
            font-weight: 700;
        }

        .tiny {
            font-size: {{ $tinyFontSize }};
            line-height: 1.28;
        }

        .receipt-title {
            border: 1.5px solid #000000;
            text-align: center;
            font-size: {{ $titleFontSize }};
            font-weight: 900;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 3px 2px;
            margin-top: 0;
            margin-bottom: 4px;
        }

        .fiscal-title {
            text-align: center;
            font-size: {{ $titleFontSize }};
            font-weight: 900;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 5px 2px;
            margin: 5px 0;
            border-top: 1px dashed #000000;
            border-bottom: 1px dashed #000000;
        }

        .receipt-card {
            border: 1.2px solid #000000;
            padding: 4px 5px;
            margin-bottom: 5px;
        }

        .order-box {
            border: 2px solid #000000;
            padding: 4px 3px;
            margin: 5px 0;
            text-align: center;
            page-break-inside: avoid;
        }

        .order-label {
            font-size: {{ $tinyFontSize }};
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .order-number {
            font-size: {{ $is58 ? '18px' : '24px' }};
            font-weight: 900;
            letter-spacing: 1px;
            line-height: 1.1;
        }

        .brand-name {
            font-size: {{ $brandFontSize }};
            font-weight: 900;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            word-break: break-word;
            overflow-wrap: anywhere;
            line-height: 1.16;
        }

        .branch-name {
            font-size: {{ $branchFontSize }};
            font-weight: 900;
            text-transform: uppercase;
            margin-top: 3px;
            word-break: break-word;
            overflow-wrap: anywhere;
            line-height: 1.24;
        }

        .section-title {
            border-top: 1.2px solid #000000;
            border-bottom: 1.2px solid #000000;
            padding: 3px 2px;
            margin: 6px 0 5px 0;
            font-weight: 900;
            text-transform: uppercase;
            text-align: center;
            color: #000000;
            background: #ffffff;
        }

        .dash {
            border-top: 1px dashed #000000;
            margin: 5px 0;
        }

        .kv {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 4px;
        }

        .kv td {
            padding: 2px 0;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: anywhere;
            color: #000000;
        }

        .kv .k {
            width: 38%;
            font-weight: 900;
            text-transform: uppercase;
        }

        .kv .v {
            width: 62%;
            text-align: right;
            font-weight: 800;
        }

        .receipt-barcode-wrap {
            margin: 6px 0 5px 0;
            padding: 5px 2px;
            text-align: center;
            page-break-inside: avoid;
            border-top: 1px dashed #000000;
            border-bottom: 1px dashed #000000;
        }

        .receipt-barcode-img {
            display: block;
            width: 100%;
            max-width: 100%;
            height: {{ $barcodeHeight }}px;
            margin: 0 auto;
        }

        .receipt-barcode-text {
            margin-top: 3px;
            font-size: {{ $tinyFontSize }};
            font-weight: 900;
            letter-spacing: 0.3px;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .receipt-barcode-error {
            font-size: {{ $tinyFontSize }};
            font-weight: 900;
            color: #000000;
        }

        .item {
            margin-bottom: 5px;
            page-break-inside: avoid;
        }

        .item-name {
            font-weight: 900;
            text-transform: uppercase;
            word-break: break-word;
            overflow-wrap: anywhere;
            margin-bottom: 3px;
            color: #000000;
            line-height: 1.28;
        }

        .line-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .line-table td {
            padding: 2px 0;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: anywhere;
            color: #000000;
        }

        .line-left {
            width: 58%;
            font-weight: 800;
        }

        .line-right {
            width: 42%;
            text-align: right;
            font-weight: 900;
        }

        .tax-mark {
            display: inline-block;
            min-width: 16px;
            text-align: right;
            font-weight: 900;
        }

        .totals-box,
        .payment-box,
        .fiscal-box {
            border: 1.2px solid #000000;
            padding: 6px 5px;
            margin-top: 6px;
            page-break-inside: avoid;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .totals-table td {
            padding: 3px 0;
            word-wrap: break-word;
            overflow-wrap: anywhere;
            color: #000000;
        }

        .totals-label {
            width: 50%;
            font-weight: 900;
            text-transform: uppercase;
        }

        .totals-value {
            width: 50%;
            text-align: right;
            font-weight: 900;
        }

        .grand-total {
            border-top: 1.2px solid #000000;
            border-bottom: 1.2px solid #000000;
        }

        .grand-total td {
            padding: 6px 0;
            font-size: {{ $totalFontSize }};
            font-weight: 900;
        }

        .qr-wrap {
            text-align: center;
            margin-top: 9px;
            page-break-inside: avoid;
        }

        .qr-img {
            width: {{ $qrSize }};
            height: {{ $qrSize }};
            display: block;
            margin: 0 auto 4px auto;
        }

        .verify-code {
            font-size: {{ $tinyFontSize }};
            line-height: 1.4;
            font-weight: 900;
            letter-spacing: 1px;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .qr-string {
            font-size: {{ $tinyFontSize }};
            line-height: 1.25;
            word-break: break-all;
            overflow-wrap: anywhere;
            margin-top: 4px;
        }

        .footer {
            margin-top: 7px;
            text-align: center;
            font-weight: 900;
            word-break: break-word;
            overflow-wrap: anywhere;
            line-height: 1.28;
        }

        .receipt-number-footer {
            text-align: center;
            word-break: break-word;
            overflow-wrap: anywhere;
            font-size: {{ $tinyFontSize }};
            font-weight: 900;
            line-height: 1.25;
        }

        .powered {
            text-align: center;
            margin-top: 7px;
            color: #000000;
            font-size: {{ $tinyFontSize }};
            font-weight: 800;
        }

        .no-print-gap {
            height: 0.8mm;
        }
    </style>
</head>

<body>
<div class="receipt">
    <div class="receipt-title">
        {{ $documentTitle }}
    </div>

    <div class="receipt-card center">
        <div class="brand-name">
            {{ $company->company_name ?? $taxpayerName ?? config('app.name') }}
        </div>

        @if(!empty($taxpayerTin))
            <div class="muted tiny">
                TIN: {{ $taxpayerTin }}
            </div>
        @endif

        @if(!empty($vatNumber))
            <div class="muted tiny">
                VAT No: {{ $vatNumber }}
            </div>
        @endif

        <div class="branch-name">
            {{ $branch->branch_name ?? $branch->name ?? 'Branch' }}
        </div>

        @if(!empty($branch->branch_address))
            <div class="muted tiny">
                {{ $branch->branch_address }}
            </div>
        @endif

        @if(!empty($branch->branch_city) || !empty($branch->branch_country))
            <div class="muted tiny">
                {{ trim(($branch->branch_city ?? '') . ' ' . ($branch->branch_country ?? '')) }}
            </div>
        @endif
    </div>

    @if($orderNumber)
        <div class="order-box">
            <div class="order-label">Order Number</div>
            <div class="order-number">{{ $orderNumber }}</div>
        </div>
    @endif

    <table class="kv">
        <tr>
            <td class="k">Invoice No</td>
            <td class="v">{{ $isCreditNote ? ($receiptCounter ?: '-') : $sale->invoice_number }}</td>
        </tr>

{{--        @if($orderNumber)--}}
{{--            <tr>--}}
{{--                <td class="k">Order No</td>--}}
{{--                <td class="v">{{ $orderNumber }}</td>--}}
{{--            </tr>--}}
{{--        @endif--}}

{{--        @if($orderBusinessDate)--}}
{{--            <tr>--}}
{{--                <td class="k">Business Date</td>--}}
{{--                <td class="v">{{ \Carbon\Carbon::parse($orderBusinessDate)->format('d/m/Y') }}</td>--}}
{{--            </tr>--}}
{{--        @endif--}}

        @if($isCreditNote)
            <tr>
                <td class="k">Fiscal Day No</td>
                <td class="v">{{ $fiscalDayNo ?: '-' }}</td>
            </tr>

            <tr>
                <td class="k">Customer Ref</td>
                <td class="v">{{ $customerReferenceNo }}</td>
            </tr>

            <tr>
                <td class="k">Device Serial</td>
                <td class="v">{{ $deviceSerial ?: '-' }}</td>
            </tr>

            <tr>
                <td class="k">Device ID</td>
                <td class="v">{{ $deviceId ?: '-' }}</td>
            </tr>
        @endif

        <tr>
            <td class="k">Date</td>
            <td class="v">
                {{ \Carbon\Carbon::parse($sale->transaction_date ?? $sale->created_at)->format('d/m/Y H:i') }}
            </td>
        </tr>

        <tr>
            <td class="k">Currency</td>
            <td class="v">{{ strtoupper($currency) }}</td>
        </tr>

        <tr>
            <td class="k">Till</td>
            <td class="v">
                {{ $tillCode ?: '-' }}{{ $tillName ? ' - ' . $tillName : '' }}
            </td>
        </tr>

        <tr>
            <td class="k">Cashier</td>
            <td class="v">{{ $cashierName }}</td>
        </tr>

        <tr>
            <td class="k">{{ $mobileBuyer ? 'Buyer' : 'Customer' }}</td>
            <td class="v">{{ $displayBuyerName }}</td>
        </tr>

        @if($displayBuyerTin !== '')
            <tr>
                <td class="k">{{ $mobileBuyer ? 'Buyer TIN' : 'Cust TIN' }}</td>
                <td class="v">{{ $displayBuyerTin }}</td>
            </tr>
        @endif
        @if($mobileBuyer && filled(data_get($mobileBuyer, 'buyerTradeName')))
            <tr>
                <td class="k">Trade Name</td>
                <td class="v">{{ data_get($mobileBuyer, 'buyerTradeName') }}</td>
            </tr>
        @endif
        @if($mobileBuyer && filled(data_get($mobileBuyer, 'VATNumber')))
            <tr>
                <td class="k">Buyer VAT</td>
                <td class="v">{{ data_get($mobileBuyer, 'VATNumber') }}</td>
            </tr>
        @endif
        @if($mobileBuyerAddress !== '')
            <tr>
                <td class="k">Buyer Address</td>
                <td class="v">{{ $mobileBuyerAddress }}</td>
            </tr>
        @endif
        @if($mobileBuyer && filled(data_get($mobileBuyer, 'buyerContacts.phoneNo')))
            <tr>
                <td class="k">Buyer Phone</td>
                <td class="v">{{ data_get($mobileBuyer, 'buyerContacts.phoneNo') }}</td>
            </tr>
        @endif
        @if($mobileBuyer && filled(data_get($mobileBuyer, 'buyerContacts.email')))
            <tr>
                <td class="k">Buyer Email</td>
                <td class="v">{{ data_get($mobileBuyer, 'buyerContacts.email') }}</td>
            </tr>
        @endif
    </table>

    @if($barcodeEnabled)
        <div class="receipt-barcode-wrap">
            @if($receiptBarcodeImageDataUri)
                <img
                    class="receipt-barcode-img"
                    src="{{ $receiptBarcodeImageDataUri }}"
                    alt="Receipt Barcode"
                >
            @else
                <div class="receipt-barcode-error">
                    BARCODE NOT AVAILABLE
                </div>

                @if(config('app.debug') && !empty($receiptBarcodeError))
                    <div class="receipt-barcode-error tiny">
                        {{ $receiptBarcodeError }}
                    </div>
                @endif
            @endif

            @if($barcodeShowText && !empty($receiptBarcodeNumber))
                <div class="receipt-barcode-text">
                    {{ $receiptBarcodeNumber }}
                </div>
            @endif
        </div>
    @endif

    @if($isCreditNote)
        <div class="section-title">
            Credited Invoice
        </div>

        <table class="kv">
            <tr>
                <td class="k">Device Serial</td>
                <td class="v">{{ $originalDeviceSerial ?: $deviceSerial ?: '-' }}</td>
            </tr>

            <tr>
                <td class="k">Invoice No</td>
                <td class="v">{{ $originalInvoiceNo ?: '-' }}</td>
            </tr>

            @if(!empty($originalFiscalDayNo))
                <tr>
                    <td class="k">Fiscal Day No</td>
                    <td class="v">{{ $originalFiscalDayNo }}</td>
                </tr>
            @endif

            <tr>
                <td class="k">Date</td>
                <td class="v">{{ $originalDate ?: '-' }}</td>
            </tr>

            <tr>
                <td class="k">Customer Ref</td>
                <td class="v">{{ $originalCustomerReferenceNo ?: '-' }}</td>
            </tr>
        </table>
    @endif

    @if($hasFiscalPresentation)
        <div class="fiscal-box">
            <div class="center heavy">
                FISCAL INFORMATION
            </div>

            @if($mobileFiscalReceipt && !$isFiscalised)
                <div class="center tiny">Locally signed by this registered device; FDMS confirmation is pending or device-reported, not independently verified by Central.</div>
            @endif

            <table class="kv">
                <tr>
                    <td class="k">Device ID</td>
                    <td class="v">{{ $deviceId ?: '-' }}</td>
                </tr>

                <tr>
                    <td class="k">Device Serial</td>
                    <td class="v">{{ $deviceSerial ?: '-' }}</td>
                </tr>

                <tr>
                    <td class="k">Receipt No</td>
                    <td class="v">{{ $receiptCounter ?: '-' }}</td>
                </tr>

                <tr>
                    <td class="k">Global No</td>
                    <td class="v">{{ $receiptGlobalNo ?: '-' }}</td>
                </tr>

                <tr>
                    <td class="k">Fiscal Day</td>
                    <td class="v">{{ $fiscalDayNo ?: '-' }}</td>
                </tr>

                @if(!empty($vatNumber))
                    <tr>
                        <td class="k">VAT No</td>
                        <td class="v">{{ $vatNumber }}</td>
                    </tr>
                @endif

                <tr>
                    <td class="k">Currency</td>
                    <td class="v">{{ strtoupper($currency) }}</td>
                </tr>
            </table>
        </div>
    @endif

    <div class="section-title">
        Items
    </div>

    @foreach($sale->receiptLines->sortBy('line_no') as $line)
        @php
            $lineTaxes = $line->taxes ?? collect();
            $lineTaxMark = $lineTaxes->where('obliged_to_zimra', true)->first()?->tax_code
                ?: $lineTaxes->first()?->tax_code
                ?: '';
            $customerModifiers = collect(data_get($line->metadata, 'modifiers', data_get($line->metadata, 'kitchen.modifiers', [])))
                ->filter(fn ($modifier) => ($modifier['print_on_customer_receipt'] ?? true) === true)
                ->values();

            $customerPrepMessages = collect(data_get($line->metadata, 'prep_messages', data_get($line->metadata, 'kitchen.prep_messages', [])))
                ->map(fn ($message) => is_array($message) ? ($message['message'] ?? '') : $message)
                ->filter(fn ($message) => trim((string) $message) !== '')
                ->values();

            $customerPrepNote = data_get($line->metadata, 'prep_note', data_get($line->metadata, 'kitchen.prep_note'));
        @endphp

        <div class="item">
            <div class="item-name">
                {{ $line->line_name }}
            </div>

            @foreach($customerModifiers as $modifier)
                <div class="tiny heavy" style="padding-left: 6px; text-transform: uppercase;">
                    + {{ $modifier['name'] ?? 'Modifier' }} x {{ number_format((float) ($modifier['quantity'] ?? 1), 0) }}
                </div>
            @endforeach

            @foreach($customerPrepMessages as $message)
                <div class="tiny heavy" style="padding-left: 6px; text-transform: uppercase;">
                    * {{ $message }}
                </div>
            @endforeach

            @if(!empty($customerPrepNote))
                <div class="tiny heavy" style="padding-left: 6px; text-transform: uppercase;">
                    NOTE: {{ $customerPrepNote }}
                </div>
            @endif

            <table class="line-table">
                <tr>
                    <td class="line-left">
                        {{ $formatQty($line) }} x {{ $formatMoney($line->line_price) }}
                    </td>

                    <td class="line-right">
                        {{ $formatMoney($line->line_total) }}
                        @if($lineTaxMark)
                            <span class="tax-mark">{{ strtoupper(substr($lineTaxMark, 0, 2)) }}</span>
                        @endif
                    </td>
                </tr>

                @if((float) $line->line_discount > 0)
                    <tr>
                        <td class="line-left">
                            Discount
                        </td>

                        <td class="line-right">
                            -{{ $formatMoney($line->line_discount) }}
                        </td>
                    </tr>
                @endif
            </table>
        </div>

        @if(!$loop->last)
            <div class="dash"></div>
        @endif
    @endforeach

    <div class="totals-box">
        <table class="totals-table">
            <tr>
                <td class="totals-label">Subtotal</td>
                <td class="totals-value">{{ $formatMoney($sale->subtotal_amount) }}</td>
            </tr>

            <tr>
                <td class="totals-label">Discount</td>
                <td class="totals-value">-{{ $formatMoney($sale->discount_amount) }}</td>
            </tr>

            <tr>
                <td class="totals-label">Tax</td>
                <td class="totals-value">{{ $formatMoney($sale->tax_amount) }}</td>
            </tr>

            <tr class="grand-total">
                <td class="totals-label">Total</td>
                <td class="totals-value">{{ $formatMoney($sale->total_amount) }}</td>
            </tr>
        </table>
    </div>

    <div class="payment-box">
        <table class="totals-table">
            <tr>
                <td class="totals-label">Payment</td>
                <td class="totals-value">{{ strtoupper($sale->payment_method ?? '-') }}</td>
            </tr>

            <tr>
                <td class="totals-label">Tendered</td>
                <td class="totals-value">{{ $formatMoney($sale->amount_tendered) }}</td>
            </tr>

            <tr>
                <td class="totals-label">Change Returned</td>
                <td class="totals-value">{{ $formatMoney($changeValue) }}</td>
            </tr>

            @if($hasCreditedChange)
                <tr>
                    <td class="totals-label">Change Credited</td>
                    <td class="totals-value">
                        {{ strtoupper($changeCreditedCurrency) }} {{ number_format($changeCreditedAmount, 2) }}
                    </td>
                </tr>
                <tr>
                    <td class="totals-label">Account</td>
                    <td class="totals-value">{{ $changeCreditAccountNumber ?: '-' }}</td>
                </tr>
                <tr>
                    <td class="totals-label">Account Payment</td>
                    <td class="totals-value">{{ $changeCreditPaymentNumber ?: '-' }}</td>
                </tr>
            @endif
        </table>
    </div>

    <div class="payment-box">
        <div class="center heavy">
            TAX SUMMARY
        </div>

        <table class="totals-table">
            @forelse($receiptTaxes as $taxRow)
                <tr>
                    <td class="totals-label">
                        {{ strtoupper($taxRow['tax_code']) }}
                        {{ number_format((float) $taxRow['tax_percent'], 2) }}%
                        {{ $taxRow['is_inclusive'] ? 'IN' : 'EX' }}
                    </td>
                    <td class="totals-value">
                        {{ $currency }} {{ $formatPlainMoney($taxRow['tax_amount']) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="totals-label">Tax</td>
                    <td class="totals-value">{{ $formatMoney($sale->tax_amount) }}</td>
                </tr>
            @endforelse
        </table>
    </div>

    @if($sale->base_currency_code && $sale->base_currency_code !== $sale->currency_code)
        <div class="payment-box">
            <div class="center heavy">
                BASE CURRENCY
            </div>

            <table class="totals-table">
                <tr>
                    <td class="totals-label">Currency</td>
                    <td class="totals-value">{{ $sale->base_currency_code }}</td>
                </tr>

                <tr>
                    <td class="totals-label">Rate</td>
                    <td class="totals-value">{{ number_format((float) $sale->exchange_rate, 6) }}</td>
                </tr>

                <tr>
                    <td class="totals-label">Base Total</td>
                    <td class="totals-value">
                        {{ $sale->base_currency_code }} {{ number_format((float) $sale->base_total_amount, 2) }}
                    </td>
                </tr>
            </table>
        </div>
    @endif

    @if($hasFiscalPresentation)
        <div class="fiscal-title">
            Fiscal Verification
        </div>

        <table class="kv">
            <tr>
                <td class="k">Items</td>
                <td class="v">{{ number_format((float) $itemCount, 3) }}</td>
            </tr>
        </table>

        @if($qrImageDataUri)
            <div class="qr-wrap">
                <img class="qr-img" src="{{ $qrImageDataUri }}" alt="Fiscal QR Code">

                @if($verificationCode)
                    <div class="tiny">Verification Code:</div>
                    <div class="verify-code">{{ strtoupper($verificationCode) }}</div>
                @endif

                <div class="receipt-number-footer" style="margin-top: 5px;">
                    Invoice No: {{ $sale->invoice_number }}
                </div>

                @if($manualVerifyUrl)
                    <div class="tiny" style="margin-top: 5px;">
                        Verify manually at
                    </div>
                    <div class="qr-string">{{ $manualVerifyUrl }}</div>
                @endif
            </div>
        @else
            <div class="center tiny heavy">
                QR CODE NOT AVAILABLE
            </div>
        @endif
    @endif

    <div class="dash"></div>

    <div class="footer">
        {{ $settings['footer_message'] ?? $settings['receipt_footer_message'] ?? 'Thank you for shopping with us.' }}
    </div>

    <div class="powered">
        Powered by ServeNest POS
    </div>

    <div class="no-print-gap"></div>
</div>
</body>
</html>
