<?php

namespace Modules\GeneralSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\GeneralSettings\Models\PosTill;
use Modules\GeneralSettings\Models\QzCertificate;

use function App\Helpers\hasPermission;

class QzCertificateController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'printer_management', $company->id);
        if ($result !== true) {
            return $result;
        }

        $query = QzCertificate::where('company_id', $company->id)
            ->with(['branch', 'till'])
            ->orderBy('branch_id')
            ->orderBy('till_id')
            ->orderByDesc('created_at');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('till_id')) {
            $query->where('till_id', $request->till_id);
        }

        if ($request->filled('is_active')) {
            $query->where(
                'is_active',
                filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
            );
        }

        $perPage = (int) $request->get('per_page', 50);

        return response()->json([
            'message' => 'QZ certificates fetched successfully.',
            'data' => $query->paginate($perPage),
        ], 200);
    }

    public function store(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'printer_management', $company->id);
        if ($result !== true) {
            return $result;
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'nullable',
                'string',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'till_id' => [
                'nullable',
                'string',
                Rule::exists('pos_tills', 'id')->where('company_id', $company->id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'certificate' => ['required', 'file', 'max:200'],
            'private_key' => ['required', 'file', 'max:200'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $request->branch_id ?: null;
        $tillId = $request->till_id ?: null;

        if ($tillId) {
            $till = PosTill::where('company_id', $company->id)
                ->where('id', $tillId)
                ->first();

            if (! $till) {
                return response()->json([
                    'message' => 'Selected till is invalid.',
                ], 422);
            }

            if ($branchId && (string) $till->branch_id !== (string) $branchId) {
                return response()->json([
                    'message' => 'Selected till does not belong to the selected branch.',
                ], 422);
            }

            if (! $branchId) {
                $branchId = $till->branch_id;
            }
        }

        $duplicate = $this->certificateExists(
            companyId: $company->id,
            branchId: $branchId,
            tillId: $tillId
        );

        if ($duplicate) {
            return response()->json([
                'message' => 'A QZ certificate already exists for this branch and till.',
            ], 422);
        }

        $certificateContent = file_get_contents($request->file('certificate')->getRealPath());
        $privateKeyContent = file_get_contents($request->file('private_key')->getRealPath());

        if (! $this->looksLikeCertificate($certificateContent)) {
            return response()->json([
                'message' => 'Invalid certificate file. Expected a PEM certificate.',
            ], 422);
        }

        if (! $this->looksLikePrivateKey($privateKeyContent)) {
            return response()->json([
                'message' => 'Invalid private key file. Expected a PEM private key.',
            ], 422);
        }

        $privateKey = openssl_pkey_get_private($privateKeyContent);

        if (! $privateKey) {
            return response()->json([
                'message' => 'Private key could not be loaded by OpenSSL.',
            ], 422);
        }

        if (! $this->credentialPairMatches($certificateContent, $privateKeyContent)) {
            return response()->json([
                'message' => 'The private key does not match the uploaded QZ certificate.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $folder = $this->buildStorageFolder(
                companyId: $company->id,
                branchId: $branchId,
                tillId: $tillId
            );

            $certificatePath = "{$folder}/digital-certificate.txt";
            $privateKeyPath = "{$folder}/private-key.pem";

            Storage::disk('qz_private')->put($certificatePath, $certificateContent);
            Storage::disk('qz_private')->put($privateKeyPath, $privateKeyContent);

            $certificate = QzCertificate::create([
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'till_id' => $tillId,
                'name' => $request->name ?: null,
                'certificate_path' => $certificatePath,
                'private_key_path' => $privateKeyPath,
                'is_active' => $request->boolean('is_active', true),
                'notes' => $request->notes ?: null,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'QZ certificate uploaded successfully.',
                'data' => $certificate->load(['branch', 'till']),
            ], 201);
        } catch (QueryException $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Database error while saving QZ certificate.',
            ], 500);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while saving QZ certificate.',
            ], 500);
        }
    }

    public function show(string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'printer_management', $company->id);
        if ($result !== true) {
            return $result;
        }

        $certificate = QzCertificate::where('company_id', $company->id)
            ->where('id', $id)
            ->with(['branch', 'till'])
            ->first();

        if (! $certificate) {
            return response()->json([
                'message' => 'QZ certificate not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'QZ certificate fetched successfully.',
            'data' => $certificate,
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'printer_management', $company->id);
        if ($result !== true) {
            return $result;
        }

        $certificate = QzCertificate::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (! $certificate) {
            return response()->json([
                'message' => 'QZ certificate not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'nullable',
                'string',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'till_id' => [
                'nullable',
                'string',
                Rule::exists('pos_tills', 'id')->where('company_id', $company->id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'certificate' => ['nullable', 'file', 'max:200'],
            'private_key' => ['nullable', 'file', 'max:200'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $request->branch_id ?: null;
        $tillId = $request->till_id ?: null;

        if ($tillId) {
            $till = PosTill::where('company_id', $company->id)
                ->where('id', $tillId)
                ->first();

            if (! $till) {
                return response()->json([
                    'message' => 'Selected till is invalid.',
                ], 422);
            }

            if ($branchId && (string) $till->branch_id !== (string) $branchId) {
                return response()->json([
                    'message' => 'Selected till does not belong to the selected branch.',
                ], 422);
            }

            if (! $branchId) {
                $branchId = $till->branch_id;
            }
        }

        $duplicate = QzCertificate::where('company_id', $company->id)
            ->where('id', '!=', $certificate->id)
            ->where(function ($query) use ($branchId) {
                if ($branchId) {
                    $query->where('branch_id', $branchId);
                } else {
                    $query->whereNull('branch_id');
                }
            })
            ->where(function ($query) use ($tillId) {
                if ($tillId) {
                    $query->where('till_id', $tillId);
                } else {
                    $query->whereNull('till_id');
                }
            })
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'A QZ certificate already exists for this branch and till.',
            ], 422);
        }

        if ($request->hasFile('certificate') && $request->hasFile('private_key')) {
            $candidateCertificate = file_get_contents($request->file('certificate')->getRealPath());
            $candidatePrivateKey = file_get_contents($request->file('private_key')->getRealPath());

            if (! is_string($candidateCertificate)
                || ! is_string($candidatePrivateKey)
                || ! $this->credentialPairMatches($candidateCertificate, $candidatePrivateKey)) {
                return response()->json([
                    'message' => 'The private key does not match the uploaded QZ certificate.',
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            $folder = $this->buildStorageFolder(
                companyId: $company->id,
                branchId: $branchId,
                tillId: $tillId
            );

            $data = [
                'branch_id' => $branchId,
                'till_id' => $tillId,
                'name' => $request->name ?: null,
                'is_active' => $request->boolean('is_active', true),
                'notes' => $request->notes ?: null,
            ];

            if ($request->hasFile('certificate')) {
                $certificateContent = file_get_contents($request->file('certificate')->getRealPath());

                if (! $this->looksLikeCertificate($certificateContent)) {
                    return response()->json([
                        'message' => 'Invalid certificate file. Expected a PEM certificate.',
                    ], 422);
                }

                $certificatePath = "{$folder}/digital-certificate.txt";

                Storage::disk('qz_private')->put($certificatePath, $certificateContent);

                if (
                    $certificate->certificate_path &&
                    $certificate->certificate_path !== $certificatePath
                ) {
                    Storage::disk('qz_private')->delete($certificate->certificate_path);
                }

                $data['certificate_path'] = $certificatePath;
            }

            if ($request->hasFile('private_key')) {
                $privateKeyContent = file_get_contents($request->file('private_key')->getRealPath());

                if (! $this->looksLikePrivateKey($privateKeyContent)) {
                    return response()->json([
                        'message' => 'Invalid private key file. Expected a PEM private key.',
                    ], 422);
                }

                $privateKey = openssl_pkey_get_private($privateKeyContent);

                if (! $privateKey) {
                    return response()->json([
                        'message' => 'Private key could not be loaded by OpenSSL.',
                    ], 422);
                }

                $privateKeyPath = "{$folder}/private-key.pem";

                Storage::disk('qz_private')->put($privateKeyPath, $privateKeyContent);

                if (
                    $certificate->private_key_path &&
                    $certificate->private_key_path !== $privateKeyPath
                ) {
                    Storage::disk('qz_private')->delete($certificate->private_key_path);
                }

                $data['private_key_path'] = $privateKeyPath;
            }

            $certificate->update($data);

            DB::commit();

            return response()->json([
                'message' => 'QZ certificate updated successfully.',
                'data' => $certificate->fresh(['branch', 'till']),
            ], 200);
        } catch (QueryException $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Database error while updating QZ certificate.',
            ], 500);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while updating QZ certificate.',
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'printer_management', $company->id);
        if ($result !== true) {
            return $result;
        }

        $certificate = QzCertificate::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (! $certificate) {
            return response()->json([
                'message' => 'QZ certificate not found.',
            ], 404);
        }

        Storage::disk('qz_private')->delete($certificate->certificate_path);
        Storage::disk('qz_private')->delete($certificate->private_key_path);

        // QzCertificate deliberately uses hard deletes because the unique key
        // does not include deleted_at; a tombstone would block reinstallation.
        $certificate->delete();

        return response()->json([
            'message' => 'QZ certificate deleted successfully.',
        ], 200);
    }

    private function certificateExists(string $companyId, mixed $branchId = null, mixed $tillId = null): bool
    {
        return QzCertificate::where('company_id', $companyId)
            ->where(function ($query) use ($branchId) {
                if ($branchId) {
                    $query->where('branch_id', $branchId);
                } else {
                    $query->whereNull('branch_id');
                }
            })
            ->where(function ($query) use ($tillId) {
                if ($tillId) {
                    $query->where('till_id', $tillId);
                } else {
                    $query->whereNull('till_id');
                }
            })
            ->exists();
    }

    private function buildStorageFolder(string $companyId, mixed $branchId = null, mixed $tillId = null): string
    {
        $folder = "qz-certificates/company-{$companyId}";

        if ($branchId) {
            $folder .= "/branch-{$branchId}";
        } else {
            $folder .= '/global';
        }

        if ($tillId) {
            $folder .= "/till-{$tillId}";
        }

        return $folder;
    }

    private function looksLikeCertificate(string $content): bool
    {
        return str_contains($content, '-----BEGIN CERTIFICATE-----')
            && str_contains($content, '-----END CERTIFICATE-----');
    }

    private function looksLikePrivateKey(string $content): bool
    {
        return (
            str_contains($content, '-----BEGIN PRIVATE KEY-----')
            || str_contains($content, '-----BEGIN RSA PRIVATE KEY-----')
        ) && (
            str_contains($content, '-----END PRIVATE KEY-----')
            || str_contains($content, '-----END RSA PRIVATE KEY-----')
        );
    }

    private function credentialPairMatches(string $certificatePem, string $privateKeyPem): bool
    {
        $publicKey = openssl_pkey_get_public($certificatePem);
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        $publicDetails = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
        $privateDetails = $privateKey !== false ? openssl_pkey_get_details($privateKey) : false;

        return is_array($publicDetails)
            && is_array($privateDetails)
            && ! empty($publicDetails['key'])
            && ! empty($privateDetails['key'])
            && hash_equals(trim($publicDetails['key']), trim($privateDetails['key']));
    }
}
