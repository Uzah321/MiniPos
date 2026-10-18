<?php

namespace Tests\Unit;

use App\Services\Reports\EnterpriseReportDownloadService;
use Tests\TestCase;

class EnterpriseReportDownloadServiceTest extends TestCase
{
    public function test_receipt_width_pdf_can_be_downloaded_or_streamed_from_the_backend(): void
    {
        $service = app(EnterpriseReportDownloadService::class);
        $sections = [[
            'title' => 'Activity',
            'headers' => ['Transaction', 'Amount'],
            'rows' => [['SALE-1', 'USD 10.00']],
        ]];

        $download = $service->downloadPdf(
            'Credit Statement',
            'statement-80mm.pdf',
            $sections,
            null,
            [0, 0, 226.77, 841.89],
            'portrait',
            true
        );
        $preview = $service->downloadPdf(
            'Credit Statement',
            'statement-80mm.pdf',
            $sections,
            null,
            [0, 0, 226.77, 841.89],
            'portrait',
            true,
            false
        );

        $this->assertStringContainsString('attachment;', (string) $download->headers->get('content-disposition'));
        $this->assertStringContainsString('inline;', (string) $preview->headers->get('content-disposition'));
        $this->assertSame('application/pdf', $download->headers->get('content-type'));
        $this->assertSame('application/pdf', $preview->headers->get('content-type'));
    }
}
