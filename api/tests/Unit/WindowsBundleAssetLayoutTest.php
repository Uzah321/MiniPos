<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Static contracts of the ServeNest Windows installers in
 * deployment/windows-bundle. See docs/INSTALLER_ASSUMPTIONS.md there.
 */
class WindowsBundleAssetLayoutTest extends TestCase
{
    private const NGINX_TEMPLATES = ['nginx-edge', 'nginx-cloud-https', 'nginx-cloud-proxy'];

    public function test_both_editions_share_one_setup_with_their_own_identity(): void
    {
        $foh = $this->bundleFile('ServeNest-FOH.iss');
        $boh = $this->bundleFile('ServeNest-BOH.iss');

        foreach ([[$foh, 'FOH', 'web\\front'], [$boh, 'BOH', 'web\\back']] as [$script, $edition, $webDir]) {
            $this->assertStringContainsString("#define Edition \"{$edition}\"", $script);
            $this->assertStringContainsString("#define WebDir \"{$webDir}\"", $script);
            $this->assertStringContainsString('#include "include\\ServeNestSetup.iss"', $script);
            $this->assertStringContainsString('#include "include\\ServeNestHelpers.pas"', $script);
            $this->assertStringContainsString('#include "include\\ServeNestEvents.pas"', $script);
        }
        preg_match('/#define AppGuid "([^"]+)"/', $foh, $fohGuid);
        preg_match('/#define AppGuid "([^"]+)"/', $boh, $bohGuid);
        $this->assertNotEmpty($fohGuid[1] ?? null);
        $this->assertNotSame($fohGuid[1], $bohGuid[1] ?? null);
        $this->assertStringContainsString('#define RuntimeName "ServeNest" + Edition', $this->bundleFile('include/ServeNestSetup.iss'));
    }

    public function test_shortcuts_only_use_files_a_standard_user_can_read(): void
    {
        // The install folder is Administrators/SYSTEM-only; a cashier's
        // shortcut icon and .url target must live in ProgramData.
        $this->assertStringContainsString(
            '#define PublicDir "{commonappdata}\\ServeNest\\" + Edition',
            $this->bundleFile('include/ServeNestSetup.iss')
        );
        foreach (['ServeNest-FOH.iss', 'ServeNest-BOH.iss'] as $edition) {
            $sections = explode('[Code]', $this->bundleFile($edition), 2)[0];
            $this->assertStringNotContainsString('{app}', $sections, $edition);
            $this->assertStringContainsString('IconFilename: "{#PublicDir}\\servenest.ico"', $sections, $edition);
        }
    }

    public function test_editions_use_separate_services_ports_and_runtime_folders(): void
    {
        $common = $this->bundleFile('scripts/ServeNest.Common.ps1');

        $this->assertStringContainsString('$prefix = "ServeNest$Edition"', $common);
        foreach ([
            "DefaultUiPort = if (\$isFoh) { 8083 } else { 8082 }",
            "DefaultPhpPort = if (\$isFoh) { 9301 } else { 9401 }",
            "DefaultDatabasePort = if (\$isFoh) { 3307 } else { 3308 }",
            "PhpMyAdminPort = if (\$isFoh) { 8081 } else { 8084 }",
            "SyncService = if (\$isFoh) { \"\${prefix}Sync\" } else { \$null }",
            "SchedulerService = if (\$isFoh) { \$null } else { \"\${prefix}Scheduler\" }",
        ] as $expected) {
            $this->assertStringContainsString($expected, $common);
        }
    }

    public function test_installer_creates_both_public_upload_and_application_document_roots(): void
    {
        $installer = $this->bundleFile('scripts/Install-ServeNest.ps1');

        $this->assertStringContainsString("'assets'", $installer);
        $this->assertStringContainsString("'api\\assets'", $installer);
    }

