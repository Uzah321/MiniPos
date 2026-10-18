<?php

namespace App\Helpers;

use Exception;
use Modules\Currency\Models\Currency;
use Modules\RolesAndPermission\Models\Role;


class ResponseHelper
{
    /**
     * Generate a basic response.
     *
     * @param string $message
     * @param int $code
     * @param array|null $data
     * @return \Illuminate\Http\JsonResponse
     */
    public static function baseResponse(string $message, int $code = 200, ?array $data = null)
    {
        $response = [
            'code' => $code,
            'status' => $code === 200 ? 'success' : 'error',
            'message' => $message,
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $code);
    }

    /**
     * Generate a template response.
     *
     * @param string $type
     * @param string $message
     * @param int $code
     * @return \Illuminate\Http\JsonResponse
     */
    public static function templateResponse(string $type, string $message, int $code)
    {
        $response = [
            'code' => $code,
            'status' => $type === 'success' ? 'success' : 'error',
            'message' => $message,
        ];

        return response()->json($response, $code);
    }

    public static function generate($text)
    {
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new RendererStyle(300),
            new \BaconQrCode\Renderer\Image\GdImageBackEnd() // ✅ Force GD, not Imagick
        );

        $writer = new Writer($renderer);

        return $writer->writeString($text);
    }

}
if (!function_exists('hasPermission')) {
    function hasPermission($role_id, $permission_name, $company_id = null, $customMessage = null)
    {
        $company_id = $company_id ?? (
        app()->bound('company') ? app('company')->id : null
        );

        $authenticatedUser = auth()->user();
        if ($authenticatedUser instanceof \Modules\Authentication\Models\PosUser) {
            $authorization = app(\Modules\Authentication\Services\EnterpriseAuthorizationService::class);
            $superAdmins = app(\App\Services\Auth\SuperAdminService::class);
            $usesScopedAssignments = $authorization->usesScopedAssignments($authenticatedUser);
            $isPlatformAdministrator = $superAdmins->isPlatformAdministrator($authenticatedUser);

            // Canonical assignments are authoritative forever once present.
            // This makes every legacy controller call safe during migration:
            // stale base-role permissions cannot resurrect a revoked grant.
            if ($usesScopedAssignments || $isPlatformAdministrator) {
                if (!$company_id || !app()->bound('company')) {
                    return response()->json([
                        'code' => 400,
                        'message' => 'Company context not resolved',
                    ], 400);
                }

                if ($isPlatformAdministrator
                    && !$superAdmins->hasPlatformSessionAccess($authenticatedUser)) {
                    return response()->json([
                        'code' => 403,
                        'message' => 'Platform administration requires a Back Office platform session.',
                    ], 403);
                }

                if (!$isPlatformAdministrator
                    && (string) $authenticatedUser->company_id !== (string) $company_id) {
                    return response()->json([
                        'code' => 403,
                        'message' => 'User does not belong to this company',
                    ], 403);
                }

                $company = app('company');
                $scopes = app(\Modules\Authentication\Services\EnterpriseScopeResolver::class);

                try {
                    if (app()->bound('till') && app('till')) {
                        $scope = $scopes->fromTill(app('till'));
                    } else {
                        $httpRequest = request();
                        $branchId = collect([
                            $httpRequest->attributes->get('company_branch_id'),
                            $httpRequest->attributes->get('business_mode_branch_id'),
                            $httpRequest->header('X-Branch-ID'),
                            $httpRequest->input('branch_id'),
                            $authenticatedUser->branch_id,
                        ])->first(fn ($value) => is_scalar($value) && trim((string) $value) !== '');

                        if ($branchId === null) {
                            $scope = $scopes->fromCompany($company);
                        } else {
                            $branchId = trim((string) $branchId);
                            $shop = \Modules\Company\Models\Shop::query()
                                ->where('company_id', $company_id)
                                ->where('branch_id', $branchId)
                                ->first();
                            $scope = $shop
                                ? $scopes->fromShop($shop)
                                : new \Modules\Authentication\Data\AuthorizationScope(
                                    companyId: (string) $company_id,
                                    shopId: $branchId,
                                    branchId: $branchId,
                                );
                        }
                    }
                } catch (\Throwable) {
                    return response()->json([
                        'code' => 403,
                        'message' => 'The authorization scope could not be resolved.',
                    ], 403);
                }

                if ((string) $scope->companyId !== (string) $company_id
                    || !$authorization->can($authenticatedUser, (string) $permission_name, $scope)) {
                    return response()->json([
                        'code' => 403,
                        'message' => $customMessage ?? 'User has no permission in this enterprise scope',
                    ], 403);
                }

                return true;
            }
        }

        $role = Role::where('id', $role_id)
            ->with('rolePermissions.permission')
            ->first();

        if (!$role) {
            return response()->json([
                'code' => 404,
                'message' => 'Role not found',
            ], 404);
        }

        $normaliseRole = static fn ($value) => strtolower(str_replace([' ', '-'], '_', trim((string) $value)));
        $superAdminNames = ['super_admin', 'super_administrator'];
        $isAuthenticatedSuperAdmin = $authenticatedUser
            && (string) $authenticatedUser->role_id === (string) $role_id
            && in_array($normaliseRole($authenticatedUser->user_type ?? ''), $superAdminNames, true);

        if (!$company_id) {
            return response()->json([
                'code' => 400,
                'message' => 'Company context not resolved',
            ], 400);
        }

        if ($role->company_id !== $company_id) {
            return response()->json([
                'code' => 403,
                'message' => 'Role does not belong to this company',
            ], 403);
        }

        if (in_array($normaliseRole($role->name), $superAdminNames, true) || $isAuthenticatedSuperAdmin) {
            return true;
        }

        foreach ($role->rolePermissions as $rolePermission) {
            if ($rolePermission->permission?->name === $permission_name) {
                return true;
            }
        }

        return response()->json([
            'code' => 403,
            'message' => $customMessage ?? 'User has no permission',
        ], 403);
    }
}




