<?php

namespace App\Http\Controllers;

use App\Models\MobileFiscalDevice;
use App\Models\SyncNode;
use App\Services\Zimra\MobileFiscalRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileFiscalDeviceController extends Controller
{
    public function eligibleNodes(): JsonResponse
    {
        $companyId = app('company')->id;

        return response()->json(['data' => SyncNode::query()
            ->with(['branch:id,company_id,branch_name,branch_code', 'till:id,company_id,branch_id,till_code,till_name'])
            ->where('company_id', $companyId)
            ->whereHas('branch', fn ($query) => $query->where('company_id', $companyId))
            ->whereHas('till', fn ($query) => $query
                ->where('company_id', $companyId)
                ->whereColumn('pos_tills.branch_id', 'sync_nodes.branch_id'))
            ->where('deployment_mode', 'android')
            ->where('status', 'active')
            ->whereNotNull('activated_at')
            ->whereNull('revoked_at')
            ->whereNotNull('till_id')
            ->orderBy('node_name')
            ->get(['id', 'company_id', 'branch_id', 'till_id', 'node_name', 'node_code', 'deployment_mode', 'status', 'activated_at'])]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => MobileFiscalDevice::query()
            ->where('company_id', app('company')->id)
            ->orderBy('branch_id')->orderBy('device_id')->get()]);
    }

    public function register(Request $request, MobileFiscalRegistrationService $service): JsonResponse
    {
        $data = $request->validate([
            'sync_node_id' => ['required', 'uuid'],
            'device_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'device_serial_no' => ['required', 'string', 'max:100'],
            'environment' => ['required', 'in:testing,production'],
            'device_operating_mode' => ['required', 'in:Online,Offline'],
            'qr_url' => ['required', 'url', 'max:500'],
            'certificate_pem' => ['required', 'string', 'min:100', 'max:12000'],
        ]);
        $node = SyncNode::query()
            ->where('company_id', app('company')->id)
            ->where('deployment_mode', 'android')
            ->whereKey($data['sync_node_id'])
            ->first();
        if (! $node) {
            return response()->json(['code' => 'ANDROID_NODE_NOT_FOUND', 'message' => 'Select a paired Android terminal in this company.'], 404);
        }

        try {
            $device = $service->register($data, $node, (string) $request->user()->id);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['code' => 'MOBILE_FISCAL_REGISTRATION_INVALID', 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'The Android fiscal device public certificate is registered for this terminal.', 'data' => $device], 201);
    }

    public function ownership(Request $request): JsonResponse
    {
        /** @var SyncNode|null $node */
        $node = $request->attributes->get('sync_node');
        if (! $node || $node->deployment_mode !== 'android' || ! $node->till_id) {
            return response()->json(['code' => 'ANDROID_NODE_REQUIRED', 'message' => 'This endpoint requires a paired Android till.'], 403);
        }
        $device = MobileFiscalDevice::query()
            ->where('sync_node_id', $node->id)
            ->where('company_id', $node->company_id)
            ->where('branch_id', $node->branch_id)
            ->where('till_id', $node->till_id)
            ->where('is_active', true)
            ->first();
        if (! $device || $device->certificate_valid_until?->isPast()) {
            return response()->json([
                'code' => 'MOBILE_FISCAL_DEVICE_NOT_REGISTERED',
                'message' => 'An administrator must register a valid public certificate for this Android terminal.',
            ], 409);
        }

        return response()->json(['data' => [
            'active' => true,
            'company_id' => (string) $device->company_id,
            'branch_id' => (string) $device->branch_id,
            'till_id' => (string) $device->till_id,
            'sync_node_id' => (string) $device->sync_node_id,
            'device_id' => (int) $device->device_id,
            'device_serial_no' => (string) $device->device_serial_no,
            'certificate_sha256' => (string) $device->certificate_sha256,
            'certificate_sha256_fingerprint' => (string) $device->certificate_sha256,
            'qr_url' => (string) $device->qr_url,
            'environment' => (string) $device->environment,
            'device_operating_mode' => (string) $device->device_operating_mode,
            'certificate_valid_until' => $device->certificate_valid_until?->toIso8601String(),
        ]]);
    }
}