    public function test_wizard_checks_do_not_expand_app_before_the_directory_page(): void
    {
        $helpers = $this->bundleFile('include/ServeNestHelpers.pas');
        $wizardHelpers = explode('{ Post-install helpers', $helpers, 2)[0];
        $this->assertNotSame($helpers, $wizardHelpers);

        $this->assertStringContainsString("ProspectiveInstallPath('config\\installation.json')", $wizardHelpers);
        $this->assertStringNotContainsString("ExpandConstant('{app}", $wizardHelpers);
        foreach (['ServeNest-FOH.iss', 'ServeNest-BOH.iss'] as $edition) {
            $wizardCode = explode('function BuildConfigurationJson', $this->bundleFile($edition), 2)[0];
            $this->assertStringNotContainsString("ExpandConstant('{app}", $wizardCode);
        }
        $this->assertStringNotContainsString('LicenseFile', $this->bundleFile('include/ServeNestSetup.iss'));
    }

    public function test_install_folder_never_adopts_another_installation(): void
    {
        $helpers = $this->bundleFile('include/ServeNestHelpers.pas');
        $installer = $this->bundleFile('scripts/Install-ServeNest.ps1');

        $this->assertStringContainsString("Product := JsonValue(Bundle, 'product');", $helpers);
        $this->assertStringContainsString("if Product <> 'ServeNest' then", $helpers);
        $this->assertStringContainsString("JsonValue(Bundle, 'edition') <> '{#Edition}'", $helpers);
        $this->assertStringContainsString("DirExists(AddBackslash(Directory) + '.git')", $helpers);
        $this->assertStringContainsString("[string] \$metadata.edition -ne \$edition", $installer);
        $this->assertStringContainsString("[string] \$bundle.product -ne 'ServeNest'", $installer);
        $this->assertStringContainsString("'apps\\api'", $installer);
    }

    public function test_wizard_extracts_server_runtimes_into_dedicated_edition_folders(): void
    {
        $setup = $this->bundleFile('include/ServeNestSetup.iss');

        foreach (['nginx', 'nssm', 'php', 'phpMyAdmin'] as $runtime) {
            $this->assertStringContainsString("DestDir: \"C:\\{$runtime}\\{#RuntimeName}\"; Check: IsServerProfile", $setup);
        }
        $this->assertStringContainsString('DestDir: "{app}\\runtime\\database"; Check: IsServerProfile', $setup);
        $this->assertStringNotContainsString('DestDir: "{app}\\runtime\\nginx"', $setup);
        $this->assertStringNotContainsString('DestDir: "{app}\\runtime\\nssm"', $setup);
    }

    public function test_server_runtime_directories_are_locked_before_extraction(): void
    {
        $helpers = $this->bundleFile('include/ServeNestHelpers.pas');
        $events = $this->bundleFile('include/ServeNestEvents.pas');

        $this->assertStringContainsString('function ProtectExtractionDirectory(const Path: String): String;', $helpers);
        $this->assertStringContainsString('/inheritance:r /grant:r', $helpers);
        $this->assertStringContainsString('Result := ProtectExtractionDirectory(NormalizedInstallRoot(WizardDirValue));', $events);
        foreach (['nginx', 'nssm', 'php', 'phpMyAdmin'] as $runtime) {
            $this->assertStringContainsString("ProtectExtractionDirectory('C:\\{$runtime}\\{#RuntimeName}')", $events);
        }
    }

    public function test_repair_stops_only_owned_services_before_replacing_locked_executables(): void
    {
        $setup = $this->bundleFile('include/ServeNestSetup.iss');
        $events = $this->bundleFile('include/ServeNestEvents.pas');
        $stop = $this->bundleFile('scripts/Stop-ServeNestServices.ps1');

        $this->assertStringContainsString('Stop-ServeNestServices.ps1"; Flags: dontcopy', $setup);
        $this->assertStringContainsString('ServeNest.Common.ps1"; Flags: dontcopy', $setup);
        $this->assertStringContainsString('CloseApplications=no', $setup);
        $this->assertStringContainsString('Result := StopRunningServices;', $events);
        $this->assertStringContainsString('Assert-OwnedApplicationService $layout $root $name', $stop);
        $this->assertStringContainsString('Assert-OwnedDatabaseService $layout $root', $stop);
    }

