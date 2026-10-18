<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Company\Models\Company;
use Modules\GeneralSettings\Http\Controllers\CurrentTerminalController;
use Modules\GeneralSettings\Http\Controllers\CurrentTerminalQzCertificateController;
use Modules\GeneralSettings\Http\Controllers\PrinterController;
use Modules\GeneralSettings\Models\PosTill;
use Modules\GeneralSettings\Models\Printer;
use Modules\GeneralSettings\Models\QzCertificate;
use Modules\Sales\Http\Controllers\QzController;
use PDO;
use Tests\TestCase;

class CurrentTerminalSettingsTest extends TestCase
{
    private const COMPANY_ID = '019f9b00-0000-7000-8000-000000000001';

    private const BRANCH_ID = '019f9b00-0000-7000-8000-000000000002';

    private const TILL_ID = '019f9b00-0000-7000-8000-000000000003';

    private const OTHER_BRANCH_ID = '019f9b00-0000-7000-8000-000000000004';

    private const OTHER_TILL_ID = '019f9b00-0000-7000-8000-000000000005';

    private bool $databaseAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is required for this feature test.');
        }

        $this->databaseAvailable = true;
        $this->createTables();
        $this->seedTerminalIdentity();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('till');
        app()->forgetInstance('company');

        if ($this->databaseAvailable) {
            foreach (['qz_certificates', 'printers', 'pos_tills', 'company_branches', 'companies'] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_terminal_update_changes_only_the_resolved_tills_editable_metadata(): void
    {
        $request = Request::create('/current-terminal', 'PATCH', [
            'device_label' => '  Counter One  ',
            'company_id' => 'spoofed-company',
            'branch_id' => self::OTHER_BRANCH_ID,
            'till_code' => 'SPOOFED-CODE',
            'till_name' => 'Spoofed Name',
            'is_active' => false,
            'is_registered' => false,
            'registration_token' => hash('sha256', 'replacement-token'),
        ]);

        $response = (new CurrentTerminalController)->update($request);
        $stored = DB::table('pos_tills')->where('id', self::TILL_ID)->first();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Counter One', $stored->device_label);
        $this->assertSame(self::COMPANY_ID, $stored->company_id);
        $this->assertSame(self::BRANCH_ID, $stored->branch_id);
        $this->assertSame('TILL-001', $stored->till_code);
        $this->assertSame('Main Register', $stored->till_name);
        $this->assertSame(1, (int) $stored->is_active);
        $this->assertSame(1, (int) $stored->is_registered);
        $this->assertSame(hash('sha256', 'current-till-token'), $stored->registration_token);
    }

    public function test_printer_create_and_update_force_the_resolved_company_branch_and_till(): void
    {
        $controller = new CurrentTerminalController;
        $create = $controller->storePrinter(Request::create('/printers', 'POST', [
            'company_id' => 'spoofed-company',
            'branch_id' => self::OTHER_BRANCH_ID,
            'till_id' => self::OTHER_TILL_ID,
            'printer_name' => 'Receipt Printer',
            'target_printing' => 'receipt',
            'printer_type' => 'qz',
            'is_default' => true,
        ]));

        $this->assertSame(201, $create->getStatusCode());
        $printerId = $create->getData(true)['data']['id'];
        $stored = DB::table('printers')->where('id', $printerId)->first();
        $this->assertSame(self::COMPANY_ID, $stored->company_id);
        $this->assertSame(self::BRANCH_ID, $stored->branch_id);
        $this->assertSame(self::TILL_ID, $stored->till_id);

        $update = $controller->updatePrinter(Request::create('/printers/'.$printerId, 'PUT', [
            'company_id' => 'spoofed-company',
            'branch_id' => self::OTHER_BRANCH_ID,
            'till_id' => self::OTHER_TILL_ID,
            'printer_name' => 'Receipt Printer Updated',
            'target_printing' => 'receipt',
            'printer_type' => 'browser',
        ]), $printerId);

        $stored = DB::table('printers')->where('id', $printerId)->first();
        $this->assertSame(200, $update->getStatusCode());
        $this->assertSame('Receipt Printer Updated', $stored->printer_name);
        $this->assertSame(self::COMPANY_ID, $stored->company_id);
        $this->assertSame(self::BRANCH_ID, $stored->branch_id);
        $this->assertSame(self::TILL_ID, $stored->till_id);
    }

    public function test_one_terminal_cannot_update_or_delete_another_tills_printer_override(): void
    {
        $otherPrinter = $this->createPrinter([
            'branch_id' => self::OTHER_BRANCH_ID,
            'till_id' => self::OTHER_TILL_ID,
            'printer_name' => 'Other Till Printer',
        ]);
        $ownPrinter = $this->createPrinter([
            'printer_name' => 'Own Till Printer',
        ]);
        $controller = new CurrentTerminalController;

        $update = $controller->updatePrinter(Request::create('/printers/'.$otherPrinter->id, 'PUT', [
            'printer_name' => 'Hijacked',
            'target_printing' => 'receipt',
        ]), $otherPrinter->id);
        $delete = $controller->destroyPrinter($otherPrinter->id);

        $this->assertSame(404, $update->getStatusCode());
        $this->assertSame(404, $delete->getStatusCode());
        $this->assertDatabaseHas('printers', [
            'id' => $otherPrinter->id,
            'printer_name' => 'Other Till Printer',
            'deleted_at' => null,
        ]);

        $this->assertSame(200, $controller->destroyPrinter($ownPrinter->id)->getStatusCode());
        $this->assertSoftDeleted('printers', ['id' => $ownPrinter->id]);
    }

    public function test_print_resolver_ignores_spoofed_query_and_header_scopes(): void
    {
        $currentPrinter = $this->createPrinter([
            'printer_name' => 'Current Till Receipt',
            'is_default' => true,
        ]);
        $this->createPrinter([
            'branch_id' => self::OTHER_BRANCH_ID,
            'till_id' => self::OTHER_TILL_ID,
            'printer_name' => 'Other Till Receipt',
            'is_default' => true,
        ]);

        $request = Request::create('/resolve', 'GET', [
            'target_printing' => 'receipt',
            'branch_id' => self::OTHER_BRANCH_ID,
            'till_id' => self::OTHER_TILL_ID,
        ]);
        $request->headers->set('X-Till-Id', self::OTHER_TILL_ID);

        $response = (new PrinterController)->resolveForPrinting($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($currentPrinter->id, $response->getData(true)['data']['id']);
        $this->assertSame(self::TILL_ID, $response->getData(true)['data']['till_id']);
    }

    public function test_qz_upload_is_bound_to_current_till_and_never_returns_key_material_or_paths(): void
    {
        Storage::fake('qz_private');
        [$certificatePem, $privateKeyPem] = $this->qzCredentialPair('current-terminal.test');
        $request = Request::create('/qz-certificate', 'POST', [
            'company_id' => 'spoofed-company',
            'branch_id' => self::OTHER_BRANCH_ID,
            'till_id' => self::OTHER_TILL_ID,
            'name' => 'Counter One QZ',
        ], [], [
            'certificate' => UploadedFile::fake()->createWithContent('certificate.pem', $certificatePem),
            'private_key' => UploadedFile::fake()->createWithContent('private-key.pem', $privateKeyPem),
        ]);

        $controller = new CurrentTerminalQzCertificateController;
        $response = $controller->store($request);
        $payload = $response->getData(true)['data'];
        $stored = DB::table('qz_certificates')->where('id', $payload['id'])->first();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(self::COMPANY_ID, $stored->company_id);
        $this->assertSame(self::BRANCH_ID, $stored->branch_id);
        $this->assertSame(self::TILL_ID, $stored->till_id);
        $this->assertTrue($payload['certificate_configured']);
        $this->assertTrue($payload['private_key_configured']);
        $this->assertArrayNotHasKey('certificate_path', $payload);
        $this->assertArrayNotHasKey('private_key_path', $payload);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_qz_upload_rejects_a_private_key_that_does_not_match_the_certificate(): void
    {
        Storage::fake('qz_private');
        [$certificatePem] = $this->qzCredentialPair('first.test');
        [, $differentPrivateKey] = $this->qzCredentialPair('second.test');
        $request = Request::create('/qz-certificate', 'POST', [], [], [
            'certificate' => UploadedFile::fake()->createWithContent('certificate.pem', $certificatePem),
            'private_key' => UploadedFile::fake()->createWithContent('private-key.pem', $differentPrivateKey),
        ]);

        $response = (new CurrentTerminalQzCertificateController)->store($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('does not match', $response->getData(true)['message']);
        $this->assertDatabaseCount('qz_certificates', 0);
    }

    public function test_qz_replacement_rejects_a_one_file_credential_change(): void
    {
        Storage::fake('qz_private');
        [$certificatePem, $privateKeyPem] = $this->qzCredentialPair('existing.test');
        $this->storeQzCredential(
            self::BRANCH_ID,
            self::TILL_ID,
            'existing',
            $certificatePem,
            $privateKeyPem
        );
        [$replacementCertificate] = $this->qzCredentialPair('replacement.test');

        $response = (new CurrentTerminalQzCertificateController)->store(
            Request::create('/qz-certificate', 'POST', [], [], [
                'certificate' => UploadedFile::fake()->createWithContent(
                    'replacement.pem',
                    $replacementCertificate
                ),
            ])
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('must be uploaded together', $response->getData(true)['message']);
        $this->assertDatabaseCount('qz_certificates', 1);
    }

    public function test_qz_runtime_uses_exact_resolved_till_and_ignores_asserted_scope(): void
    {
        Storage::fake('qz_private');
        [$currentCertificate, $currentPrivateKey] = $this->qzCredentialPair('current.test');
        [$otherCertificate, $otherPrivateKey] = $this->qzCredentialPair('other.test');
        $this->storeQzCredential(
            self::BRANCH_ID,
            self::TILL_ID,
            'current',
            $currentCertificate,
            $currentPrivateKey
        );
        $this->storeQzCredential(
            self::OTHER_BRANCH_ID,
            self::OTHER_TILL_ID,
            'other',
            $otherCertificate,
            $otherPrivateKey
        );

        $certRequest = Request::create('/qz/cert', 'GET', [
            'branch_id' => self::OTHER_BRANCH_ID,
            'till_id' => self::OTHER_TILL_ID,
        ]);
        $certRequest->headers->set('X-Till-Id', self::OTHER_TILL_ID);
        $certificateResponse = (new QzController)->cert($certRequest);

        $signRequest = Request::create('/qz/sign', 'POST', [
            'request' => 'terminal-scoped-payload',
            'branch_id' => self::OTHER_BRANCH_ID,
            'till_id' => self::OTHER_TILL_ID,
        ]);
        $signRequest->headers->set('X-Till-Id', self::OTHER_TILL_ID);
        $signatureResponse = (new QzController)->sign($signRequest);
        $signature = base64_decode($signatureResponse->getData(true)['signature'], true);

        $this->assertSame(200, $certificateResponse->getStatusCode());
        $this->assertSame($currentCertificate, $certificateResponse->getContent());
        $this->assertIsString($signature);
        $this->assertSame(1, openssl_verify(
            'terminal-scoped-payload',
            $signature,
            openssl_pkey_get_public($currentCertificate),
            OPENSSL_ALGO_SHA1
        ));
        $this->assertSame(0, openssl_verify(
            'terminal-scoped-payload',
            $signature,
            openssl_pkey_get_public($otherCertificate),
            OPENSSL_ALGO_SHA1
        ));
    }

    public function test_front_office_qz_runtime_does_not_fall_back_to_branch_or_company_certificate(): void
    {
        Storage::fake('qz_private');
        [$certificatePem, $privateKeyPem] = $this->qzCredentialPair('global.test');
        $this->storeQzCredential(null, null, 'global', $certificatePem, $privateKeyPem);

        $response = (new QzController)->cert(Request::create('/qz/cert', 'GET'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('not configured for this terminal', $response->getContent());
    }

    public function test_back_office_qz_runtime_preserves_selected_scope_fallback(): void
    {
        Storage::fake('qz_private');
        [$certificatePem, $privateKeyPem] = $this->qzCredentialPair('branch-fallback.test');
        $this->storeQzCredential(
            self::BRANCH_ID,
            null,
            'branch-fallback',
            $certificatePem,
            $privateKeyPem
        );
        app()->forgetInstance('till');

        $response = (new QzController)->cert(Request::create('/qz/cert', 'GET', [
            'branch_id' => self::BRANCH_ID,
            'till_id' => self::TILL_ID,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($certificatePem, $response->getContent());
    }

    public function test_current_terminal_cannot_delete_another_tills_qz_credential(): void
    {
        Storage::fake('qz_private');
        [$certificatePem, $privateKeyPem] = $this->qzCredentialPair('other.test');
        $other = $this->storeQzCredential(
            self::OTHER_BRANCH_ID,
            self::OTHER_TILL_ID,
            'other',
            $certificatePem,
            $privateKeyPem
        );

        $response = (new CurrentTerminalQzCertificateController)->destroy();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertDatabaseHas('qz_certificates', ['id' => $other->id]);
        Storage::disk('qz_private')->assertExists($other->private_key_path);
    }

    public function test_current_terminal_can_remove_and_reinstall_its_qz_credential(): void
    {
        Storage::fake('qz_private');
        [$certificatePem, $privateKeyPem] = $this->qzCredentialPair('reinstall.test');
        $original = $this->storeQzCredential(
            self::BRANCH_ID,
            self::TILL_ID,
            'original',
            $certificatePem,
            $privateKeyPem
        );
        $controller = new CurrentTerminalQzCertificateController;

        $deleted = $controller->destroy();
        $replacement = $controller->store(Request::create('/qz-certificate', 'POST', [], [], [
            'certificate' => UploadedFile::fake()->createWithContent('certificate.pem', $certificatePem),
            'private_key' => UploadedFile::fake()->createWithContent('private-key.pem', $privateKeyPem),
        ]));

        $this->assertSame(200, $deleted->getStatusCode());
        $this->assertDatabaseMissing('qz_certificates', ['id' => $original->id]);
        $this->assertSame(201, $replacement->getStatusCode());
        $this->assertDatabaseCount('qz_certificates', 1);
        $this->assertNotSame($original->id, $replacement->getData(true)['data']['id']);
    }

    private function createPrinter(array $overrides = []): Printer
    {
        return Printer::query()->create(array_merge([
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_id' => self::TILL_ID,
            'printer_name' => 'Printer',
            'target_printing' => 'receipt',
            'printer_type' => 'qz',
            'connection_type' => 'local',
            'is_default' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function qzCredentialPair(string $commonName): array
    {
        $privateKey = openssl_pkey_new($this->openSslOptions([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]));
        $this->assertNotFalse($privateKey);

        $csr = openssl_csr_new(['commonName' => $commonName], $privateKey, $this->openSslOptions(['digest_alg' => 'sha256']));
        $this->assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 30, $this->openSslOptions(['digest_alg' => 'sha256']));
        $this->assertNotFalse($certificate);

        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));
        $this->assertTrue(openssl_pkey_export($privateKey, $privateKeyPem, null, $this->openSslOptions()));

        return [$certificatePem, $privateKeyPem];
    }

    private function storeQzCredential(
        ?string $branchId,
        ?string $tillId,
        string $name,
        string $certificatePem,
        string $privateKeyPem
    ): QzCertificate {
        $folder = "qz-certificates/tests/{$name}";
        $certificatePath = "{$folder}/certificate.pem";
        $privateKeyPath = "{$folder}/private-key.pem";
        Storage::disk('qz_private')->put($certificatePath, $certificatePem);
        Storage::disk('qz_private')->put($privateKeyPath, $privateKeyPem);

        return QzCertificate::query()->create([
            'company_id' => self::COMPANY_ID,
            'branch_id' => $branchId,
            'till_id' => $tillId,
            'name' => $name,
            'certificate_path' => $certificatePath,
            'private_key_path' => $privateKeyPath,
            'is_active' => true,
        ]);
    }

    private function seedTerminalIdentity(): void
    {
        $now = now();
        DB::table('companies')->insert([
            'id' => self::COMPANY_ID,
            'company_code' => 'CURRENT',
            'company_name' => 'Current Company',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('company_branches')->insert([
            [
                'id' => self::BRANCH_ID,
                'company_id' => self::COMPANY_ID,
                'branch_name' => 'Current Branch',
                'branch_code' => 'CURRENT',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::OTHER_BRANCH_ID,
                'company_id' => self::COMPANY_ID,
                'branch_name' => 'Other Branch',
                'branch_code' => 'OTHER',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('pos_tills')->insert([
            [
                'id' => self::TILL_ID,
                'company_id' => self::COMPANY_ID,
                'branch_id' => self::BRANCH_ID,
                'till_code' => 'TILL-001',
                'till_name' => 'Main Register',
                'device_label' => 'Counter',
                'registration_token' => hash('sha256', 'current-till-token'),
                'is_registered' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::OTHER_TILL_ID,
                'company_id' => self::COMPANY_ID,
                'branch_id' => self::OTHER_BRANCH_ID,
                'till_code' => 'TILL-002',
                'till_name' => 'Other Register',
                'device_label' => 'Other Counter',
                'registration_token' => hash('sha256', 'other-till-token'),
                'is_registered' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        app()->instance('company', Company::query()->findOrFail(self::COMPANY_ID));
        app()->instance('till', PosTill::query()->with('branch')->findOrFail(self::TILL_ID));
    }

    private function createTables(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_code');
            $table->string('company_name');
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name');
            $table->string('branch_code');
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('till_code');
            $table->string('till_name');
            $table->string('device_label')->nullable();
            $table->string('registration_token', 128)->nullable();
            $table->string('activation_code')->nullable();
            $table->boolean('is_registered')->default(false);
            $table->boolean('is_active')->default(true);
            $table->uuid('registered_by')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip_address')->nullable();
            $table->text('last_user_agent')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('printers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->string('printer_name');
            $table->string('target_printing');
            $table->string('printer_type')->default('qz');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('paper_size')->nullable();
            $table->string('connection_type')->nullable();
            $table->string('ip_address')->nullable();
            $table->integer('port')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('qz_certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->string('name')->nullable();
            $table->string('certificate_path');
            $table->string('private_key_path');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
