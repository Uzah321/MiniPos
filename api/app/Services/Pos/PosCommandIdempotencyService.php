<?php

namespace App\Services\Pos;

use App\Models\PosCommandReceipt;
use App\Services\Sync\SyncPayloadHasher;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PosCommandIdempotencyService
{
    public function __construct(private readonly SyncPayloadHasher $hasher) {}

    /**
     * Read a completed receipt before resolving mutable state such as an open
     * shift. This is what lets a retry made after the shift was closed receive
     * the original success response.
     *
     * @return array{receipt: PosCommandReceipt|null, response: Response|null}
     */
    public function inspect(
        Request $request,
        string $commandType,
        string $companyId,
        string $branchId,
        string $tillId,
        string $userId
    ): array {
        $clientActionId = trim((string) $request->input('client_action_id'));
        if ($clientActionId === '') {
            return ['receipt' => null, 'response' => null];
        }

        $receipt = PosCommandReceipt::query()
            ->where('client_action_id', $clientActionId)
            ->first();
        if (! $receipt) {
            return ['receipt' => null, 'response' => null];
        }

        return $this->existingResult(
            $receipt,
            $request,
            $commandType,
            $companyId,
            $branchId,
            $tillId,
            $userId
        );
    }

    /**
     * Claim the command from inside the same transaction as its business
     * mutation. Locking the till serializes retries at one physical terminal.
     *
     * @return array{receipt: PosCommandReceipt|null, response: Response|null}
     */
    public function claim(
        Request $request,
        string $commandType,
        string $companyId,
        string $branchId,
        string $tillId,
        string $userId
    ): array {
        $clientActionId = trim((string) $request->input('client_action_id'));
        if ($clientActionId === '') {
            return ['receipt' => null, 'response' => null];
        }

        // The till was already resolved from X-Till-Token. Locking that stable
        // scope row prevents two retries for this terminal from both observing
        // an absent receipt and executing the command.
        DB::table('pos_tills')
            ->where('id', $tillId)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->value('id');

        $receipt = PosCommandReceipt::query()
            ->where('client_action_id', $clientActionId)
            ->lockForUpdate()
            ->first();
        if ($receipt) {
            return $this->existingResult(
                $receipt,
                $request,
                $commandType,
                $companyId,
                $branchId,
                $tillId,
                $userId
            );
        }

        try {
            $receipt = PosCommandReceipt::query()->create([
                'client_action_id' => $clientActionId,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'till_id' => $tillId,
                'user_id' => $userId,
                'command_type' => $commandType,
                'request_hash' => $this->requestHash($request, $commandType),
                'status' => 'processing',
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            // Two different till locks can contend only when a caller reuses
            // the same globally unique UUID across scopes. Resolve the winner
            // and apply the normal scope/hash rejection instead of leaking a
            // database exception as a 500 response.
            $receipt = PosCommandReceipt::query()
                ->where('client_action_id', $clientActionId)
                ->lockForUpdate()
                ->first();
            if (! $receipt) {
                throw $exception;
            }

            return $this->existingResult(
                $receipt,
                $request,
                $commandType,
                $companyId,
                $branchId,
                $tillId,
                $userId
            );
        }

        return ['receipt' => $receipt, 'response' => null];
    }

    public function complete(PosCommandReceipt $receipt, Response $response): void
    {
        $receipt->forceFill([
            'status' => 'completed',
            'response_status' => $response->getStatusCode(),
            'response_content_type' => $response->headers->get('Content-Type', 'application/json'),
            'response_body' => (string) $response->getContent(),
            'completed_at' => now(),
        ])->save();
    }

    /** @return array{receipt: PosCommandReceipt|null, response: Response|null} */
    private function existingResult(
        PosCommandReceipt $receipt,
        Request $request,
        string $commandType,
        string $companyId,
        string $branchId,
        string $tillId,
        string $userId
    ): array {
        $sameScope = hash_equals((string) $receipt->company_id, $companyId)
            && hash_equals((string) $receipt->branch_id, $branchId)
            && hash_equals((string) $receipt->till_id, $tillId)
            && hash_equals((string) $receipt->user_id, $userId)
            && hash_equals((string) $receipt->command_type, $commandType)
            && hash_equals(
                (string) $receipt->request_hash,
                $this->requestHash($request, $commandType)
            );

        if (! $sameScope) {
            return [
                'receipt' => null,
                'response' => response()->json([
                    'message' => 'This client action id was already used by a different command or terminal.',
                    'code' => 'POS_CLIENT_ACTION_ID_REUSED',
                ], 409),
            ];
        }

        if ($receipt->status !== 'completed'
            || $receipt->response_status === null
            || $receipt->response_body === null) {
            return [
                'receipt' => $receipt,
                'response' => response()->json([
                    'message' => 'This POS action is already being processed. Retry shortly with the same client action id.',
                    'code' => 'POS_COMMAND_IN_PROGRESS',
                ], 409),
            ];
        }

        $response = response(
            (string) $receipt->response_body,
            (int) $receipt->response_status,
            ['Content-Type' => (string) ($receipt->response_content_type ?: 'application/json')]
        );
        $response->headers->set('X-Idempotent-Replayed', 'true');

        return ['receipt' => $receipt, 'response' => $response];
    }

    private function requestHash(Request $request, string $commandType): string
    {
        return $this->hasher->hash([
            'command_type' => $commandType,
            'body' => $request->all(),
        ]);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), [
            '23000', // MySQL / SQLite integrity constraint violation
            '23505', // PostgreSQL unique violation
        ], true);
    }
}