    public function test_nssm_output_is_decoded_instead_of_read_as_console_text(): void
    {
        $common = $this->bundleFile('scripts/ServeNest.Common.ps1');

        $this->assertStringContainsString('function ConvertFrom-NssmOutput([byte[]] $Bytes)', $common);
        $this->assertStringContainsString('[Text.Encoding]::Unicode.GetString(', $common);
        $this->assertStringContainsString('$process.StandardOutput.BaseStream.CopyTo($buffer)', $common);
        foreach (glob($this->bundlePath('scripts/*.ps1')) as $script) {
            // `& $nssm get ...` captured as text embeds NUL characters.
            $this->assertDoesNotMatchRegularExpression(
                '/&\s*\$[\w:.]+\s+get\s/i',
                file_get_contents($script),
                basename($script).' must read NSSM settings through Invoke-NssmGet.'
            );
        }
    }

    public function test_nginx_templates_serve_the_spa_from_the_origin_root(): void
    {
        foreach (self::NGINX_TEMPLATES as $name) {
            $template = $this->bundleFile("templates/nginx/{$name}.conf.template");

            $this->assertStringNotContainsStringIgnoringCase('salexa', $template, $name);
            $this->assertMatchesRegularExpression('/location \/ \{\s*try_files \$uri \$uri\/ \/index\.html;/', $template, $name);
            $this->assertStringContainsString('location = /runtime-config.js {', $template);
            $this->assertStringContainsString('location ^~ /api/ {', $template);
            $this->assertStringContainsString('{{NGINX_RUNTIME_NGINX}}/conf/mime.types', $template);
            $this->assertStringContainsString('{{NGINX_RUNTIME_NGINX}}/conf/fastcgi_params', $template);
            $this->assertStringContainsString('{{INSTALL_ROOT_NGINX}}/api/public/index.php', $template);
            $this->assertStringNotContainsString('{{INSTALL_ROOT_NGINX}}/runtime/nginx/conf/', $template);
            $this->assertStringContainsString('include "{{INSTALL_ROOT_NGINX}}/config/phpmyadmin-nginx.conf";', $template);
        }
    }

    public function test_php_requests_are_balanced_over_a_worker_pool(): void
    {
        foreach (self::NGINX_TEMPLATES as $name) {
            $template = $this->bundleFile("templates/nginx/{$name}.conf.template");

            $this->assertMatchesRegularExpression('/upstream servenest_php \{\s*least_conn;\s*\{\{PHP_UPSTREAM_SERVERS\}\}/', $template, $name);
            $this->assertStringNotContainsString('fastcgi_pass 127.0.0.1:', $template, $name);
        }
        $this->assertStringNotContainsString('fastcgi_pass 127.0.0.1:', $this->bundleFile('templates/nginx/phpmyadmin-server.conf.template'));
        $this->assertStringContainsString('PhpServices = @(1..$phpWorkers', $this->bundleFile('scripts/ServeNest.Common.ps1'));
    }

    public function test_every_template_serves_only_the_shared_upload_root_as_public_assets(): void
    {
        foreach (self::NGINX_TEMPLATES as $name) {
            $template = $this->bundleFile("templates/nginx/{$name}.conf.template");

            $this->assertSame(1, substr_count($template, 'alias "{{INSTALL_ROOT_NGINX}}/assets/";'), $name);
            $this->assertStringNotContainsString('alias "{{INSTALL_ROOT_NGINX}}/api/assets/";', $template, $name);
            $this->assertMatchesRegularExpression(
                '/location \/assets\/ \{\s*alias "\{\{INSTALL_ROOT_NGINX\}\}\/assets\/";\s*add_header X-Content-Type-Options nosniff always;/',
                $template,
                $name
            );
            $this->assertMatchesRegularExpression(
                '/location \/storage\/ \{\s*alias "\{\{INSTALL_ROOT_NGINX\}\}\/api\/storage\/app\/public\/";\s*add_header X-Content-Type-Options nosniff always;/',
                $template,
                $name
            );
        }
    }

