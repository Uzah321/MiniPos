<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\GeneralSettings\Models\PosTill;
use Modules\GeneralSettings\Models\QzCertificate;

class QzController extends Controller
{
    public function cert(Request $request)
    {
        $company = app('company');
        $scope = $this->resolveScope($request, (string) $company->id);

        if (isset($scope['response'])) {
            return $scope['response'];
        }

        $certificate = $this->resolveCertificate(
            companyId: (string) $company->id,
            branchId: $scope['branch_id'],
            tillId: $scope['till_id'],
            exactTill: $scope['exact_till']
        );

        if (! $certificate) {
            return response('Certificate not configured for this terminal', 404)
                ->header('Content-Type', 'text/plain');
        }

        if (! Storage::disk('qz_private')->exists($certificate->certificate_path)) {
            return response('Certificate file not found', 404)
                ->header('Content-Type', 'text/plain');
        }

        $pem = Storage::disk('qz_private')->get($certificate->certificate_path);

        return response($pem, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function sign(Request $request)
    {
        $company = app('company');
        $validator = Validator::make($request->all(), [
            'request' => ['required', 'string', 'max:1048576'],
            // Selectors are honoured only for a Back Office token. A Front
            // Office request is always scoped from app('till').
            'branch_id' => ['nullable', 'string'],
            'till_id' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $scope = $this->resolveScope($request, (string) $company->id);

        if (isset($scope['response'])) {
            return $scope['response'];
        }

        $certificate = $this->resolveCertificate(
            companyId: (string) $company->id,
            branchId: $scope['branch_id'],
            tillId: $scope['till_id'],
            exactTill: $scope['exact_till']
        );

        if (! $certificate) {
            return response()->json([
                'message' => 'QZ certificate not configured for this terminal.',
            ], 404);
        }

        if (! Storage::disk('qz_private')->exists($certificate->private_key_path)) {
            return response()->json([
                'message' => 'Private key file not found.',
            ], 500);
        }

        $keyPem = Storage::disk('qz_private')->get($certificate->private_key_path);
        $privateKey = openssl_pkey_get_private($keyPem);

        if ($privateKey === false) {
            return response()->json([
                'message' => 'Invalid private key.',
            ], 500);
        }

        $signature = '';
        $ok = openssl_sign(
            (string) $request->input('request'),
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA1
        );

        if (! $ok || $signature === '') {
            return response()->json([
                'message' => 'Error signing message.',
            ], 500);
        }

        return response()->json([
            'signature' => base64_encode($signature),
        ]);
    }

    /**
     * Front Office scope is supplied exclusively by ResolveTillFromToken.
     * Back Office keeps the existing selected branch/till behaviour.
     */
    private function resolveScope(Request $request, string $companyId): array
    {
        $resolvedTill = app()->bound('till') ? app('till') : null;

        if ($resolvedTill instanceof PosTill
            && (string) $resolvedTill->company_id === $companyId) {
            return [
                'branch_id' => $resolvedTill->branch_id,
                'till_id' => $resolvedTill->id,
                'exact_till' => true,
            ];
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'nullable',
                'string',
                Rule::exists('company_branches', 'id')->where('company_id', $companyId),
            ],
            'till_id' => [
                'nullable',
                'string',
                Rule::exists('pos_tills', 'id')->where('company_id', $companyId),
            ],
        ]);

        if ($validator->fails()) {
            return [
                'response' => response()->json([
                    'message' => 'Invalid QZ certificate scope.',
                    'errors' => $validator->errors(),
                ], 422),
            ];
        }

        $branchId = $request->input('branch_id')
            ?: $this->validatedCompanyBranchId($request);
        $tillId = $request->input('till_id') ?: $request->header('X-Till-Id');

        if ($tillId) {
            $selectedTill = PosTill::query()
                ->where('company_id', $companyId)
                ->whereKey($tillId)
                ->first();

            if (! $selectedTill
                || ($branchId && (string) $selectedTill->branch_id !== (string) $branchId)) {
                return [
                    'response' => response()->json([
                        'message' => 'Selected till does not belong to the selected company and branch.',
                    ], 422),
                ];
            }

            $branchId ??= $selectedTill->branch_id;
        }

        return [
            'branch_id' => $branchId,
            'till_id' => $tillId,
            'exact_till' => false,
        ];
    }

    /**
     * ResolveCompanyFromDomain validates this value against the resolved
     * company before dispatching authenticated tenant routes. Reading that
     * context avoids trusting a platform user's stale primary branch after a
     * workspace switch.
     */
    private function validatedCompanyBranchId(Request $request): ?string
    {
        $branchId = $request->attributes->get('company_branch_id');

        if (! is_scalar($branchId)) {
            return null;
        }

        $branchId = trim((string) $branchId);

        return $branchId !== '' ? $branchId : null;
    }

    private function resolveCertificate(
        string $companyId,
        mixed $branchId = null,
        mixed $tillId = null,
        bool $exactTill = false
    ): ?QzCertificate {
        $query = QzCertificate::query()
            ->where('company_id', $companyId)
            ->where('is_active', true);

        if ($exactTill) {
            return $query
                ->where('branch_id', $branchId)
                ->where('till_id', $tillId)
                ->first();
        }

        return $query
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->where(function ($query) use ($tillId) {
                $query->whereNull('till_id');

                if ($tillId) {
                    $query->orWhere('till_id', $tillId);
                }
            })
            ->orderByRaw('till_id IS NULL ASC')
            ->orderByRaw('branch_id IS NULL ASC')
            ->first();
    }
}
