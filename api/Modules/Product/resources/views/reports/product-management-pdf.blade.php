<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px 24px 32px; }
        body { font-family: DejaVu Sans, sans-serif; margin: 0; color: #183247; font-size: 9px; }
        .header { background: #002b49; color: #fff; padding: 16px 18px; }
        .header h1 { margin: 0 0 4px; font-size: 18px; letter-spacing: .5px; }
        .header p { margin: 0; font-size: 9px; color: #dbeafe; }
        .meta { margin: 10px 0; width: 100%; border-collapse: collapse; }
        .meta td { background: #f1f5f9; padding: 7px 9px; border-left: 3px solid #009688; }
        .summary { margin: 0 0 10px; width: 100%; border-collapse: separate; border-spacing: 5px 0; }
        .summary td { background: #e6f7f5; padding: 7px; text-align: center; color: #064e3b; }
        .summary strong { display: block; font-size: 14px; color: #002b49; }
        table.products { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .products th { background: #009688; color: #fff; padding: 6px 4px; font-size: 7px; text-transform: uppercase; }
        .products td { border: 1px solid #dbe3ea; padding: 5px 4px; vertical-align: top; word-wrap: break-word; }
        .products tr:nth-child(even) td { background: #f8fafc; }
        .number { text-align: right; }
        .center { text-align: center; }
        .product { font-weight: bold; color: #002b49; }
        .footer { position: fixed; bottom: -22px; left: 0; right: 0; color: #64748b; font-size: 7px; }
        .page-number:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="header">
        <h1>PRODUCT MANAGEMENT REPORT</h1>
        <p>{{ $report['company_name'] }} &nbsp;•&nbsp; Generated {{ $report['generated_at'] }}</p>
    </div>

    <table class="meta"><tr><td><strong>Applied filters:</strong> {{ $report['filter_summary'] }}</td></tr></table>
    <table class="summary"><tr>
        <td><strong>{{ number_format($report['record_count']) }}</strong>Products</td>
        <td><strong>{{ number_format($report['active_count']) }}</strong>Active</td>
        <td><strong>{{ number_format($report['stock_tracked_count']) }}</strong>Stock tracked</td>
        <td><strong>{{ number_format($report['low_stock_count']) }}</strong>At/below reorder</td>
        <td><strong>{{ number_format($report['total_on_hand'], 4) }}</strong>Total on hand</td>
    </tr></table>

    <table class="products">
        <thead><tr>
            <th style="width:3%">#</th><th style="width:15%">Product</th><th style="width:8%">Type</th>
            <th style="width:10%">Scope</th><th style="width:8%">Code / SKU</th><th style="width:9%">Barcode</th>
            <th style="width:11%">Category</th><th style="width:9%">UOM</th><th style="width:8%">Price</th>
            <th style="width:7%">On hand</th><th style="width:7%">Available</th><th style="width:7%">Reorder</th>
            <th style="width:6%">Status</th>
        </tr></thead>
        <tbody>
        @forelse ($report['rows'] as $row)
            <tr>
                <td class="center">{{ $row['line'] }}</td>
                <td class="product">{{ $row['name'] }}</td>
                <td>{{ $row['type'] }}</td><td>{{ $row['scope'] }}</td>
                <td>{{ $row['code'] }}<br>{{ $row['sku'] }}</td><td>{{ $row['barcode'] }}</td>
                <td>{{ $row['category'] }}</td><td>{{ $row['uom'] }}</td>
                <td class="number">{{ $row['currency'] }} {{ number_format($row['selling_price'], 4) }}</td>
                <td class="number">{{ number_format($row['on_hand'], 4) }}</td>
                <td class="number">{{ number_format($row['available'], 4) }}</td>
                <td class="number">{{ number_format($row['reorder_level'], 4) }}</td>
                <td class="center">{{ $row['status'] }}</td>
            </tr>
        @empty
            <tr><td colspan="13" class="center">No products matched the selected filters.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="footer">ServeNest POS Enterprise • Filtered backend report <span style="float:right">Page <span class="page-number"></span></span></div>
</body>
</html>