    public function test_back_office_is_served_over_https_or_behind_a_tls_proxy(): void
    {
        $https = $this->bundleFile('templates/nginx/nginx-cloud-https.conf.template');
        $proxy = $this->bundleFile('templates/nginx/nginx-cloud-proxy.conf.template');
        $edge = $this->bundleFile('templates/nginx/nginx-edge.conf.template');

        $this->assertStringContainsString('listen {{UI_PORT}} ssl default_server;', $https);
        $this->assertStringContainsString('ssl_certificate "{{TLS_CERTIFICATE_NGINX}}";', $https);
        $this->assertStringContainsString('ssl_certificate_key "{{TLS_PRIVATE_KEY_NGINX}}";', $https);
        foreach ([$https, $proxy] as $template) {
            $this->assertStringContainsString('root "{{INSTALL_ROOT_NGINX}}/web/back";', $template);
            $this->assertStringContainsString('fastcgi_param HTTPS on;', $template);
            // A shop Day End is reconciled synchronously by the Back Office.
            $this->assertStringContainsString('fastcgi_read_timeout 310s;', $template);
        }
        $this->assertStringContainsString('root "{{INSTALL_ROOT_NGINX}}/web/front";', $edge);
        $this->assertStringNotContainsString('ssl', $edge);
        $this->assertStringContainsString('max_execution_time=300', $this->bundleFile('templates/php/php.ini.template'));
        $this->assertStringContainsString('memory_limit=1G', $this->bundleFile('templates/php/php.ini.template'));
    }

    public function test_runtime_config_uses_the_servenest_global_for_each_workspace(): void
    {
        $front = $this->bundleFile('templates/runtime/runtime-config.front.js');
        $back = $this->bundleFile('templates/runtime/runtime-config.back.js');

        foreach ([[$front, 'front-office', 'edge'], [$back, 'back-office', 'cloud']] as [$config, $deployment, $backend]) {
            $this->assertStringContainsString('window.__SERVENEST_RUNTIME_CONFIG__ = Object.assign(', $config);
            $this->assertStringContainsString("deployment_mode: \"{$deployment}\"", $config);
            $this->assertStringContainsString("backend_mode: \"{$backend}\"", $config);
            $this->assertStringContainsString('api_base_url: window.location.origin', $config);
            $this->assertStringNotContainsStringIgnoringCase('salexa', $config);
        }
    }

    public function test_shop_servers_sync_over_verified_https_with_the_shared_lookup_key(): void
    {
        $edge = $this->bundleFile('templates/env/edge.env.template');
        $configure = $this->bundleFile('scripts/Configure-ServeNestEnvironment.ps1');

        foreach ([
            'SERVENEST_DEPLOYMENT_MODE=edge',
            'SERVENEST_SYNC_ALLOW_INSECURE_HTTP=false',
            'SERVENEST_SYNC_VERIFY_TLS=true',
            'SERVENEST_SYNC_CA_BUNDLE="{{SYNC_CA_BUNDLE}}"',
            'AUTH_LOGIN_CODE_LOOKUP_KEY={{LOGIN_CODE_LOOKUP_KEY}}',
            'LICENSE_SIGNING_MODE=verify_only',
        ] as $line) {
            $this->assertStringContainsString($line, $edge);
        }
        $this->assertMatchesRegularExpression('/^LICENSE_SIGNATURE_PRIVATE_KEY=$/m', $edge);
        $this->assertStringContainsString("Assert-Origin 'BackOfficeUrl' \$BackOfficeUrl @('https')", $configure);
        $this->assertStringContainsString('Refusing to place a licence issuer private key on a shop POS server.', $configure);
    }

