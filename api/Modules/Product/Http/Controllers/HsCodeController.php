<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\HsCode\HsCodeSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Product\Models\Product;
use function App\Helpers\hasPermission;

class HsCodeController extends Controller
{
    public function suggestions(Request $request, HsCodeSuggestionService $suggestions)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
        if ($permission !== true) {
            return $permission;
        }

        $data = $request->validate([
            'description' => ['required', 'string', 'max:500'],
        ]);

        return response()->json([
            'message' => 'HS heading suggestions require verification and approval before fiscal use.',
            'data' => $suggestions->suggest($data['description']),
        ]);
    }

    public function approve(Request $request, string $productId)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
        if ($permission !== true) {
            return $permission;
        }

        $data = $request->validate([
            'hs_code' => ['required', 'string', 'regex:/^(?:[0-9]{4}|[0-9]{8})$/', Rule::notIn(['0000', '00000000'])],
            'classification_basis' => ['required', 'string', 'min:10', 'max:2000'],
            'confirmed_current_tariff' => ['required', 'accepted'],
        ]);

        $product = Product::query()
            ->where('company_id', $company->id)
            ->whereKey($productId)
            ->firstOrFail();

        $approvalId = (string) Str::uuid();
        DB::transaction(function () use ($data, $company, $product, $approvalId): void {
            DB::table('product_hs_approvals')->insert([
                'id' => $approvalId,
                'company_id' => $company->id,
                'product_id' => $product->id,
                'hs_code' => $data['hs_code'],
                'classification_basis' => trim($data['classification_basis']),
                'source_url' => HsCodeSuggestionService::TARIFF_URL,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $product->hs_code = $data['hs_code'];
            $product->save();
        });

        return response()->json([
            'message' => 'HS code approved for this product. Paired terminals will receive it with the next configuration sync.',
            'data' => [
                'product_id' => $product->id,
                'hs_code' => $product->hs_code,
                'approval_id' => $approvalId,
            ],
        ]);
    }
}
