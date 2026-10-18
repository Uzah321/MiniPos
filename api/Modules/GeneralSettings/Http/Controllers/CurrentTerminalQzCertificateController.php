<?php

namespace Modules\GeneralSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Modules\GeneralSettings\Models\PosTill;
use Modules\GeneralSettings\Models\QzCertificate;

/**
 * Manages the QZ Tray signing identity for the activated browser terminal.
 *
 * Company, branch and till scope always come from ResolveTillFromToken. The
 * private key remains on the local Laravel server and is never serialized to
 * the browser; QzController performs signatures on the terminal's behalf.
 */
class CurrentTerminalQzCertificateController extends Controller
{
    public function show(): JsonResponse
    {
        $till = $this->currentTill();
        $certificate = $this->certificateFor($till);

        return response()->json([
            'message' => 'Current terminal QZ certificate fetched successfully.',
            'data' => $certificate ? $this->safePayload($certificate) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $till = $this->currentTill();
        $certificate = $this->certificateFor($till);

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'certificate' => ['sometimes', 'file', 'max:200'],
            'private_key' => ['sometimes', 'file', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $hasCertificateUpload = $request->hasFile('certificate');
        $hasPrivateKeyUpload = $request->hasFile('private_key');

        if ($hasCertificateUpload xor $hasPrivateKeyUpload) {
            return response()->json([
                'message' => 'The QZ certificate and private key must be uploaded together.',
            ], 422);
        }

        if (! $certificate && (! $hasCertificateUpload || ! $hasPrivateKeyUpload)) {
            return response()->json([
                'message' => 'Both the QZ certificate and private key are required for first-time setup.',
                'errors' => [
                    'certificate' => $hasCertificateUpload ? [] : ['The certificate file is required.'],
                    'private_key' => $hasPrivateKeyUpload ? [] : ['The private key file is required.'],
                ],
            ], 422);
        }

        $certificateContent = $hasCertificateUpload
            ? $this->readUpload($request, 'certificate')
            : $this->readStoredFile($certificate?->certificate_path);
        $privateKeyContent = $hasPrivateKeyUpload
            ? $this->readUpload($request, 'private_key')
            : $this->readStoredFile($certificate?->private_key_path);

        $credentialError = $this->validateCredentialPair($certificateContent, $privateKeyContent);

        if ($credentialError !== null) {
            return response()->json([
                'message' => $credentialError,
            ], 422);
        }

        $folder = sprintf(
            'qz-certificates/company-%s/branch-%s/till-%s',
            $till->company_id,
            $till->branch_id,
            $till->id
        );
        $certificatePath = "{$folder}/digital-certificate.txt";
        $privateKeyPath = "{$folder}/private-key.pem";
        $creating = ! $certificate;

        try {
            DB::transaction(function () use (
                $request,
                $till,
                &$certificate,
                $certificateContent,
                $privateKeyContent,
                $certificatePath,
                $privateKeyPath
            ): void {
                if (! Storage::disk('qz_private')->put($certificatePath, $certificateContent)) {
                    throw new \RuntimeException('Unable to save the QZ certificate file.');
                }

                if (! Storage::disk('qz_private')->put($privateKeyPath, $privateKeyContent)) {
                    throw new \RuntimeException('Unable to save the QZ private key file.');
                }

                $certificate ??= new QzCertificate;
                $certificate->company_id = $till->company_id;
                $certificate->branch_id = $till->branch_id;
                $certificate->till_id = $till->id;
                $certificate->certificate_path = $certificatePath;
                $certificate->private_key_path = $privateKeyPath;

                if ($request->exists('name')) {
                    $name = trim((string) $request->input('name'));
                    $certificate->name = $name !== '' ? $name : null;
                } elseif (! $certificate->exists) {
                    $certificate->name = trim((string) $till->till_code).' QZ Tray';
                }

                if ($request->exists('notes')) {
                    $notes = trim((string) $request->input('notes'));
                    $certificate->notes = $notes !== '' ? $notes : null;
                }

                $certificate->is_active = $request->exists('is_active')
                    ? $request->boolean('is_active')
                    : ($certificate->exists ? (bool) $certificate->is_active : true);
                $certificate->save();
            });
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The QZ certificate could not be saved for this terminal.',
            ], 500);
        }

        return response()->json([
            'message' => $creating
                ? 'Current terminal QZ certificate saved successfully.'
                : 'Current terminal QZ certificate updated successfully.',
            'data' => $this->safePayload($certificate->fresh()),
        ], $creating ? 201 : 200);
    }

    public function destroy(): JsonResponse
    {
        $till = $this->currentTill();
        $certificate = $this->certificateFor($till);

        if (! $certificate) {
            return response()->json([
                'message' => 'QZ certificate not found for this terminal.',
            ], 404);
        }

        $paths = [$certificate->certificate_path, $certificate->private_key_path];
        Storage::disk('qz_private')->delete(array_values(array_filter($paths)));
        // QzCertificate deliberately uses hard deletes because the table's
        // unique key does not include deleted_at. Keeping a tombstone would
        // prevent this terminal from reinstalling its QZ identity.
        $certificate->delete();

        return response()->json([
            'message' => 'Current terminal QZ certificate deleted successfully.',
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

    private function certificateFor(PosTill $till): ?QzCertificate
    {
        return QzCertificate::query()
            ->where('company_id', $till->company_id)
            ->where('branch_id', $till->branch_id)
            ->where('till_id', $till->id)
            ->first();
    }

    private function safePayload(QzCertificate $certificate): array
    {
        return [
            'id' => $certificate->id,
            'name' => $certificate->name,
            'is_active' => (bool) $certificate->is_active,
            'notes' => $certificate->notes,
            'certificate_configured' => Storage::disk('qz_private')->exists($certificate->certificate_path),
            'private_key_configured' => Storage::disk('qz_private')->exists($certificate->private_key_path),
            'created_at' => $certificate->created_at,
            'updated_at' => $certificate->updated_at,
        ];
    }

    private function readUpload(Request $request, string $field): string
    {
        $path = $request->file($field)?->getRealPath();
        $content = is_string($path) ? file_get_contents($path) : false;

        return is_string($content) ? $content : '';
    }

    private function readStoredFile(?string $path): string
    {
        if (! $path || ! Storage::disk('qz_private')->exists($path)) {
            return '';
        }

        return Storage::disk('qz_private')->get($path);
    }

    private function validateCredentialPair(string $certificatePem, string $privateKeyPem): ?string
    {
        $x509 = $certificatePem !== '' ? openssl_x509_read($certificatePem) : false;

        if ($x509 === false) {
            return 'Invalid certificate file. Expected a valid PEM X.509 certificate.';
        }

        $privateKey = $privateKeyPem !== '' ? openssl_pkey_get_private($privateKeyPem) : false;

        if ($privateKey === false) {
            return 'Invalid private key file. Expected an unencrypted PEM private key.';
        }

        $publicKey = openssl_pkey_get_public($certificatePem);
        $publicDetails = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
        $privateDetails = openssl_pkey_get_details($privateKey);

        if (! is_array($publicDetails)
            || ! is_array($privateDetails)
            || empty($publicDetails['key'])
            || empty($privateDetails['key'])
            || ! hash_equals(trim($publicDetails['key']), trim($privateDetails['key']))) {
            return 'The private key does not match the uploaded QZ certificate.';
        }

        return null;
    }
}