    public function test_back_office_is_the_licence_issuer_with_explicit_platform_administrators(): void
    {
        $cloud = $this->bundleFile('templates/env/cloud.env.template');

        foreach ([
            'SERVENEST_DEPLOYMENT_MODE=cloud',
            'SERVENEST_SYNC_LOCAL_HUB=false',
            'SERVENEST_SEED_SIMULATION_DATA=false',
            'LICENSE_SIGNING_MODE=issuer',
            'LICENSE_SIGNATURE_PRIVATE_KEY={{LICENSE_PRIVATE_KEY}}',
            'PLATFORM_ADMIN_POS_USER_IDS={{PLATFORM_ADMIN_IDS}}',
            'AUTH_LOGIN_CODE_LOOKUP_KEY={{LOGIN_CODE_LOOKUP_KEY}}',
            'LICENSE_LEGACY_HMAC_SIGN=false',
            'LICENSE_ALLOW_UNSIGNED_LEGACY=false',
            'SESSION_SECURE_COOKIE=true',
        ] as $line) {
            $this->assertStringContainsString($line, $cloud);
        }
    }

    public function test_platform_administrator_is_bootstrapped_from_a_protected_file(): void
    {
        $installer = $this->bundleFile('scripts/Install-ServeNest.ps1');

        $this->assertStringContainsString("@('servenest:bootstrap-platform-admin', \$protectedAdministratorFile)", $installer);
        $this->assertStringContainsString("\$bootstrapArguments += '--rotate-existing'", $installer);
        // A retried first install must neither fail nor create a second administrator.
        $this->assertStringContainsString("\$bootstrapArguments += '--ensure'", $installer);
        $this->assertStringContainsString('$rotateAdministrator = [bool] $metadata -and [bool] $platformAdminIds', $installer);
        $this->assertStringContainsString('Remove-SensitiveFile $protectedAdministratorFile', $installer);
        $this->assertStringContainsString("Invoke-Artisan @('db:seed', '--force')", $installer);
        // The password reaches PHP only inside the protected JSON file.
        $this->assertDoesNotMatchRegularExpression('/Invoke-Artisan[^\r\n]*password/i', $installer);
        $this->assertStringContainsString('Key material must be selected as a file', $installer);
    }

    public function test_shop_connection_file_never_carries_the_issuer_key(): void
    {
        $installer = $this->bundleFile('scripts/Install-ServeNest.ps1');
        $start = strpos($installer, '$connectionDocument = [ordered]@{');
        $end = strpos($installer, '$connectionJson = $connectionDocument');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $document = substr($installer, $start, $end - $start);

        $this->assertStringContainsString("kind = 'servenest-shop-connection'", $document);
        $this->assertStringContainsString('public_key = [IO.File]::ReadAllText($licensePublicPath)', $document);
        $this->assertStringContainsString('key = $lookupKey', $document);
        $this->assertStringNotContainsStringIgnoringCase('private', $document);
    }

    public function test_bundle_includes_phpmyadmin_without_copying_machine_configuration(): void
    {
        $builder = $this->bundleFile('Build-WindowsBundle.ps1');
        $installer = $this->bundleFile('scripts/Install-ServeNest.ps1');
        $server = $this->bundleFile('templates/nginx/phpmyadmin-server.conf.template');

        $this->assertStringContainsString('[string] $PhpMyAdminSource', $builder);
        $this->assertStringContainsString("Assert-Files \$phpMyAdmin @('index.php', 'vendor\\autoload.php') 'phpMyAdmin'", $builder);
        foreach (["'config.inc.php'", "'setup'", "'tmp'", "'examples'", "'doc'"] as $excluded) {
            $this->assertStringContainsString($excluded, $builder);
        }
        $this->assertStringContainsString('listen 127.0.0.1:{{PHPMYADMIN_PORT}};', $server);
        $this->assertStringContainsString('location ^~ /vendor/ { return 404; }', $server);
        $this->assertStringContainsString('location ~ \\.php(?:/|$) { return 404; }', $server);
        $this->assertStringContainsString("['auth_type'] = 'cookie'", $installer);
        $this->assertStringContainsString("['host'] = '127.0.0.1'", $installer);
        $this->assertStringContainsString("['AllowNoPassword'] = false", $installer);
        $this->assertStringContainsString('Set-RestrictedFileAcl $phpMyAdminConfig', $installer);
    }

