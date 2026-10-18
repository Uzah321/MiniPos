<?php

namespace App\Services\Receipt;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Modules\Authentication\Models\GeneralSetting;
use Modules\GeneralSettings\Models\Printer;
use Modules\Sales\Models\SalesTransaction;
use Modules\ZimraIntegration\Models\ZimraReceipt;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class ReceiptService
{
    public function prepareReceipt(SalesTransaction $sale): array
    {
        $sale = $sale->loadMissing([
            'company',
            'branch',
            'till',
            'receiptLines.taxes',
        ]);

        $companyId = $sale->company_id;
        $branchId = $sale->branch_id;
        $tillId = $sale->till_id;

        $settings = $this->resolveReceiptSettings($companyId, $branchId);
        $receiptUrl = $this->generateReceiptPdf($sale, $settings);
        $printer = null;

        if ($settings['auto_print'] === true) {
            $printer = $this->resolveReceiptPrinter($companyId, $branchId, $tillId);
        }

        return [
            'receipt_url' => $receiptUrl,
            'receipt_generated' => $receiptUrl !== null,
//            'receipt_settings' => [
//                'paper_size' => $settings['paper_size'],
//                'footer_message' => $settings['footer_message'],
//                'auto_print' => $settings['auto_print'],
//                'source' => $settings['source'],
//            ],
            'receipt_settings' => $settings,
            'auto_print' => $settings['auto_print'] && $printer !== null,
            'printer' => $printer ? [
                'id' => $printer->id,
                'printer_name' => $printer->printer_name,
                'printer_type' => $printer->printer_type,
                'paper_size' => $printer->paper_size,
                'connection_type' => $printer->connection_type,
                'ip_address' => $printer->ip_address,
                'port' => $printer->port,
            ] : null,
        ];
    }

    public function resolveReceiptPrinter(?string $companyId, ?string $branchId, ?string $tillId): ?Printer
    {
        return Printer::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('till_id', $tillId)
            ->where('target_printing', 'receipt')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->latest()
            ->first();
    }

//    public function resolveReceiptSettings(?string $companyId, ?string $branchId): array
//    {
//        $paperSize = $this->getStringSetting($companyId, $branchId, 'receipt_size', '80mm');
//        $footerMessage = $this->getStringSetting($companyId, $branchId, 'receipt_footer_message', 'Thank you for shopping with us.');
//        $autoPrint = $this->settingIsEnabled($companyId, $branchId, 'receipt_auto_print');
//
//        $source = $this->getSetting($companyId, $branchId, 'receipt_size')
//        || $this->getSetting($companyId, $branchId, 'receipt_footer_message')
//        || $this->getSetting($companyId, $branchId, 'receipt_auto_print')
//            ? 'general_settings.receipt'
//            : 'defaults';
//
//        return [
//            'paper_size' => $paperSize,
//            'footer_message' => $footerMessage,
//            'auto_print' => $autoPrint,
//            'source' => $source,
//        ];
//    }
    public function resolveReceiptSettings(?string $companyId, ?string $branchId): array
    {
        $settings = [
            'paper_size' => $this->getStringSetting($companyId, $branchId, 'receipt_size', '80mm'),
            'receipt_size' => $this->getStringSetting($companyId, $branchId, 'receipt_size', '80mm'),

            'footer_message' => $this->getStringSetting(
                $companyId,
                $branchId,
                'receipt_footer_message',
                'Thank you for shopping with us.'
            ),
            'receipt_footer_message' => $this->getStringSetting(
                $companyId,
                $branchId,
                'receipt_footer_message',
                'Thank you for shopping with us.'
            ),

            'auto_print' => $this->settingIsEnabled($companyId, $branchId, 'receipt_auto_print'),
            'receipt_auto_print' => $this->settingIsEnabled($companyId, $branchId, 'receipt_auto_print'),

            /*
            |--------------------------------------------------------------------------
            | Receipt font settings
            |--------------------------------------------------------------------------
            */
            'receipt_font_family' => $this->getStringSetting(
                $companyId,
                $branchId,
                'receipt_font_family',
                'mono'
            ),

            'receipt_body_font_size' => $this->getStringSetting(
                $companyId,
                $branchId,
                'receipt_body_font_size',
                '12'
            ),

            'receipt_tiny_font_size' => $this->getStringSetting(
                $companyId,
                $branchId,
                'receipt_tiny_font_size',
                '10.5'
            ),

            'receipt_title_font_size' => $this->getStringSetting(
                $companyId,
                $branchId,
                'receipt_title_font_size',
                '14'
            ),

            'receipt_brand_font_size' => $this->getStringSetting(
                $companyId,
                $branchId,
                'receipt_brand_font_size',
                '18'
            ),

            'receipt_branch_font_size' => $this->getStringSetting(
                $companyId,
                $branchId,
                'receipt_branch_font_size',
                '12'
            ),

            'receipt_total_font_size' => $this->getStringSetting(
                $companyId,
                $branchId,
                'receipt_total_font_size',
                '16'
            ),

            /*
            |--------------------------------------------------------------------------
            | Receipt barcode settings
            |--------------------------------------------------------------------------
            */
            'receipt_barcode_enabled' => $this->settingIsEnabled(
                $companyId,
                $branchId,
                'receipt_barcode_enabled',
                true
            ),

            'receipt_barcode_show_text' => $this->settingIsEnabled(
                $companyId,
                $branchId,
                'receipt_barcode_show_text',
                true
            ),

            'receipt_barcode_height' => $this->getStringSetting(
                $companyId,
                $branchId,
                'receipt_barcode_height',
                '38'
            ),

            'receipt_barcode_width_factor' => $this->getStringSetting(
                $companyId,
                $branchId,
                'receipt_barcode_width_factor',
                '1.35'
            ),
        ];

        $settings['source'] = $this->hasAnyReceiptSetting($companyId, $branchId)
            ? 'general_settings.receipt'
            : 'defaults';

        return $settings;
    }
    public function generateReceiptPdf(SalesTransaction $sale, array $settings): string
    {
        $paperSize = strtolower((string) ($settings['paper_size'] ?? '80mm'));
        $zimraReceipt = $this->resolveZimraReceipt($sale);
        $mobileFiscalReceipt = \App\Models\MobileFiscalReceipt::query()
            ->where('company_id', $sale->company_id)
            ->where('sales_transaction_id', $sale->id)
            ->first();
        $fiscalDevice = $this->resolveFiscalDevice($zimraReceipt, $sale);
        $qrImageDataUri = $this->resolveQrImageDataUri($sale, $zimraReceipt);

        $html = View::make('sales::enterprise-receipt', [
            'sale' => $sale,
            'settings' => $settings,
            'zimraReceipt' => $zimraReceipt,
            'mobileFiscalReceipt' => $mobileFiscalReceipt,
            'fiscalDevice' => $fiscalDevice,
            'qrImageDataUri' => $qrImageDataUri,
        ])->render();

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans Mono');
        $options->set('dpi', 96);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper($this->paperSizeToDompdf($paperSize, $sale->receiptLines->count(), $zimraReceipt !== null || $mobileFiscalReceipt !== null), 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();

        $directory = base_path('assets/receipts/' . $sale->company_id . '/' . $sale->branch_id);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $safeInvoice = preg_replace('/[^A-Za-z0-9\-_]/', '_', $sale->invoice_number);
        $filename = $safeInvoice . '.pdf';
        $filePath = $directory . '/' . $filename;
        file_put_contents($filePath, $dompdf->output());

        return url('api/assets/receipts/' . $sale->company_id . '/' . $sale->branch_id . '/' . $filename);
    }

    private function resolveZimraReceipt(SalesTransaction $sale): ?ZimraReceipt
    {
        if (!class_exists(ZimraReceipt::class)) {
            return null;
        }

        return ZimraReceipt::query()
            ->where('sales_transaction_id', $sale->id)
            ->latest()
            ->first();
    }

    private function resolveFiscalDevice(?ZimraReceipt $zimraReceipt, SalesTransaction $sale)
    {
        $deviceId = $zimraReceipt?->fiscal_device_id ?: $sale->fiscal_device_id;

        if (!$deviceId || !class_exists(\Modules\FiscalDevice\Models\FiscalDevice::class)) {
            return null;
        }

        return \Modules\FiscalDevice\Models\FiscalDevice::query()
            ->where('id', $deviceId)
            ->first();
    }

    private function resolveQrImageDataUri(SalesTransaction $sale, ?ZimraReceipt $zimraReceipt): ?string
    {
        $qrString = $zimraReceipt?->qr_string
            ?: $zimraReceipt?->qr_code_data
                ?: $sale->qr_code_data
                    ?: data_get($sale->fiscal_response, 'qr_code.qrString')
                        ?: data_get($sale->metadata, 'qr_code_data');

        if (!$qrString) {
            return null;
        }

        if (str_starts_with((string) $qrString, 'data:image')) {
            return $qrString;
        }

        try {
            $svg = QrCode::format('svg')
                ->size(220)
                ->margin(1)
                ->errorCorrection('H')
                ->generate((string) $qrString);

            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable $e) {
            return null;
        }
    }

//    private function settingIsEnabled(string $companyId, ?string $branchId, string $settingKey): bool
//    {
//        $setting = $this->getSetting($companyId, $branchId, $settingKey);
//        return $setting ? ((int) $setting->is_active === 1) : false;
//    }

    private function hasAnyReceiptSetting(string $companyId, ?string $branchId): bool
    {
        return GeneralSetting::query()
            ->where('company_id', $companyId)
            ->where('module', 'receipt')
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->where('is_active', true)
            ->exists();
    }

    private function settingIsEnabled(
        string $companyId,
        ?string $branchId,
        string $settingKey,
        bool $default = false
    ): bool {
        $setting = $this->getSetting($companyId, $branchId, $settingKey);

        if (!$setting || (int) $setting->is_active !== 1) {
            return $default;
        }

        return filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN);
    }

    private function getStringSetting(string $companyId, ?string $branchId, string $settingKey, string $default = ''): string
    {
        $setting = $this->getSetting($companyId, $branchId, $settingKey);
        if (!$setting || (int) $setting->is_active !== 1 || $setting->setting_value === null || $setting->setting_value === '') {
            return $default;
        }
        return $setting->setting_value;
    }

    private function getSetting(string $companyId, ?string $branchId, string $settingKey): ?GeneralSetting
    {
        if ($branchId) {
            $branchSetting = GeneralSetting::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('setting_key', $settingKey)
                ->where('module', 'receipt')
                ->first();
            if ($branchSetting) {
                return $branchSetting;
            }
        }

        return GeneralSetting::where('company_id', $companyId)
            ->whereNull('branch_id')
            ->where('setting_key', $settingKey)
            ->where('module', 'receipt')
            ->first();
    }

    private function paperSizeToDompdf(string $paperSize, int $lineCount, bool $hasFiscalBlock = false): array|string
    {
        $fiscalExtra = $hasFiscalBlock ? 340 : 0;
        $height = 560 + ($lineCount * 48) + $fiscalExtra;

        if (str_contains($paperSize, '58')) {
            return [0, 0, 164, max($height, 1000)];
        }
        if (str_contains($paperSize, 'a5')) {
            return 'a5';
        }
        if (str_contains($paperSize, 'a4')) {
            return 'a4';
        }
        if (str_contains($paperSize, '90')) {
            return [0, 0, 255, max($height, 1100)];
        }
        if (str_contains($paperSize, '100')) {
            return [0, 0, 284, max($height, 1130)];
        }
        return [0, 0, 238, max($height, 1080)];
    }
}
