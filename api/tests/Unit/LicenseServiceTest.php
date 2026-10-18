<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Auth\SuperAdminService;
use App\Services\License\LicenseAccessService;
use App\Services\License\LicenseService;
use App\Services\License\LicenseSignatureService;
use App\Services\License\MobileLicenseEntitlementException;
use App\Services\License\MobileLicenseEntitlementService;
use Carbon\Carbon;
use Mockery;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Company;
use Modules\Company\Models\License;
use Modules\RolesAndPermission\Models\Role;
use Tests\TestCase;

class LicenseServiceTest extends TestCase
{
    private static ?string $privateKey = null;

    private static ?string $publicKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('license.warning_days', 90);
        $this->configureIssuer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_new_keys_are_compact_and_user_input_is_normalized(): void
    {
        $service = new class extends LicenseService
        {
            protected function licenseKeyExists(string $key): bool
            {
                return false;
            }
        };

        $key = $service->generateLicenseKey();

        $this->assertMatchesRegularExpression(LicenseService::KEY_PATTERN, $key);
        $this->assertSame($key, $service->normalizeLicenseKey(strtolower(str_replace('-', ' ', $key))));
        $this->expectException(\InvalidArgumentException::class);
        $service->normalizeLicenseKey('SALEXA-VERY-LONG-LEGACY-LICENSE');
    }

    public function test_entitlement_is_signed_and_tampering_is_rejected(): void
    {
        Carbon::setTestNow('2026-10-05 23:30:00');
        $service = app(LicenseService::class);
        $license = $this->license(['expires_at' => '2026-10-05']);

        $service->signEntitlement($license);

        $this->assertNotEmpty($license->entitlement_signature_v2);
        $this->assertSame('openssl-sha256', $license->signature_algorithm);
        $this->assertSame(2, $license->entitlement_version);
        $this->assertSame('LICENSE_VALID', $service->validationCode($license));
        $this->assertArrayNotHasKey('entitlement_payload', $license->toArray());
        $this->assertArrayNotHasKey('entitlement_signature', $license->toArray());
        $this->assertArrayNotHasKey('entitlement_payload_v2', $license->toArray());
        $this->assertArrayNotHasKey('entitlement_signature_v2', $license->toArray());
        $this->assertArrayNotHasKey('license_token', $license->toArray());

        $keyId = $license->signature_key_id;
        $license->signature_key_id = 'unknown-key';
        $this->assertSame('LICENSE_TAMPERED', $service->validationCode($license));

        $license->signature_key_id = $keyId;
        $license->max_users = 999;
        $this->assertSame('LICENSE_TAMPERED', $service->validationCode($license));
    }

    public function test_unsigned_legacy_license_is_lazily_signed(): void
    {
        $license = $this->license([
            'license_key' => 'SALEXA-ENT-LEGACY-ABCDEFGHIJ',
            'entitlement_payload' => null,
            'entitlement_signature' => null,
        ]);

        $this->assertTrue(app(LicenseService::class)->isValid($license));
        $this->assertNotEmpty($license->entitlement_signature_v2);
    }