    public function test_bundle_rejects_mixed_php_executables_and_extensions_before_staging(): void
    {
        $builder = $this->bundleFile('Build-WindowsBundle.ps1');
        $validator = $this->bundleFile('scripts/Assert-PhpRuntimeCompatibility.ps1');

        $this->assertLessThan(
            strpos($builder, 'Reset-SafeDirectory $stageRoot'),
            strpos($builder, "scripts\\Assert-PhpRuntimeCompatibility.ps1")
        );
        $this->assertStringContainsString('WindowsPowerShell\\v1.0\\powershell.exe', $builder);
        $this->assertStringContainsString("@('php.exe', 'php-cgi.exe')", $validator);
        $this->assertStringContainsString('PHP Extension Build', $validator);
        $this->assertStringContainsString('extension_dir=', $validator);
        $this->assertStringContainsString("extension '\$module' is not loadable", $validator);
        $this->assertStringContainsString("@('-c', \$probeIni, '-i')", $validator);
        $this->assertStringNotContainsString("'-r'", $validator);
    }

    public function test_builder_never_ships_secrets_customer_data_or_source_maps(): void
    {
        $builder = $this->bundleFile('Build-WindowsBundle.ps1');

        foreach ([
            "'.env', '.env.*'",
            "'*.salexa-company', '*.servenest-foh'",
            "(Join-Path \$api 'zimra')",
            "(Join-Path \$api 'assets')",
            "(Join-Path \$api 'storage\\app')",
            "-ExcludeFiles @('*.map')",
            'install --no-dev',
            '-----BEGIN [A-Z ]*PRIVATE KEY-----\r?\n[A-Za-z0-9+/=]{40,}',
            "src=\"/static/js/main\\.[^\"]+\\.js\"",
        ] as $expected) {
            $this->assertStringContainsString($expected, $builder);
        }
    }

    public function test_health_check_loads_the_spa_bundle_and_requires_running_automatic_services(): void
    {
        $health = $this->bundleFile('scripts/Test-ServeNestInstallation.ps1');

        $this->assertStringContainsString("src=\"(?<path>/static/js/main\\.[^\"]+\\.js)\"", $health);
        $this->assertStringContainsString('"$Origin/runtime-config.js"', $health);
        $this->assertStringContainsString("'migrate:status'", $health);
        $this->assertStringContainsString("\$service.StartType -ne 'Automatic'", $health);
        $this->assertStringContainsString("Assert-Http \"http://127.0.0.1:\$(\$layout.PhpMyAdminPort)/\" 'phpMyAdmin'", $health);
        $this->assertStringNotContainsString('-SkipCertificateCheck', $health);
    }

    public function test_uninstall_checks_ownership_and_preserves_data(): void
    {
        $uninstall = $this->bundleFile('scripts/Uninstall-ServeNestServices.ps1');

        $this->assertStringContainsString('Assert-OwnedApplicationService $layout $root $name', $uninstall);
        $this->assertStringContainsString('Assert-OwnedDatabaseService $layout $root', $uninstall);
        $this->assertStringContainsString("'auto', 'edge', 'cloud', 'till'", $uninstall);
        $this->assertStringNotContainsString('Remove-Item', $uninstall);
        $this->assertStringContainsString(
            'Uninstall-ServeNestServices.ps1"" -InstallRoot ""{app}""',
            $this->bundleFile('include/ServeNestSetup.iss')
        );
    }

    private function bundlePath(string $relative): string
    {
        return base_path('../deployment/windows-bundle/'.$relative);
    }

    private function bundleFile(string $relative): string
    {
        $contents = file_get_contents($this->bundlePath($relative));
        $this->assertIsString($contents, "Missing installer file {$relative}");

        return $contents;
    }
}
