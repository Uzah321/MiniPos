<?php

declare(strict_types=1);

// Called by the Windows installer before it generates php.ini. Never print
// certificate, key, or phpinfo material into installer logs.
//
//   php Check-TlsPair.php <certificate.pem> <private-key.pem> [<minimum-days-remaining>]
//
// Exit codes: 0 valid, 2 unreadable input, 3 key does not match,
// 4 outside validity dates or expiring within the minimum days.
if (($argc !== 3 && $argc !== 4) || !is_file($argv[1]) || !is_file($argv[2])) {
    exit(2);
}
$minimumDays = isset($argv[3]) ? max(0, (int) $argv[3]) : 0;

$certificatePem = @file_get_contents($argv[1]);
$privateKeyPem = @file_get_contents($argv[2]);
if ($certificatePem === false || $privateKeyPem === false) {
    exit(2);
}

$certificate = @openssl_x509_read($certificatePem);
$privateKey = @openssl_pkey_get_private($privateKeyPem);
if (!$certificate || !$privateKey || !openssl_x509_check_private_key($certificate, $privateKey)) {
    exit(3);
}

$details = openssl_x509_parse($certificate);
if (!$details || ($details['validFrom_time_t'] ?? 0) > time()
    || ($details['validTo_time_t'] ?? 0) <= time() + ($minimumDays * 86400)) {
    exit(4);
}

exit(0);
