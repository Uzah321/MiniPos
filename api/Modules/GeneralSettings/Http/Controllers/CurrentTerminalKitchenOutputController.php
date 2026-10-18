<?php

namespace Modules\GeneralSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\GeneralSettings\Models\PosTill;
use Modules\Sales\Services\HospitalityOutputConfigurationService;

class CurrentTerminalKitchenOutputController extends Controller
{
    public function __construct(
        private readonly HospitalityOutputConfigurationService $configuration
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'message' => 'Current terminal kitchen output configuration fetched successfully.',
            'data' => $this->configuration->forTill($this->currentTill()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'mode' => ['sometimes', 'required', 'string', Rule::in([
                HospitalityOutputConfigurationService::MODE_KITCHEN_PRINTER,
                HospitalityOutputConfigurationService::MODE_KDS,
            ])],
            'auto_print' => ['sometimes', 'required', 'boolean'],
            'copies' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $this->configuration->updateForTill(
                $this->currentTill(),
                $validator->validated(),
                auth()->id() ? (string) auth()->id() : null
            );
        } catch (\DomainException $exception) {
            if ($exception->getMessage() === 'KDS_NOT_AVAILABLE') {
                return response()->json([
                    'message' => 'KDS is reserved for a future module. Configure a QZ kitchen printer for this terminal.',
                    'code' => 'KDS_NOT_AVAILABLE',
                    'capabilities' => [
                        'kitchen_printer' => ['available' => true, 'transport' => 'qz'],
                        'kds' => ['available' => false, 'status' => 'planned'],
                    ],
                ], 422);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Current terminal kitchen output configuration updated successfully.',
            'data' => $data,
        ]);
    }

    private function currentTill(): PosTill
    {
        $till = app()->bound('till') ? app('till') : null;
        $company = app()->bound('company') ? app('company') : null;

        if (! $till instanceof PosTill
            || ! $company
            || (string) $till->company_id !== (string) $company->id) {
            throw new HttpResponseException(response()->json([
                'message' => 'A valid current terminal activation is required.',
                'code' => 'CURRENT_TERMINAL_REQUIRED',
            ], 403));
        }

        return $till;
    }
}
