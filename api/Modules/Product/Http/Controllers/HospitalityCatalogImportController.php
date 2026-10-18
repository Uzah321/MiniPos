<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Hospitality\HospitalityCatalogImporter;
use App\Services\Hospitality\HospitalityCatalogReviewService;
use App\Services\Hospitality\HospitalityCatalogWorkbookReader;
use App\Services\Hospitality\HospitalityImportScopeException;
use App\Services\Hospitality\HospitalityImportScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class HospitalityCatalogImportController extends Controller
{
    public function scope(
        Request $request,
        HospitalityImportScopeService $importScope
    ): JsonResponse {
        $company = app('company');
        $companyId = (string) $company->id;
        $access = $importScope->resolve($request->user(), $company);
        $brandQuery = DB::table('brands')->where('company_id', $companyId);
        if (Schema::hasColumn('brands', 'status')) {
            $brandQuery->where('status', 'active');
        }
        if (Schema::hasColumn('brands', 'deleted_at')) {
            $brandQuery->whereNull('deleted_at');
        }
        if (! $access['company_wide']) {
            $brandQuery->whereIn(
                'id',
                $access['shops']->pluck('brand_id')->filter()->unique()->values()->all()
            );
        }
        $brands = $brandQuery
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'logo_url']);

        $shopsByBrand = $access['shops']
            ->whereIn('brand_id', $brands->pluck('id')->all())
            ->groupBy('brand_id');

        return response()->json(['data' => [
            'company' => [
                'id' => $companyId,
                'name' => $company->company_name ?? $company->name ?? null,
            ],
            'company_wide' => (bool) $access['company_wide'],
            'requires_branch' => ! $access['company_wide'],
            'brands' => $brands->map(static fn (object $brand): array => [
                'id' => (string) $brand->id,
                'code' => $brand->code,
                'name' => $brand->name,
                'logo_url' => $brand->logo_url,
                'shops' => ($shopsByBrand->get($brand->id) ?? collect())
                    ->map(static fn (object $shop): array => [
                        'id' => (string) $shop->id,
                        'branch_id' => (string) $shop->branch_id,
                        'code' => $shop->code,
                        'name' => $shop->name,
                        'branch_code' => $shop->branch?->branch_code,
                        'branch_name' => $shop->branch?->branch_name,
                    ])->values()->all(),
            ])->values()->all(),
        ]]);
    }

    public function preview(
        Request $request,
        HospitalityCatalogWorkbookReader $reader,
        HospitalityCatalogReviewService $reviewer,
        HospitalityImportScopeService $importScope
    ): JsonResponse {
        $validator = Validator::make($request->all(), $this->rules(false));
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Check the import fields.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $company = app('company');
            $importScope->assertSelectionAllowed(
                $importScope->resolve($request->user(), $company),
                (string) $request->input('brand_id'),
                $request->input('branch_id')
            );
            $file = $request->file('file');
            $catalog = $reader->read(
                (string) $file->getRealPath(),
                (string) $file->getClientOriginalName()
            );
            $review = $reviewer->review(
                $catalog,
                (string) $company->id,
                (string) $request->input('brand_id'),
                $request->input('branch_id')
            );

            return response()->json(['data' => $review]);
        } catch (HospitalityImportScopeException $exception) {
            return $this->scopeFailure($exception);
        } catch (QueryException $exception) {
            Log::error('Hospitality catalogue preview database failure.', [
                'company_id' => app()->bound('company') ? app('company')->id : null,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'The workbook could not be previewed.',
                'code' => 'HOSPITALITY_IMPORT_PREVIEW_FAILED',
            ], 500);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->workbookFailure($exception);
        } catch (Throwable $exception) {
            Log::error('Hospitality catalogue preview failed.', [
                'company_id' => app()->bound('company') ? app('company')->id : null,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'The workbook could not be previewed.',
                'code' => 'HOSPITALITY_IMPORT_PREVIEW_FAILED',
            ], 500);
        }
    }

    public function commit(
        Request $request,
        HospitalityCatalogWorkbookReader $reader,
        HospitalityCatalogReviewService $reviewer,
        HospitalityCatalogImporter $importer,
        HospitalityImportScopeService $importScope
    ): JsonResponse {
        $validator = Validator::make($request->all(), $this->rules(true));
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Check the import fields.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (config('servnest.deployment_mode') !== 'cloud') {
            return response()->json([
                'message' => 'Catalogue changes must be committed in the cloud Back Office.',
                'code' => 'HOSPITALITY_IMPORT_CLOUD_ONLY',
            ], 409);
        }

        try {
            $company = app('company');
            $importScope->assertSelectionAllowed(
                $importScope->resolve($request->user(), $company),
                (string) $request->input('brand_id'),
                $request->input('branch_id')
            );
            $file = $request->file('file');
            $catalog = $reader->read(
                (string) $file->getRealPath(),
                (string) $file->getClientOriginalName()
            );
            $review = $reviewer->review(
                $catalog,
                (string) $company->id,
                (string) $request->input('brand_id'),
                $request->input('branch_id')
            );
            if (! $review['can_commit']) {
                return response()->json([
                    'message' => 'Resolve the workbook errors before importing.',
                    'code' => 'HOSPITALITY_IMPORT_REJECTED',
                    'data' => $review,
                ], 422);
            }

            $result = $importer->import(
                $catalog,
                (string) $company->id,
                (string) $request->input('brand_id'),
                strtoupper((string) $request->input('currency', 'USD')),
                strtolower((string) $request->input(
                    'source_system',
                    config('servnest.catalogue.import_source_system', 'gaap')
                )),
                auth()->id() ? (string) auth()->id() : null,
                $request->boolean('force'),
                $request->input('branch_id')
            );

            return response()->json(['data' => [
                'import_id' => $result['id'] ?? null,
                'status' => $result['status'] ?? 'completed',
                'brand' => $review['brand'],
                'branch' => $review['branch'],
                'format' => $review['format'],
                'source' => $review['source'],
                'statistics' => $result['statistics'] ?? $review['statistics'],
                'warnings' => $review['warnings'],
            ]]);
        } catch (HospitalityImportScopeException $exception) {
            return $this->scopeFailure($exception);
        } catch (QueryException $exception) {
            Log::error('Hospitality catalogue import database failure.', [
                'company_id' => app()->bound('company') ? app('company')->id : null,
                'brand_id' => $request->input('brand_id'),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'The catalogue import failed and no catalogue changes were committed.',
                'code' => 'HOSPITALITY_IMPORT_FAILED',
            ], 500);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->workbookFailure($exception);
        } catch (Throwable $exception) {
            Log::error('Hospitality catalogue import failed.', [
                'company_id' => app()->bound('company') ? app('company')->id : null,
                'brand_id' => $request->input('brand_id'),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'The catalogue import failed and no catalogue changes were committed.',
                'code' => 'HOSPITALITY_IMPORT_FAILED',
            ], 500);
        }
    }

    /** @return array<string, array<int, string>> */
    private function rules(bool $commit): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx', 'max:102400'],
            'brand_id' => ['required', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'source_system' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'force' => [$commit ? 'nullable' : 'prohibited', 'boolean'],
        ];
    }

    private function workbookFailure(Throwable $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => 'HOSPITALITY_IMPORT_VALIDATION_FAILED',
        ], 422);
    }

    private function scopeFailure(HospitalityImportScopeException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode,
        ], 403);
    }
}
