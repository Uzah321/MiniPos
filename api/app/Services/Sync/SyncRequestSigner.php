<?php

namespace App\Services\Sync;

use InvalidArgumentException;

class SyncRequestSigner
{
    public function signature(
        string $method,
        string $requestTarget,
        string $timestamp,
        string $nonce,
        string $body,
        string $rawCredential,
        string $nodeId = ''
    ): string {
        if ($rawCredential === '') {
            throw new InvalidArgumentException('The sync credential is required.');
        }

        $canonical = $this->canonicalRequest($method, $requestTarget, $timestamp, $nonce, $body, $nodeId);

        // The encrypted raw credential is the HMAC key. credential_hash is
        // only a fingerprint for integrity/audit; using that database-visible
        // hash as the key would make a read-only DB disclosure forge-capable.
        return hash_hmac('sha256', $canonical, $rawCredential);
    }

    public function canonicalRequest(
        string $method,
        string $requestTarget,
        string $timestamp,
        string $nonce,
        string $body,
        string $nodeId = ''
    ): string {
        $target = '/'.ltrim(trim($requestTarget), '/');

        return implode("\n", [
            strtoupper(trim($method)),
            $target,
            trim($nodeId),
            trim($timestamp),
            trim($nonce),
            hash('sha256', $body),
        ]);
    }
}
