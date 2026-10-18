<?php

namespace Tests\Unit;

use App\Http\Controllers\SyncNodeManagementController;
use App\Http\Controllers\SyncPairController;
use App\Http\Controllers\SyncProtocolController;
use App\Services\Enterprise\EnterpriseHierarchyService;
use App\Services\Sync\SyncTransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Company\Models\Company;
use Tests\TestCase;

class LocalHubProtocolBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.local_hub', true);
    }

    public function test_local_hub_still_dispatches_web_edge_pairing_to_signed_sync_server(): void
    {
        $request = Request::create('/api/pos/sync/pair', 'POST', [
            'company_code' => 'CMP-DEMO',
            'pairing_code' => 'ONE-TIME.CODE',
            'target_platform' => 'edge',
        ]);
        $expected = response()->json(['web_edge_pairing' => true]);
        $cloud = $this->createMock(SyncProtocolController::class);
        $cloud->expects($this->once())->method('pair')->with($request)->willReturn($expected);
        $transport = $this->createMock(SyncTransportService::class);
        $hierarchy = $this->createMock(EnterpriseHierarchyService::class);

        $this->assertSame($expected, (new SyncPairController($cloud, $transport, $hierarchy))($request));
    }

    public function test_local_hub_refuses_android_pairing_before_consuming_a_grant(): void
    {
        $request = Request::create('/api/pos/sync/pair', 'POST', [
            'company_code' => 'CMP-DEMO',
            'pairing_code' => 'ONE-TIME.CODE',
            'target_platform' => 'android',
            'license_contract_version' => 1,
            'supported_license_key_ids' => ['primary'],
            'supported_license_algorithms' => ['openssl-sha256'],
            'supported_license_key_fingerprints' => ['primary' => str_repeat('a', 64)],
            'device_fingerprint' => str_repeat('device', 4),
        ]);

        $response = app(SyncProtocolController::class)->pair($request);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('SYNC_ANDROID_LOCAL_HUB_UNAVAILABLE', $response->getData(true)['code']);
    }

    public function test_local_hub_refuses_android_grant_creation_and_mobile_license_delivery(): void
    {
        app()->instance('company', new Company);
        $grant = app(SyncNodeManagementController::class)->createPairingCode(Request::create(
            '/api/pos/sync/pairing-codes',
            'POST',
            [
                'branch_id' => '019fa100-0000-7000-8000-000000000002',
                'target_platform' => 'android',
            ]
        ));

        $this->assertSame(409, $grant->getStatusCode());
        $this->assertSame('SYNC_ANDROID_LOCAL_HUB_UNAVAILABLE', $grant->getData(true)['code']);

        $license = app(SyncProtocolController::class)->mobileLicense(Request::create(
            '/api/pos/sync/license',
            'GET'
        ));
        $this->assertInstanceOf(JsonResponse::class, $license);
        $this->assertSame(409, $license->getStatusCode());
        $this->assertSame('SYNC_ANDROID_LOCAL_HUB_UNAVAILABLE', $license->getData(true)['code']);
    }
}
