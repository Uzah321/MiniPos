<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Services\Auth\SuperAdminService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncDeploymentBoundaryTest extends TestCase
{
    public function test_unpaired_edge_exposes_only_its_safe_pinned_cloud_origin(): void
    {
        $originalMode = config('salexa_sync.deployment_mode');
        $originalCloudUrl = config('salexa_sync.cloud_url');
        $originalAllowUnpinned = config('salexa_sync.allow_unpinned_cloud_url');

        Schema::create('sync_local_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->nullable();
            $table->timestamps();
        });

        try {
            config()->set('salexa_sync.deployment_mode', 'edge');
            config()->set('salexa_sync.cloud_url', 'https://cloud.salexa.test:8443/');
            config()->set('salexa_sync.allow_unpinned_cloud_url', false);

            $this->getJson('/api/pos/sync/status')
                ->assertOk()
                ->assertJsonPath('data.paired', false)
                ->assertJsonPath('data.pairing_required', true)
                ->assertJsonPath('data.cloud_configured', true)
                ->assertJsonPath('data.cloud_url_masked', 'https://cloud.salexa.test:8443')
                ->assertJsonPath('data.cloud_url_locked', true)
                ->assertJsonMissingPath('data.credential')
                ->assertJsonMissingPath('data.local_control_token')
                ->assertJsonMissingPath('data.till_token');

            config()->set('salexa_sync.cloud_url', 'https://user:secret@cloud.salexa.test/private?token=secret');

            $this->getJson('/api/pos/sync/status')
                ->assertOk()
                ->assertJsonPath('data.cloud_configured', false)
                ->assertJsonPath('data.cloud_url_masked', null)
                ->assertJsonPath('data.configuration_error', 'SYNC_CLOUD_URL_NOT_CONFIGURED');
        } finally {
            Schema::dropIfExists('sync_local_states');
            config()->set('salexa_sync.deployment_mode', $originalMode);
            config()->set('salexa_sync.cloud_url', $originalCloudUrl);
            config()->set('salexa_sync.allow_unpinned_cloud_url', $originalAllowUnpinned);
        }
    }

    public function test_edge_accepts_any_registered_till_in_its_branch_and_rejects_other_scopes(): void
    {
        $originalMode = config('salexa_sync.deployment_mode');
        config()->set('salexa_sync.deployment_mode', 'edge');

        $this->createEdgeIdentityTables();

        try {
            $companyId = '00000000-0000-0000-0000-000000000001';
            $branchId = '00000000-0000-0000-0000-000000000002';
            $pairedTillId = '00000000-0000-0000-0000-000000000003';
            $otherTillId = '00000000-0000-0000-0000-000000000004';
            $otherBranchId = '00000000-0000-0000-0000-000000000007';
            $otherCompanyId = '00000000-0000-0000-0000-000000000008';
            $otherTillToken = 'other-till-token';
            $timestamp = now();

            DB::table('companies')->insert([
                [
                    'id' => $companyId,
                    'company_code' => 'CMP-ONE',
                    'company_name' => 'Company One',
                    'status' => 'active',
                    'deleted_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'id' => $otherCompanyId,
                    'company_code' => 'CMP-TWO',
                    'company_name' => 'Company Two',
                    'status' => 'active',
                    'deleted_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ]);
            DB::table('company_branches')->insert([
                [
                    'id' => $branchId,
                    'company_id' => $companyId,
                    'status' => 'active',
                    'deleted_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'id' => $otherBranchId,
                    'company_id' => $companyId,
                    'status' => 'active',
                    'deleted_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ]);
            DB::table('pos_tills')->insert([
                [
                    'id' => $pairedTillId,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'registration_token' => hash('sha256', 'paired-till-token'),
                    'is_registered' => true,
                    'is_active' => true,
                    'deleted_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'id' => $otherTillId,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'registration_token' => hash('sha256', $otherTillToken),
                    'is_registered' => true,
                    'is_active' => true,
                    'deleted_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'id' => '00000000-0000-0000-0000-000000000009',
                    'company_id' => $companyId,
                    'branch_id' => $otherBranchId,
                    'registration_token' => hash('sha256', 'other-branch-token'),
                    'is_registered' => true,
                    'is_active' => true,
                    'deleted_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'id' => '00000000-0000-0000-0000-000000000010',
                    'company_id' => $otherCompanyId,
                    'branch_id' => '00000000-0000-0000-0000-000000000011',
                    'registration_token' => hash('sha256', 'other-company-token'),
                    'is_registered' => true,
                    'is_active' => true,
                    'deleted_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ]);
            DB::table('company_branches')->insert([
                'id' => '00000000-0000-0000-0000-000000000011',
                'company_id' => $otherCompanyId,
                'status' => 'active',
                'deleted_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            DB::table('sync_local_states')->insert([
                'id' => '00000000-0000-0000-0000-000000000005',
                'node_id' => '00000000-0000-0000-0000-000000000006',
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'till_id' => $pairedTillId,
                'status' => 'paired',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $request = Request::create('/api/pos/products', 'GET');
            $request->headers->set('X-Till-Token', $otherTillToken);
            $middleware = new ResolveCompanyFromDomain(new SuperAdminService);
            $response = $middleware->handle(
                $request,
                fn () => response()->json(['ok' => true])
            );

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($otherTillId, (string) app('till')->id);

            foreach (['other-branch-token', 'other-company-token'] as $rejectedToken) {
                $rejected = Request::create('/api/pos/products', 'GET');
                $rejected->headers->set('X-Till-Token', $rejectedToken);
                $rejectedResponse = $middleware->handle(
                    $rejected,
                    fn () => response()->json(['ok' => true])
                );
                $this->assertSame(403, $rejectedResponse->getStatusCode());
            }
        } finally {
            app()->forgetInstance('till');
            app()->forgetInstance('company');
            $this->dropEdgeIdentityTables();
            config()->set('salexa_sync.deployment_mode', $originalMode);
        }
    }

    public function test_signed_protocol_routes_are_unavailable_outside_cloud_mode(): void
    {
        $originalMode = config('salexa_sync.deployment_mode');

        try {
            foreach (['edge', 'standalone'] as $mode) {
                config()->set('salexa_sync.deployment_mode', $mode);

                foreach ([
                    ['POST', '/api/pos/sync/push'],
                    ['GET', '/api/pos/sync/pull'],
                    ['GET', '/api/pos/sync/node-status'],
                ] as [$method, $uri]) {
                    $response = $this->json($method, $uri);

                    $response
                        ->assertStatus(409)
                        ->assertJsonPath('code', 'SYNC_CLOUD_ENDPOINT_UNAVAILABLE');
                }
            }
        } finally {
            config()->set('salexa_sync.deployment_mode', $originalMode);
        }
    }

    private function createEdgeIdentityTables(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_code')->nullable();
            $table->string('company_name')->nullable();
            $table->string('status');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('status');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->char('registration_token', 64);
            $table->boolean('is_registered');
            $table->boolean('is_active');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip_address', 45)->nullable();
            $table->string('last_user_agent')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('sync_local_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->nullable();
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('licenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('status');
            $table->timestamps();
        });
    }

    private function dropEdgeIdentityTables(): void
    {
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('sync_local_states');
        Schema::dropIfExists('pos_tills');
        Schema::dropIfExists('company_branches');
        Schema::dropIfExists('companies');
    }
}
