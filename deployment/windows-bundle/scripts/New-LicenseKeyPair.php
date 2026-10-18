<?php

declare(strict_types=1);

// Creates the Central Back Office licence signing key pair, or derives the
// public key from an operator-supplied unencrypted private key.
//
//   php New-LicenseKeyPair.php <private-out.pem> <public-out.pem> [<existing-private.pem>]
//
// Exit codes: 0 written, 2 bad arguments, 3 invalid existing key,
// 4 key generation failed, 5 output could not be written.
// Key material is never printed.
if ($argc !== 3 && $argc !== 4) {
    exit(2);
}

[$script, $privateOut, $publicOut] = $argv;
$existingPath = $argv[3] ?? null;

// PHP on Windows needs an explicit OpenSSL configuration for key operations.
$configPath = tempnam(sys_get_temp_dir(), 'snkey');
file_put_contents($configPath, "[ req ]\ndistinguished_name = req_dn\n[ req_dn ]\n");
$options = ['config' => $configPath];

try {
    if ($existingPath !== null) {
        $pem = @file_get_contents($existingPath);
        $key = $pem === false ? false : @openssl_pkey_get_private($pem);
        if ($key === false) {
            exit(3);
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !in_array($details['type'] ?? null, [OPENSSL_KEYTYPE_RSA, OPENSSL_KEYTYPE_EC], true)
            || (($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA && ($details['bits'] ?? 0) < 2048)) {
            exit(3);
        }
    } else {
        $key = @openssl_pkey_new($options + [
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 3072,
        ]);
        if ($key === false) {
            exit(4);
        }
        $details = openssl_pkey_get_details($key);
    }

    $privatePem = '';
    if (!@openssl_pkey_export($key, $privatePem, null, $options) || !is_array($details) || empty($details['key'])) {
        exit(4);
    }

    if (@file_put_contents($privateOut, $privatePem) === false
        || @file_put_contents($publicOut, $details['key']) === false) {
        exit(5);
    }
} finally {
    @unlink($configPath);
}

exit(0);
