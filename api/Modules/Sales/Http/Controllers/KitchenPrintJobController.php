<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\GeneralSettings\Models\PosTill;
use Modules\Sales\Models\HospitalityTableOrder;
use Modules\Sales\Models\KitchenPrintJob;
use Modules\Sales\Models\SalesTransaction;
use Modules\Sales\Services\HospitalityOutputConfigurationService;

class KitchenPrintJobController extends Controller
{
    public function __construct(
        private readonly HospitalityOutputConfigurationService $output
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['nullable', 'string', Rule::in([
                'pending',
                'pending_configuration',
                'failed',
                'printed',
            ])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $till = $this->currentTill();
        $query = $this->scopedJobs($till);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        } else {
            $query->whereIn('status', ['pending', 'pending_configuration', 'failed']);
        }

        $jobs = $query
            ->orderBy('created_at')
            ->limit((int) ($request->input('limit') ?: 50))
            ->get();

        return response()->json([
            'message' => 'Kitchen print jobs fetched successfully.',
            'data' => $jobs,
            'output' => $this->output->forTill($till),
        ]);
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', Rule::in(['printed', 'failed'])],
            'error_message' => ['nullable', 'string', 'max:2000'],
            // Backward compatibility for terminals deployed before the
            // canonical error_message field was introduced.
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $till = $this->currentTill();
        $status = (string) $validator->validated()['status'];
        $errorMessage = trim((string) (
            $request->input('error_message') ?? $request->input('error')
        ));
        if ($status === 'failed' && $errorMessage === '') {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => [
                    'error_message' => ['An error message is required when a kitchen print job fails.'],
                ],
            ], 422);
        }

        $result = DB::transaction(function () use ($till, $id, $status, $errorMessage): array {
            $job = $this->scopedJobs($till)->whereKey($id)->lockForUpdate()->first();
            if (! $job) {
                return ['missing' => true];
            }

            if ($job->status === $status) {
                return ['job' => $job, 'idempotent' => true];
            }
            if ($job->status === 'printed') {
                return ['conflict' => 'A printed kitchen ticket cannot be changed to failed.'];
            }

            $updates = [
                'status' => $status,
                'printed_at' => $status === 'printed' ? now() : null,
            ];
            if (Schema::hasColumn('kitchen_print_jobs', 'attempt_count')) {
                $updates['attempt_count'] = ((int) $job->attempt_count) + 1;
            }
            if (Schema::hasColumn('kitchen_print_jobs', 'last_attempt_at')) {
                $updates['last_attempt_at'] = now();
            }
            if (Schema::hasColumn('kitchen_print_jobs', 'failed_at')) {
                $updates['failed_at'] = $status === 'failed' ? now() : null;
            }
            if (Schema::hasColumn('kitchen_print_jobs', 'error_message')) {
                $updates['error_message'] = $status === 'failed'
                    ? $errorMessage
                    : null;
            }
            if (Schema::hasColumn('kitchen_print_jobs', 'acknowledged_by')) {
                $updates['acknowledged_by'] = auth()->id();
            }
            $job->forceFill($updates)->save();
            $this->mirrorTableOrderKitchenStatus($job, $status);

            $saleUpdates = ['kitchen_print_status' => $status];
            if ($status === 'printed' && Schema::hasColumn('sales_transactions', 'kitchen_printed_at')) {
                $saleUpdates['kitchen_printed_at'] = now();
            }
            if ($job->sales_transaction_id
                && Schema::hasColumn('sales_transactions', 'kitchen_print_status')) {
                SalesTransaction::query()
                    ->where('company_id', $till->company_id)
                    ->where('branch_id', $till->branch_id)
                    ->where('till_id', $till->id)
                    ->whereKey($job->sales_transaction_id)
                    ->update($saleUpdates);
            }

            if ($job->sales_transaction_id
                && Schema::hasTable('receipt_lines')
                && Schema::hasColumn('receipt_lines', 'kitchen_print_status')) {
                $lineUpdates = ['kitchen_print_status' => $status];
                if ($status === 'printed' && Schema::hasColumn('receipt_lines', 'kitchen_printed_at')) {
                    $lineUpdates['kitchen_printed_at'] = now();
                }
                DB::table('receipt_lines')
                    ->where('sales_transaction_id', $job->sales_transaction_id)
                    ->update($lineUpdates);
            }

            return ['job' => $job->fresh() ?: $job, 'idempotent' => false];
        }, 3);

        if (isset($result['missing'])) {
            return response()->json(['message' => 'Kitchen print job not found.'], 404);
        }
        if (isset($result['conflict'])) {
            return response()->json([
                'message' => $result['conflict'],
                'code' => 'KITCHEN_PRINT_ALREADY_ACKNOWLEDGED',
            ], 409);
        }

        return response()->json([
            'message' => $status === 'printed'
                ? 'Kitchen print job acknowledged as printed.'
                : 'Kitchen print failure recorded.',
            'data' => $result['job'],
            'idempotent' => (bool) $result['idempotent'],
        ]);
    }

    public function retry(string $id): JsonResponse
    {
        $till = $this->currentTill();
        $runtime = $this->output->forTill($till);

        if (! ($runtime['ready'] ?? false)) {
            return response()->json([
                'message' => 'The QZ kitchen printer is not ready for this terminal.',
                'code' => 'KITCHEN_PRINTER_NOT_READY',
                'output' => $runtime,
            ], 409);
        }

        $result = DB::transaction(function () use ($till, $id, $runtime): array {
            $job = $this->scopedJobs($till)->whereKey($id)->lockForUpdate()->first();
            if (! $job) {
                return ['missing' => true];
            }
            if ($job->status === 'printed') {
                return ['conflict' => true];
            }

            $updates = [
                'status' => 'pending',
                'printed_at' => null,
                'printer_id' => data_get($runtime, 'printer.id'),
                'printer_name' => data_get($runtime, 'printer.printer_name'),
            ];
            foreach (['failed_at', 'last_attempt_at', 'error_message', 'acknowledged_by'] as $column) {
                if (Schema::hasColumn('kitchen_print_jobs', $column)) {
                    $updates[$column] = null;
                }
            }
            $job->forceFill($updates)->save();
            $this->mirrorTableOrderKitchenStatus($job, 'queued');

            if ($job->sales_transaction_id
                && Schema::hasColumn('sales_transactions', 'kitchen_print_status')) {
                SalesTransaction::query()
                    ->where('company_id', $till->company_id)
                    ->where('branch_id', $till->branch_id)
                    ->where('till_id', $till->id)
                    ->whereKey($job->sales_transaction_id)
                    ->update(['kitchen_print_status' => 'pending']);
            }

            return ['job' => $job->fresh() ?: $job];
        }, 3);

        if (isset($result['missing'])) {
            return response()->json(['message' => 'Kitchen print job not found.'], 404);
        }
        if (isset($result['conflict'])) {
            return response()->json([
                'message' => 'A printed kitchen ticket cannot be retried.',
                'code' => 'KITCHEN_PRINT_ALREADY_ACKNOWLEDGED',
            ], 409);
        }

        return response()->json([
            'message' => 'Kitchen print job queued for retry.',
            'data' => $result['job'],
            'output' => $runtime,
        ]);
    }

    private function scopedJobs(PosTill $till)
    {
        return KitchenPrintJob::query()
            ->where('company_id', $till->company_id)
            ->where('branch_id', $till->branch_id)
            ->where('till_id', $till->id)
            ->where('target_printing', 'kitchen');
    }

    private function mirrorTableOrderKitchenStatus(KitchenPrintJob $job, string $status): void
    {
        if (! $job->hospitality_table_order_id || ! $job->dispatch_revision) {
            return;
        }

        HospitalityTableOrder::query()
            ->whereKey($job->hospitality_table_order_id)
            ->where('company_id', $job->company_id)
            ->where('branch_id', $job->branch_id)
            ->where('last_kitchen_content_revision', $job->dispatch_revision)
            ->update([
                'kitchen_status' => $status,
                'updated_at' => now(),
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
