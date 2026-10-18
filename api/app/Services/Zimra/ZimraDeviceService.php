<?php

namespace App\Services\Zimra;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\FiscalDevice\Models\FiscalDevice;
use Modules\ZimraIntegration\Models\ZimraCompanySetting;

class ZimraDeviceService
{
    public function __construct(
        private ZimraClient $client,
        private ZimraPathService $pathService
    ) {
    }

    public function saveDevice(array $data, UploadedFile $certificate, UploadedFile $privateKey): FiscalDevice
    {
        return DB::transaction(function () use ($data, $certificate, $privateKey) {
            $setting = ZimraCompanySetting::where('company_id', $data['company_id'])->first();

            if (!$setting || !$setting->enabled) {
                throw new \Exception('ZIMRA fiscalisation is not enabled for this company.');
            }

            $paths = $this->pathService->storeDeviceFiles(
                companyId: $data['company_id'],
                deviceId: $data['device_id'],
                certificate: $certificate,
                privateKey: $privateKey
            );

            $device = FiscalDevice::updateOrCreate(
                ['company_id' => $data['company_id'], 'device_id' => $data['device_id']],
                [
                    'branch_id' => $data['branch_id'] ?? null,
                    'device_serial_no' => $data['device_serial_no'] ?? null,
                    'model_name' => $data['model_name'],
                    'model_version' => $data['model_version'],
                    'environment' => $data['environment'] ?? $setting->environment,
                    'base_url' => $data['base_url'] ?? $setting->base_url,
                    'certificate_path' => $paths['certificate_path'],
                    'private_key_path' => $paths['private_key_path'],
                    'certificate_password' => $data['certificate_password'] ?? null,
                    'is_registered' => true,
                    'is_active' => true,
                ]
            );

            return $device->fresh();
        });
    }

    public function syncConfig(FiscalDevice $device): FiscalDevice
    {
        $response = $this->client->get($device, 'GetConfig');

        if (!$response->successful()) {
            throw new \Exception('ZIMRA getConfig failed: ' . $response->body());
        }

        $device->update([
            'taxpayer_name' => $response->json('taxPayerName'),
            'taxpayer_tin' => $response->json('taxPayerTIN'),
            'vat_number' => $response->json('vatNumber'),
            'device_serial_no' => $response->json('deviceSerialNo') ?: $device->device_serial_no,
            'device_branch_name' => $response->json('deviceBranchName'),
            'device_operating_mode' => $response->json('deviceOperatingMode'),
            'taxpayer_day_max_hrs' => $response->json('taxPayerDayMaxHrs'),
            'taxpayer_day_end_notification_hrs' => $response->json('taxpayerDayEndNotificationHrs'),
            'certificate_valid_till' => $response->json('certificateValidTill'),
            'qr_url' => $response->json('qrUrl'),
            'last_config_response' => $response->json(),
        ]);

        return $device->fresh();
    }

    public function status(FiscalDevice $device): array
    {
        $response = $this->client->get($device, 'GetStatus');

        if (!$response->successful()) {
            throw new \Exception('ZIMRA getStatus failed: ' . $response->body());
        }

        $device->update(['last_status_response' => $response->json()]);

        return $response->json() ?: [];
    }

    public function listDevices(string $companyId, ?string $branchId = null)
    {
        return FiscalDevice::where('company_id', $companyId)
            ->when($branchId, function ($query) use ($branchId) {
                $query->where(function ($sub) use ($branchId) {
                    $sub->whereNull('branch_id')->orWhere('branch_id', $branchId);
                });
            })
            ->orderByDesc('is_active')
            ->orderBy('device_id')
            ->get();
    }
}