if (!function_exists('currencyConverter')) {
    function currencyConverter(
        string $fromCode,
        string $toCode,
        float|int|string $amount,
        ?string $companyId = null,
        ?string $branchId = null
    ): float {
        $amount = (float) $amount;

        $fromCode = strtoupper(trim($fromCode));
        $toCode = strtoupper(trim($toCode));

        if ($fromCode === $toCode) {
            return round($amount, 4);
        }

        $fromQuery = Currency::where('code', $fromCode)
            ->where('is_active', true)
            ->forBranchOrGlobal($branchId);

        $toQuery = Currency::where('code', $toCode)
            ->where('is_active', true)
            ->forBranchOrGlobal($branchId);

        if ($companyId) {
            $fromQuery->where('company_id', $companyId);
            $toQuery->where('company_id', $companyId);
        }

        $fromCurrency = $fromQuery->firstOrFail();
        $toCurrency = $toQuery->firstOrFail();

        $fromRate = (float) optional($fromCurrency->latestRateForBranch($branchId))->rate;
        $toRate = (float) optional($toCurrency->latestRateForBranch($branchId))->rate;

        if ($fromRate <= 0) {
            throw new Exception("Invalid currency rate for {$fromCode}.");
        }

        if ($toRate <= 0) {
            throw new Exception("Invalid currency rate for {$toCode}.");
        }

        $convertedAmount = ($amount / $fromRate) * $toRate;

        return round($convertedAmount, 4);
    }
}

if (!function_exists('baseCurrencyConverter')) {
    function baseCurrencyConverter($fromCode, $amount)
    {
        $baseCurrency = Currency::where("is_base_currency",1)
            ->with("currencyRate")
            ->firstOrFail();

        $toCurrency = Currency::where("code", $baseCurrency->code)
            ->with("currencyRate")
            ->firstOrFail();

        $fromCurrency = Currency::where("code", $fromCode)
            ->with("currencyRate")
            ->firstOrFail();

        $conertedAmount = ($amount/  $fromCurrency->currencyRate->rate)*$toCurrency->currencyRate->rate;
        return $conertedAmount;

    }
}
