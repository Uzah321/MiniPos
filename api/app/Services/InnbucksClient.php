<?php

// app/Services/InnbucksClient.php
namespace App\Services;


use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Modules\Inbucks\Models\InnbucksCredidentials;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InnbucksClient
{
    public function __construct(private InnbucksCredidentials $cred) {}

    private function baseRequest(): PendingRequest
    {
        return Http::baseUrl($this->cred->base_url)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->withHeaders([
                'X-Api-Key' => $this->cred->api_key,
            ]);
    }

    private function cacheKey(string $suffix): string
    {
        // cache per shop+env
        return "innbucks:{$this->cred->environment}:brand{$this->cred->brand_id}:complex{$this->cred->complex_id}:{$suffix}";
    }

    private function getAccessToken(): string
    {
        return Cache::remember($this->cacheKey('access_token'), now()->addMinutes(14), function () {
            $res = $this->baseRequest()->post('/auth/third-party', [
                'username' => $this->cred->username,
                'password' => $this->cred->password,
            ]);

            $data = $res->json() ?? [];

            if (!$res->successful() || ($data['responseCode'] ?? null) !== '00') {
                throw new RuntimeException("InnBucks login failed: HTTP {$res->status()} - {$res->body()}");
            }



            return $data['accessToken'];
        });
    }

    private function authRequest(): PendingRequest
    {
        return $this->baseRequest()->withToken($this->getAccessToken());
    }

    private function sendWithRetry(callable $fn): Response
    {
        $res = $fn();

        if ($res->status() === 401) {
            Cache::forget($this->cacheKey('access_token'));
            $res = $fn();
        }

        return $res;
    }

//    private function handle(Response $res): array
//    {
//        $data = $res->json() ?? [];
//        $code = $data['responseCode'] ?? null;
//
//        // login success uses "00"; other endpoints commonly use 0/"0"
//        $ok = $res->successful() && in_array($code, [0, '0', '00'], true);
//        if (!$ok) {
//            $traceId = $res->header('X-Trace-Id');
//            throw new HttpException(
//                $res->status(),
//                "InnBucks API error: HTTP {$res->status()}, trace=" . ($traceId ?: 'N/A') . " body={$res->body()}"
//            );
//        }
//
//        $data['_traceId'] = $res->header('X-Trace-Id');
//        return $data;
   // }
    private function handle(Response $res): array
    {
        $data = $res->json() ?? [];
        $traceId = $res->header('X-Trace-Id');

        // If HTTP failed, always error
        if (!$res->successful()) {
            $springErr = $data['error'] ?? null;
            $springPath = $data['path'] ?? null;

            $msg = $data['responseMsg']
                ?? $data['responseDescription']
                ?? $data['message']
                ?? ($springErr ? "{$springErr} at {$springPath}" : null)
                ?? 'InnBucks API error';

            throw new HttpException(
                $res->status(),
                "InnBucks API error: HTTP {$res->status()}, trace=" . ($traceId ?: 'N/A') . " msg={$msg} body={$res->body()}"
            );
        }

        // HTTP is 2xx from here

        // If responseCode exists, enforce it
        if (array_key_exists('responseCode', $data)) {
            $code = $data['responseCode'];
            $ok = in_array($code, [0, '0', '00'], true);

            if (!$ok) {
                $springErr = $data['error'] ?? null;
                $springPath = $data['path'] ?? null;

                $msg = $data['responseMsg']
                    ?? $data['responseDescription']
                    ?? $data['message']
                    ?? ($springErr ? "{$springErr} at {$springPath}" : null)
                    ?? 'InnBucks API error';

                throw new HttpException(
                    $res->status(),
                    "InnBucks API error: HTTP {$res->status()}, trace=" . ($traceId ?: 'N/A') . " msg={$msg} body={$res->body()}"
                );
            }
        }

        // Success: attach trace and return
        $data['_traceId'] = $traceId;
        return $data;
    }


    // --- API methods ---

    public function generateCode(string $type, string $amountCents, string $narration, ?string $reference = null): array
    {

        $res = $this->sendWithRetry(fn () =>
        $this->authRequest()->post('/api/code/generate', [
            'reference' => $reference,
            'narration' => $narration,
            'amount'    => $amountCents,
            'type'      => $type, // PAYMENT | WITHDRAWAL
        ])
        );

        return $this->handle($res);
    }

    public function queryCode(string $code, ?string $reference = null): array
    {
        $res = $this->sendWithRetry(fn () =>
        $this->authRequest()->post('/api/code/query', [
            'reference' => $reference,
            'code'      => $code,
        ])
        );

        return $this->handle($res);
    }

    public function miniStatement(string $accountId): array
    {
        $res = $this->sendWithRetry(fn () =>
        $this->authRequest()->get("/api/code/{$accountId}/miniStatement")
        );

        return $this->handle($res);
    }

    public function deposit(string $destinationAccount, int $amountCents, string $narration, string $reference): array
    {
        $res = $this->sendWithRetry(fn () =>
        $this->authRequest()->post('/api/transaction/deposit', [
            'reference'          => $reference,
            'amount'             => $amountCents,
            'narration'          => $narration,
            'destinationAccount' => $destinationAccount,
        ])
        );

        return $this->handle($res);
    }


    public function bankChange(string $destinationMsisdn, float $amountCents, string $narration, string $reference): array
    {


        $res = $this->sendWithRetry(fn () =>
        $this->authRequest()->post('/api/transaction/bankChange', [
            'reference'         => $reference,
            'amount'            => $amountCents,
            'narration'         => $narration,
            'destinationMsisdn' => $destinationMsisdn, // e.g. 263770123123
        ])
        );

        return $this->handle($res);
    }


    /**
     * 10. Linked Account Inquiry
     * GET /api/account/msisdn/{msisdn}/details
     * MSISDN format: 263772123123
     */
    public function linkedAccountDetails(string $msisdn): array
    {
        // basic cleanup: remove spaces, leading +, etc.
        $msisdn = preg_replace('/\s+/', '', $msisdn);
        $msisdn = ltrim($msisdn, '+');

        $res = $this->sendWithRetry(fn () =>
        $this->authRequest()->get("/api/account/msisdn/{$msisdn}/details")
        );

        return $this->handle($res);
    }

    /**
     * 11. Deposit Inquiry
     * POST /bank/api/transaction/inquiry
     *
     * accountNumber: destinationAccount used in original deposit
     * originalParticipantReference: reference used in original deposit
     * participantReference: unique reference for this inquiry request
     */
    public function depositInquiry(
        string $accountNumber,
        string $participantReference,
        string $originalParticipantReference
    ): array {
        $payload = [
            'accountNumber' => $accountNumber,
            'participantReference' => $participantReference,
            'originalParticipantReference' => $originalParticipantReference,
        ];

        $res = $this->sendWithRetry(fn () =>
        $this->authRequest()->post('/bank/api/transaction/inquiry', $payload)
        );

        return $this->handle($res);
    }


}

