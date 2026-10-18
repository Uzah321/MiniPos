<?php

namespace App\Services\Zimra;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

class ZimraPathService
{
    public function deviceFolder(string $companyId, int|string $deviceId): string
    {
        $base = trim((string) config('zimra.certificate_base_path', 'storage/app/private/zimra/devices'), '/');

        return "{$base}/{$companyId}/{$deviceId}";
    }

    public function absoluteDeviceFolder(string $companyId, int|string $deviceId): string
    {
        return base_path($this->deviceFolder($companyId, $deviceId));
    }

    public function storeDeviceFiles(
        string $companyId,
        int|string $deviceId,
        UploadedFile $certificate,
        UploadedFile $privateKey
    ): array {
        $folder = $this->deviceFolder($companyId, $deviceId);
        $absoluteFolder = base_path($folder);

        File::ensureDirectoryExists($absoluteFolder, 0700, true);

        $certificateName = 'certificate.' . strtolower($certificate->getClientOriginalExtension());
        $privateKeyName = 'private_key.' . strtolower($privateKey->getClientOriginalExtension());

        $certificate->move($absoluteFolder, $certificateName);
        $privateKey->move($absoluteFolder, $privateKeyName);

        @chmod($absoluteFolder . DIRECTORY_SEPARATOR . $certificateName, 0600);
        @chmod($absoluteFolder . DIRECTORY_SEPARATOR . $privateKeyName, 0600);

        return [
            'certificate_path' => "{$folder}/{$certificateName}",
            'private_key_path' => "{$folder}/{$privateKeyName}",
            'certificate_absolute_path' => base_path("{$folder}/{$certificateName}"),
            'private_key_absolute_path' => base_path("{$folder}/{$privateKeyName}"),
        ];
    }

    public function absolute(string $relativePath): string
    {
        return base_path(ltrim($relativePath, '/'));
    }
}
