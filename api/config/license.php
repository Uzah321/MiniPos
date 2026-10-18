<?php

$deploymentMode = strtolower((string) env(
    'SERVENEST_DEPLOYMENT_MODE',
    env('SALEXA_DEPLOYMENT_MODE', env('SALEXA_SYNC_DEPLOYMENT_MODE', 'standalone'))
));
$defaultSigningMode = $deploymentMode === 'cloud' ? 'issuer' : 'verify_only';
$configuredSigningMode = strtolower(trim((string) env('LICENSE_SIGNING_MODE', '')));
$signingMode = $deploymentMode === 'edge'
    ? 'verify_only'
    : (in_array($configuredSigningMode, ['issuer', 'verify_only'], true)
        ? $configuredSigningMode
        : $defaultSigningMode);
$legacyCompatibilityDefault = $signingMode === 'issuer' && $deploymentMode !== 'edge';
$activeKeyId = trim((string) env('LICENSE_SIGNATURE_KEY_ID', ''));
$publicKeys = json_decode((string) env('LICENSE_SIGNATURE_PUBLIC_KEYS', '{}'), true);
$publicKeys = is_array($publicKeys) ? $publicKeys : [];
$singlePublicKey = trim((string) env('LICENSE_SIGNATURE_PUBLIC_KEY', ''));

if ($activeKeyId !== '' && $singlePublicKey !== '') {
    $publicKeys[$activeKeyId] = $singlePublicKey;
}

return [
    'warning_days' => (int) env('LICENSE_WARNING_DAYS', 90),
    'default_support_phone' => env('LICENSE_SUPPORT_PHONE'),
    // Platform access is deliberately separate from a tenant's Super Admin
    // role. Only globally unique POS-user IDs configured by the operator are
    // trusted, and the service also requires that user to remain a Super Admin.
    'platform_admin_pos_user_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PLATFORM_ADMIN_POS_USER_IDS', ''))
    ))),
    'legacy_platform_admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('LEGACY_PLATFORM_ADMIN_EMAILS', ''))
    ))),
    'signing' => [
        // Cloud is the default issuer. Edge nodes are always verify-only;
        // standalone installations are verify-only unless an operator
        // deliberately configures a self-authorizing issuer.
        'mode' => $signingMode,
        'algorithm' => strtolower((string) env('LICENSE_SIGNATURE_ALGORITHM', 'openssl-sha256')),
        'active_key_id' => $activeKeyId,
        'private_key' => env('LICENSE_SIGNATURE_PRIVATE_KEY'),
        'private_key_passphrase' => env('LICENSE_SIGNATURE_PRIVATE_KEY_PASSPHRASE'),
        'public_keys' => $publicKeys,

        // Transitional compatibility switches. A v2 entitlement is never
        // downgraded to these legacy paths even when they are enabled.
        'legacy_hmac_verify' => filter_var(env('LICENSE_LEGACY_HMAC_VERIFY', $legacyCompatibilityDefault), FILTER_VALIDATE_BOOL),
        'legacy_hmac_sign' => filter_var(env('LICENSE_LEGACY_HMAC_SIGN', $legacyCompatibilityDefault), FILTER_VALIDATE_BOOL),
        'legacy_hmac_key' => env('LICENSE_LEGACY_HMAC_KEY'),
        'legacy_app_key_fallback' => filter_var(env('LICENSE_LEGACY_APP_KEY_FALLBACK', $legacyCompatibilityDefault), FILTER_VALIDATE_BOOL),
        'allow_unsigned_legacy' => filter_var(env('LICENSE_ALLOW_UNSIGNED_LEGACY', $legacyCompatibilityDefault), FILTER_VALIDATE_BOOL),
        'auto_upgrade_unsigned' => filter_var(env('LICENSE_AUTO_UPGRADE_UNSIGNED', $legacyCompatibilityDefault), FILTER_VALIDATE_BOOL),
        'legacy_offline_token_verify' => filter_var(env('LICENSE_LEGACY_OFFLINE_TOKEN_VERIFY', $legacyCompatibilityDefault), FILTER_VALIDATE_BOOL),
    ],
];
