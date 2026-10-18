<?php

namespace App\Http\Controllers;

use App\Models\SyncNode;
use App\Services\Zimra\MobileFiscalReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileFiscalReceiptController extends Controller
{
    public function confirmFile(
        Request $request,
        string $fiscalDayNo,
        string $fileSequence,
        MobileFiscalReceiptService $service
    ): JsonResponse {
        /** @var SyncNode|null $node */
        $node = $request->attributes->get('sync_node');
        if (! $node || $node->deployment_mode !== 'android' || ! $node->till_id) {
            return response()->json(['code' => 'ANDROID_NODE_REQUIRED', 'message' => 'A paired Android terminal is required.'], 403);
        }
        $data = $request->validate([
            'file_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'operation_id' => ['required', 'string', 'max:255'],
            'receipt_hashes' => ['present', 'array', 'max:1000'],
            'receipt_hashes.*' => ['required', 'string', 'max:100'],
            'get_file_status' => ['required', 'array'],
            'get_file_status.fileStatus' => ['required', 'array', 'min:1'],
        ]);
        try {
            $confirmation = $service->confirmFile(
                $node,
                (int) $fiscalDayNo,
                (int) $fileSequence,
                $data['file_hash'],
                $data['operation_id'],
                $data['get_file_status'],
                $data['receipt_hashes']
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'code' => 'MOBILE_FISCAL_FILE_REJECTED',
                'message' => $exception->getMessage(),
            ], 422);
        }
        return response()->json(['data' => [
            'fiscal_day_no' => $confirmation->fiscal_day_no,
            'file_sequence' => $confirmation->file_sequence,
            'file_hash' => $confirmation->file_sha256,
            'operation_id' => $confirmation->operation_id,
            'receipt_count' => count($confirmation->receipt_hashes),
            'status' => 'device_reported_file_processed',
            'centrally_verified' => false,
        ]]);
    }

    public function acknowledge(Request $request, string $clientSaleId, MobileFiscalReceiptService $service): JsonResponse
    {
        /** @var SyncNode|null $node */
        $node = $request->attributes->get('sync_node');
        if (! $node || $node->deployment_mode !== 'android' || ! $node->till_id) {
            return response()->json(['code' => 'ANDROID_NODE_REQUIRED', 'message' => 'A paired Android terminal is required.'], 403);
        }
        $data = $request->validate([
            'receipt_hash' => ['required', 'string', 'max:100'],
            'fdms_response' => ['required', 'array'],
            'fdms_response.receiptID' => ['required', 'integer', 'min:1'],
            'fdms_response.operationID' => ['required', 'string', 'max:255'],
        ]);
        try {
            $record = $service->acknowledge(
                $node,
                $clientSaleId,
                $data['receipt_hash'],
                $data['fdms_response']
            );
        } catch (\InvalidArgumentException $exception) {
            $code = str_contains($exception->getMessage(), 'conflicts') ? 409 : 422;
            return response()->json([
                'code' => 'MOBILE_FISCAL_ACK_REJECTED',
                'message' => $exception->getMessage(),
            ], $code);
        }

        return response()->json(['data' => [
            'client_sale_id' => $record->client_sale_id,
            'receipt_hash' => $record->receipt_hash,
            'status' => $record->status,
            'fdms_receipt_id' => $record->fdms_receipt_id,
            'operation_id' => $record->operation_id,
            'centrally_verified' => false,
        ]]);
    }
}
