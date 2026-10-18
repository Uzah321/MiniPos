<?php

namespace App\Services\Zimra;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\FiscalDevice\Models\FiscalDevice;
use Modules\ZimraIntegration\Models\ZimraCompanySetting;

class ZimraClient
{
    public function __construct(private ZimraPathService $pathService)
    {
    }

    public function get(FiscalDevice $device, string $endpoint): Response
    {
        return $this->secured($device)->get($this->url($device, $endpoint));
    }

    public function post(FiscalDevice $device, string $endpoint, array $payload): Response
    {
        return $this->secured($device)->post($this->url($device, $endpoint), $payload);
    }

    private function secured(FiscalDevice $device)
    {
        if (!$device->certificate_path || !$device->private_key_path) {
            throw new \Exception('Fiscal device certificate or private key is missing.');
        }

        $certificatePath = $this->pathService->absolute($device->certificate_path);
        $privateKeyPath = $this->pathService->absolute($device->private_key_path);

        if (!is_file($certificatePath)) {
            throw new \Exception("Fiscal certificate not found at base path: {$device->certificate_path}");
        }

        if (!is_file($privateKeyPath)) {
            throw new \Exception("Fiscal private key not found at base path: {$device->private_key_path}");
        }

        $options = [
            'cert' => $device->certificate_password ? [$certificatePath, $device->certificate_password] : $certificatePath,
            'ssl_key' => $device->certificate_password ? [$privateKeyPath, $device->certificate_password] : $privateKeyPath,
            'verify' => $this->verifySsl($device),
        ];

        return Http::timeout(config('zimra.timeout', 30))
            ->withHeaders($this->headers($device))
            ->withOptions($options)
            ->acceptJson()
            ->asJson();
    }

    private function headers(FiscalDevice $device): array
    {
        return [
            'DeviceModelName' => $device->model_name,
            'DeviceModelVersionNo' => $device->model_version,
            'DeviceModelVersion' => $device->model_version,
            'Content-Type' => 'application/json',
        ];
    }

    private function url(FiscalDevice $device, string $endpoint): string
    {
        $baseUrl = rtrim($this->baseUrl($device), '/');

        if (!$baseUrl) {
            throw new \Exception('ZIMRA base URL is not configured.');
        }

        return $baseUrl . '/Device/v1/' . $device->device_id . '/' . ltrim($endpoint, '/');
    }

    private function baseUrl(FiscalDevice $device): string
    {
        if ($device->base_url) {
            return $device->base_url;
        }

        $setting = ZimraCompanySetting::where('company_id', $device->company_id)->first();

        if ($setting && $setting->base_url) {
            return $setting->base_url;
        }

        $environment = $device->environment ?: optional($setting)->environment ?: config('zimra.default_environment', 'testing');

        return (string) config("zimra.environments.$environment.base_url");
    }

    private function verifySsl(FiscalDevice $device): bool
    {
        $environment = $device->environment ?: config('zimra.default_environment', 'testing');

        return (bool) config("zimra.environments.$environment.verify_ssl", true);
    }
}