    public function test_asymmetric_entitlement_verifies_with_a_different_app_key(): void
    {
        $license = $this->license();
        $service = app(LicenseService::class);
        $service->signEntitlement($license);

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('e', 32)));
        config()->set('license.signing.mode', 'verify_only');
        config()->set('license.signing.private_key', null);

        $this->assertTrue($service->verifyEntitlement($license));
        $this->assertSame('LICENSE_VALID', $service->validationCode($license));
    }

    public function test_verify_only_node_rejects_unsigned_records_and_cannot_issue(): void
    {
        config()->set('license.signing.mode', 'verify_only');
        config()->set('license.signing.private_key', null);
        $service = app(LicenseService::class);
        $license = $this->license();

        $this->assertFalse($service->verifyEntitlement($license));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not configured to issue');
        $service->signEntitlement($license);
    }

    public function test_public_key_ring_supports_rotation_without_reissuing_every_license_at_once(): void
    {
        $service = app(LicenseService::class);
        $license = $this->license();
        $service->signEntitlement($license);
        $oldLicense = clone $license;
        [$rotatedPrivateKey, $rotatedPublicKey] = $this->generateOpenSslKeyPair();

        config()->set('license.signing.active_key_id', 'test-rotated');
        config()->set('license.signing.private_key', $rotatedPrivateKey);
        config()->set('license.signing.public_keys', [
            'test-primary' => self::$publicKey,
            'test-rotated' => $rotatedPublicKey,
        ]);
        $service->signEntitlement($license);

        $this->assertTrue($service->verifyEntitlement($oldLicense));
        $this->assertTrue($service->verifyEntitlement($license));
        $this->assertSame('test-rotated', $license->signature_key_id);

        config()->set('license.signing.public_keys', ['test-rotated' => $rotatedPublicKey]);
        $this->assertFalse($service->verifyEntitlement($oldLicense));
        $this->assertTrue($service->verifyEntitlement($license));
    }

    public function test_v2_material_never_downgrades_to_a_valid_legacy_signature(): void
    {
        $service = app(LicenseService::class);
        $license = $this->license();

        config()->set('license.signing.private_key', null);
        $service->signEntitlement($license);
        $this->assertTrue($service->verifyEntitlement($license));

        $this->configureIssuer();
        $service->signEntitlement($license);
        $license->entitlement_signature_v2 = base64_encode('tampered-signature');

        $this->assertFalse($service->verifyEntitlement($license));
    }

    public function test_legacy_offline_token_parser_requires_its_compatibility_flag(): void
    {
        $payload = base64_encode(json_encode([
            'company_id' => 'company-a',
            'expires_at' => '2027-01-01',
        ], JSON_THROW_ON_ERROR));
        $legacyKey = str_repeat('k', 32);
        $token = $payload.'.'.hash_hmac('sha256', $payload, $legacyKey);
        $service = app(LicenseService::class);

        $this->assertSame('company-a', $service->validateOfflineLicenseToken($token)['company_id']);

        config()->set('license.signing.legacy_offline_token_verify', false);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid legacy license token format');
        $service->validateOfflineLicenseToken($token);
    }

    public function test_v2_offline_token_is_portable_and_rejects_tampering(): void
    {
        $service = app(LicenseService::class);
        $company = new Company(['company_name' => 'Portable Company']);
        $company->forceFill(['id' => 'company-a']);
        $license = $this->license();
        $service->signEntitlement($license);
        $token = $service->generateOfflineLicenseToken($company, [], $license);

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('z', 32)));
        config()->set('license.signing.mode', 'verify_only');
        config()->set('license.signing.private_key', null);

        $payload = $service->validateOfflineLicenseToken($token);
        $this->assertSame(2, $payload['version']);
        $this->assertSame($license->id, $payload['license_id']);
        $this->assertSame($license->license_key, $payload['license_key']);

        $parts = explode('.', $token);
        $parts[2] = ($parts[2][0] === 'A' ? 'B' : 'A').substr($parts[2], 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid license signature');
        $service->validateOfflineLicenseToken(implode('.', $parts));
    }

    public function test_offline_export_never_resigns_tampered_v2_material(): void
    {
        $service = app(LicenseService::class);
        $company = new Company(['company_name' => 'Portable Company']);
        $company->forceFill(['id' => 'company-a']);
        $license = $this->license();
        $service->signEntitlement($license);
        $revision = $license->entitlement_revision;
        $signature = $license->entitlement_signature_v2;
        $license->max_users = 999;

        try {
            $service->generateOfflineLicenseToken($company, [], $license);
            $this->fail('A tampered v2 entitlement must never be re-authorized during export.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('stored v2 license entitlement is invalid', $error->getMessage());
        }

        $this->assertSame($revision, $license->entitlement_revision);
        $this->assertSame($signature, $license->entitlement_signature_v2);
    }

    public function test_mobile_entitlement_is_signed_portable_and_omits_activation_and_signing_keys(): void
    {
        Carbon::setTestNow('2026-10-09 10:00:00 Africa/Harare');
        $license = $this->license();
        app(LicenseService::class)->signEntitlement($license);
        $delivery = app(MobileLicenseEntitlementService::class)
            ->issue($this->companyWithLicense($license));
        $entitlement = $delivery['license_entitlement'];
        $payloadJson = base64_decode($entitlement['payload'], true);
        $payload = json_decode((string) $payloadJson, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('mobile_license_entitlement', $payload['purpose']);
        $this->assertSame(1, $payload['version']);
        $this->assertSame('company-a', $payload['company_id']);
        $this->assertSame('license-a', $payload['license_id']);
        $this->assertSame($license->entitlement_revision, $entitlement['revision']);
        $this->assertArrayNotHasKey('license_key', $payload);
        $serialized = json_encode($delivery, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($license->license_key, $serialized);
        $this->assertStringNotContainsString((string) config('license.signing.private_key'), $serialized);
        $this->assertStringNotContainsString((string) self::$publicKey, $serialized);
        $this->assertTrue(app(LicenseSignatureService::class)->verify(
            $entitlement['payload'],
            $entitlement['signature'],
            $entitlement['algorithm'],
            $entitlement['key_id']
        ));

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('z', 32)));
        $this->assertTrue(app(LicenseSignatureService::class)->verify(
            $entitlement['payload'],
            $entitlement['signature'],
            $entitlement['algorithm'],
            $entitlement['key_id']
        ));
    }

    public function test_mobile_entitlement_preserves_authentic_expired_and_revoked_states(): void
    {
        Carbon::setTestNow('2026-10-08 10:00:00 Africa/Harare');
        $service = app(LicenseService::class);

        $expired = $this->license(['expires_at' => '2026-10-08']);
        $service->signEntitlement($expired);
        $beforeExpiry = app(MobileLicenseEntitlementService::class)
            ->issue($this->companyWithLicense($expired));
        Carbon::setTestNow('2026-10-09 10:00:00 Africa/Harare');
        $expiredDelivery = app(MobileLicenseEntitlementService::class)
            ->issue($this->companyWithLicense($expired));
        $expiredPayload = json_decode(
            (string) base64_decode($expiredDelivery['license_entitlement']['payload'], true),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame('LICENSE_EXPIRED', $expiredDelivery['license']['code']);
        $this->assertFalse($expiredDelivery['license']['valid']);
        $this->assertSame('issued', $expiredPayload['authorization_state']);
        $this->assertSame(
            $beforeExpiry['license_entitlement'],
            $expiredDelivery['license_entitlement'],
            'Automatic expiry must not create different signed bytes at the same entitlement revision.'
        );

        $revoked = $this->license([
            'status' => 'revoked',
            'revoked_at' => '2026-10-08 12:00:00',
        ]);
        $service->signEntitlement($revoked);
        $revokedDelivery = app(MobileLicenseEntitlementService::class)
            ->issue($this->companyWithLicense($revoked));
        $revokedPayload = json_decode(
            (string) base64_decode($revokedDelivery['license_entitlement']['payload'], true),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame('LICENSE_REVOKED', $revokedDelivery['license']['code']);
        $this->assertSame('revoked', $revokedPayload['authorization_state']);
    }

    public function test_mobile_entitlement_is_stable_for_company_status_and_rotates_with_a_new_revision(): void
    {
        $service = app(LicenseService::class);
        $license = $this->license();
        $service->signEntitlement($license);
        $company = $this->companyWithLicense($license);
        $first = app(MobileLicenseEntitlementService::class)->issue($company);

        $company->forceFill(['status' => 'inactive']);
        $inactive = app(MobileLicenseEntitlementService::class)->issue($company);
        $this->assertSame($first['license_entitlement'], $inactive['license_entitlement']);
        $this->assertSame('COMPANY_INACTIVE', $inactive['license']['code']);

        [$rotatedPrivateKey, $rotatedPublicKey] = $this->generateOpenSslKeyPair();
        config()->set('license.signing.active_key_id', 'test-rotated');
        config()->set('license.signing.private_key', $rotatedPrivateKey);
        config()->set('license.signing.public_keys', [
            'test-primary' => self::$publicKey,
            'test-rotated' => $rotatedPublicKey,
        ]);
        $rotated = app(MobileLicenseEntitlementService::class)->issue($company);

        $this->assertGreaterThan($first['license_entitlement']['revision'], $rotated['license_entitlement']['revision']);
        $this->assertSame('test-rotated', $rotated['license_entitlement']['key_id']);
        $this->assertTrue(app(LicenseSignatureService::class)->verify(
            $rotated['license_entitlement']['payload'],
            $rotated['license_entitlement']['signature'],
            $rotated['license_entitlement']['algorithm'],
            $rotated['license_entitlement']['key_id']
        ));
    }

    public function test_mobile_entitlement_rejects_missing_tampered_and_unavailable_signing_state(): void
    {
        $missingRelation = Mockery::mock();
        $missingRelation->shouldReceive('orderByRaw')->andReturnSelf();
        $missingRelation->shouldReceive('orderByDesc')->andReturnSelf();
        $missingRelation->shouldReceive('first')->andReturnNull();
        $missingCompany = Mockery::mock(Company::class)->makePartial();
        $missingCompany->forceFill(['id' => 'company-a', 'status' => 'active']);
        $missingCompany->shouldReceive('licenses')->andReturn($missingRelation);

        try {
            app(MobileLicenseEntitlementService::class)->issue($missingCompany);
            $this->fail('A missing entitlement must fail mobile provisioning.');
        } catch (MobileLicenseEntitlementException $error) {
            $this->assertSame('LICENSE_MISSING', $error->errorCode);
        }

        $tampered = $this->license();
        app(LicenseService::class)->signEntitlement($tampered);
        $tampered->max_devices = 999;
        try {
            app(MobileLicenseEntitlementService::class)->issue($this->companyWithLicense($tampered));
            $this->fail('A tampered entitlement must fail mobile provisioning.');
        } catch (MobileLicenseEntitlementException $error) {
            $this->assertSame('LICENSE_TAMPERED', $error->errorCode);
        }

        $valid = $this->license();
        app(LicenseService::class)->signEntitlement($valid);
        config()->set('license.signing.mode', 'verify_only');
        config()->set('license.signing.private_key', null);
        try {
            app(MobileLicenseEntitlementService::class)->issue($this->companyWithLicense($valid));
            $this->fail('A verify-only cloud cannot issue the mobile wrapper.');
        } catch (MobileLicenseEntitlementException $error) {
            $this->assertSame('LICENSE_ENTITLEMENT_UNAVAILABLE', $error->errorCode);
        }
    }

    public function test_mobile_entitlement_self_verification_rejects_a_mismatched_signing_pair(): void
    {
        $license = $this->license();
        app(LicenseService::class)->signEntitlement($license);
        [$privateKey] = $this->generateOpenSslKeyPair();
        [, $wrongPublicKey] = $this->generateOpenSslKeyPair();
        config()->set('license.signing.active_key_id', 'test-mismatched');
        config()->set('license.signing.private_key', $privateKey);
        config()->set('license.signing.public_keys', [
            'test-primary' => self::$publicKey,
            'test-mismatched' => $wrongPublicKey,
        ]);

        try {
            app(MobileLicenseEntitlementService::class)->issue($this->companyWithLicense($license));
            $this->fail('A mobile entitlement signed by a mismatched key pair must not be delivered.');
        } catch (MobileLicenseEntitlementException $error) {
            $this->assertSame('LICENSE_ENTITLEMENT_UNAVAILABLE', $error->errorCode);
        }
    }

    public function test_revocation_fields_require_a_new_issuer_signature(): void
    {
        $service = app(LicenseService::class);
        $license = $this->license();
        $service->signEntitlement($license);

        $license->revoked_at = now();
        $license->status = 'revoked';
        $this->assertSame('LICENSE_TAMPERED', $service->validationCode($license));

        $service->updateLicense($license, [
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->assertSame(2, $license->entitlement_revision);
        $this->assertSame('LICENSE_REVOKED', $service->validationCode($license));
    }

    public function test_license_dates_use_whole_local_days(): void
    {
        $service = app(LicenseService::class);
        Carbon::setTestNow('2026-10-05 23:59:59');

        $lastDay = $this->license(['expires_at' => '2026-10-05']);
        $service->signEntitlement($lastDay);
        $this->assertSame('LICENSE_VALID', $service->validationCode($lastDay));

        $future = $this->license(['starts_at' => '2026-10-06']);
        $service->signEntitlement($future);
        $this->assertSame('LICENSE_NOT_STARTED', $service->validationCode($future));

        Carbon::setTestNow('2026-10-06 00:00:00');
        $this->assertSame('LICENSE_EXPIRED', $service->validationCode($lastDay));
    }

    public function test_immediate_renewal_rejects_a_future_start_before_changing_licenses(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot start in the future');

        app(LicenseService::class)->renewLicense(new Company, [
            'starts_at' => '2026-10-06',
            'expires_at' => '2027-10-05',
        ]);
    }

    public function test_status_warns_at_ninety_days_and_includes_support_phone(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $license = $this->license(['expires_at' => '2027-01-03']);
        app(LicenseService::class)->signEntitlement($license);

        $relation = Mockery::mock();
        $relation->shouldReceive('orderByRaw')->twice()->andReturnSelf();
        $relation->shouldReceive('orderByDesc')->twice()->with('created_at')->andReturnSelf();
        $relation->shouldReceive('first')->twice()->andReturn($license);

        $company = Mockery::mock(Company::class)->makePartial();
        $company->forceFill([
            'id' => $license->company_id,
            'status' => 'active',
            'license_support_phone' => '+263 77 000 0000',
        ]);
        $company->shouldReceive('licenses')->twice()->andReturn($relation);

        $status = app(LicenseAccessService::class)->status($company);

        $this->assertTrue($status['valid']);
        $this->assertTrue($status['warning']);
        $this->assertTrue($status['warning_required']);
        $this->assertSame(90, $status['warning_days']);
        $this->assertSame('+263 77 000 0000', $status['license_support_phone']);

        $superAdmin = new PosUser(['user_type' => 'super_admin']);
        $superAdmin->setRelation('role', new Role(['name' => 'Super Admin']));
        $superStatus = app(LicenseAccessService::class)->status($company, $superAdmin);

        $this->assertFalse($superStatus['bypass']);
        $this->assertTrue($superStatus['valid']);
        $this->assertTrue($superStatus['warning']);
        $this->assertTrue($superStatus['warning_required']);
        $this->assertNull($superStatus['license_key']);
    }

    public function test_invalid_license_blocks_tenant_super_admin_and_only_allowlisted_system_owner_bypasses(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('license.platform_admin_pos_user_ids', ['platform-owner']);

        $license = $this->license(['status' => 'expired', 'expires_at' => '2026-10-04']);
        $company = $this->companyWithLicense($license);
        $licenseService = app(LicenseService::class);
        $superAdmins = app(SuperAdminService::class);

        $access = new class($licenseService, $superAdmins) extends LicenseAccessService
        {
            public bool $revoked = false;

            public function revokeNonPlatformAdministratorTokens(Company $company): int
            {
                $this->revoked = true;

                return 1;
            }
        };

        $ordinary = new PosUser(['user_type' => 'cashier']);
        $ordinary->setRelation('role', new Role(['name' => 'Cashier']));
        $blocked = $access->enforce($company, $ordinary);

        $this->assertFalse($blocked['valid']);
        $this->assertSame('LICENSE_EXPIRED', $blocked['code']);
        $this->assertTrue($access->revoked);

        $access->revoked = false;
        $tenantSuperAdmin = new PosUser(['user_type' => 'super_admin']);
        $tenantSuperAdmin->forceFill(['id' => 'tenant-super-admin']);
        $tenantSuperAdmin->setRelation('role', new Role(['name' => 'Super Admin']));

        $this->assertTrue($superAdmins->isSuperAdmin($tenantSuperAdmin));
        $this->assertFalse($superAdmins->isPlatformAdministrator($tenantSuperAdmin));

        $tenantBlocked = $access->enforce($company, $tenantSuperAdmin);

        $this->assertFalse($tenantBlocked['valid']);
        $this->assertFalse($tenantBlocked['bypass']);
        $this->assertSame('LICENSE_EXPIRED', $tenantBlocked['code']);
        $this->assertNull($tenantBlocked['license_key']);
        $this->assertTrue($access->revoked);

        $access->revoked = false;
        $systemOwner = new PosUser(['user_type' => 'super_admin']);
        $systemOwner->forceFill(['id' => 'platform-owner']);
        $systemOwner->setRelation('role', new Role(['name' => 'Super Admin']));
        $bypassed = $access->enforce($company, $systemOwner);

        $this->assertTrue($bypassed['valid']);
        $this->assertTrue($bypassed['bypass']);
        $this->assertSame('LICENSE_BYPASSED', $bypassed['code']);
        $this->assertSame('LICENSE_EXPIRED', $bypassed['underlying_code']);
        $this->assertSame($license->license_key, $bypassed['license_key']);
        $this->assertTrue(
            $access->revoked,
            'The owner bypass must still evict non-owner sessions for the invalid company.'
        );
    }

    public function test_platform_access_requires_an_explicitly_configured_super_admin_identity(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        $service = app(SuperAdminService::class);
        $superAdmin = new PosUser(['user_type' => 'cashier']);
        $superAdmin->forceFill(['id' => 'configured-platform-admin']);
        $superAdmin->setRelation('role', new Role(['name' => 'Super-Administrator']));
        $ordinary = new PosUser(['user_type' => 'admin']);
        $ordinary->forceFill(['id' => 'ordinary-admin']);
        $ordinary->setRelation('role', new Role(['name' => 'Administrator']));

        $this->assertTrue($service->isSuperAdmin($superAdmin));
        $this->assertFalse($service->isPlatformAdministrator($superAdmin));

        config()->set('license.platform_admin_pos_user_ids', ['configured-platform-admin']);

        $this->assertTrue($service->isPlatformAdministrator($superAdmin));
        $this->assertFalse($service->isPlatformAdministrator($ordinary));
        $this->assertFalse($service->isPlatformAdministrator(new User([
            'email' => 'unconfigured@example.test',
        ])));
    }

    private function license(array $overrides = []): License
    {
        $license = new class extends License
        {
            public function saveQuietly(array $options = []): bool
            {
                $this->exists = true;

                return true;
            }

            public function save(array $options = []): bool
            {
                $this->exists = true;

                return true;
            }

            public function fresh($with = []): static
            {
                return $this;
            }
        };

        $license->forceFill([
            'id' => 'license-a',
            'company_id' => 'company-a',
            'license_key' => 'ABCD-EFGH-JKMP-QRST',
            'license_type' => 'enterprise',
            'max_branches' => 2,
            'max_users' => 10,
            'max_devices' => 3,
            'starts_at' => '2026-01-01',
            'expires_at' => '2027-01-01',
            'status' => 'active',
            ...$overrides,
        ]);
        $license->exists = true;

        return $license;
    }

    private function companyWithLicense(License $license): Company
    {
        $relation = Mockery::mock();
        $relation->shouldReceive('orderByRaw')->andReturnSelf();
        $relation->shouldReceive('orderByDesc')->with('created_at')->andReturnSelf();
        $relation->shouldReceive('first')->andReturn($license);

        $company = Mockery::mock(Company::class)->makePartial();
        $company->forceFill(['id' => $license->company_id, 'status' => 'active']);
        $company->shouldReceive('licenses')->andReturn($relation);

        return $company;
    }

    private function configureIssuer(): void
    {
        if (self::$privateKey === null || self::$publicKey === null) {
            [self::$privateKey, self::$publicKey] = $this->generateOpenSslKeyPair();
        }

        config()->set('license.signing', [
            'mode' => 'issuer',
            'algorithm' => LicenseSignatureService::ALGORITHM_OPENSSL_SHA256,
            'active_key_id' => 'test-primary',
            'private_key' => self::$privateKey,
            'private_key_passphrase' => null,
            'public_keys' => ['test-primary' => self::$publicKey],
            'legacy_hmac_verify' => true,
            'legacy_hmac_sign' => true,
            'legacy_hmac_key' => null,
            'legacy_app_key_fallback' => true,
            'allow_unsigned_legacy' => true,
            'auto_upgrade_unsigned' => true,
            'legacy_offline_token_verify' => true,
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function generateOpenSslKeyPair(): array
    {
        $key = openssl_pkey_new($this->openSslOptions([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]));
        $this->assertNotFalse($key);

        $privateKey = '';
        $this->assertTrue(openssl_pkey_export($key, $privateKey, null, $this->openSslOptions()));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        return [$privateKey, $details['key']];
    }
}
