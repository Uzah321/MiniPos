<?php

declare(strict_types=1);

// Creates a self-signed HTTPS certificate for the Central Back Office. Shop
// POS servers trust exactly this certificate through the shop connection
// file, so TLS verification stays enabled without a public CA.
//
//   php New-SelfSignedTls.php <cert-out.pem> <key-out.pem> <common-name> <alt-name> [<alt-name> ...]
//
// Alternative names that parse as IP addresses become IP entries, the rest
// DNS entries. Exit codes: 0 written, 2 bad arguments, 4 generation failed,
// 5 output could not be written. Key material is never printed.
if ($argc < 5) {
    exit(2);
}

[$script, $certificateOut, $keyOut, $commonName] = $argv;
$alternativeNames = array_values(array_unique(array_slice($argv, 4)));
$entries = [];
foreach ($alternativeNames as $name) {
    if (filter_var($name, FILTER_VALIDATE_IP) !== false) {
        $entries[] = 'IP:'.$name;
    } elseif (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $name) === 1) {
        $entries[] = 'DNS:'.$name;
    } else {
        exit(2);
    }
}
if (preg_match('/^[A-Za-z0-9.-]{1,64}$/', $commonName) !== 1) {
    exit(2);
}

$configPath = tempnam(sys_get_temp_dir(), 'sntls');
file_put_contents($configPath, implode("\n", [
    '[ req ]',
    'distinguished_name = req_dn',
    'prompt = no',
    '[ req_dn ]',
    'CN = '.$commonName,
    '[ servenest_server ]',
    // A self-signed certificate is its own trust anchor in the shops' CA
    // bundle and in the Windows root store, so it is marked as a CA.
    'basicConstraints = critical, CA:TRUE, pathlen:0',
    'keyUsage = critical, digitalSignature, keyEncipherment, keyCertSign',
    'extendedKeyUsage = serverAuth',
    'subjectKeyIdentifier = hash',
    'subjectAltName = '.implode(', ', $entries),
    '',
]));
$options = [
    'config' => $configPath,
    'digest_alg' => 'sha256',
    'x509_extensions' => 'servenest_server',
];

try {
    $key = @openssl_pkey_new([
        'config' => $configPath,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);
    if ($key === false) {
        exit(4);
    }
    $csr = @openssl_csr_new(['commonName' => $commonName], $key, $options);
    if ($csr === false) {
        exit(4);
    }
    // 825 days is the longest lifetime browsers accept for a trusted server certificate.
    $certificate = @openssl_csr_sign($csr, null, $key, 825, $options, random_int(1, PHP_INT_MAX));
    if ($certificate === false) {
        exit(4);
    }

    $certificatePem = '';
    $keyPem = '';
    if (!@openssl_x509_export($certificate, $certificatePem)
        || !@openssl_pkey_export($key, $keyPem, null, ['config' => $configPath])) {
        exit(4);
    }
    if (@file_put_contents($certificateOut, $certificatePem) === false
        || @file_put_contents($keyOut, $keyPem) === false) {
        exit(5);
    }
} finally {
    @unlink($configPath);
}

exit(0);
