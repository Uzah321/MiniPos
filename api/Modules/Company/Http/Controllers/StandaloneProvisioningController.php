<?php

namespace Modules\Company\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Provisioning\StandaloneProvisioningPackageService;
use Modules\Company\Models\Company;
use Symfony\Component\HttpFoundation\Response;

class StandaloneProvisioningController extends Controller
{
    public function store(
        Company $company,
        StandaloneProvisioningPackageService $packages
    ): Response {
        $package = $packages->generate($company);
        $companyCode = preg_replace(
            '/[^A-Za-z0-9_-]+/',
            '-',
            strtoupper((string) $company->company_code)
        ) ?: 'COMPANY';

        return response($packages->serialize($package), 201, [
            'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'attachment; filename="'.$companyCode.'.salexa-company"',
            'Content-Type' => 'application/vnd.salexa.standalone+json',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
