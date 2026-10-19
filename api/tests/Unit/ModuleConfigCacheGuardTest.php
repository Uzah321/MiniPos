<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Module service providers re-read their config files during boot. Once the
 * installers run `config:cache`, Laravel no longer loads .env, so a re-read
 * replaces cached secrets (audit identifier key, login-code lookup keys)
 * with empty env() values and every sign-in fails. Each provider must skip
 * the re-read when the configuration is cached.
 */
class ModuleConfigCacheGuardTest extends TestCase
{
    public function test_module_providers_do_not_re_read_config_when_it_is_cached(): void
    {
        $providers = glob(dirname(__DIR__, 2).'/Modules/*/Providers/*ServiceProvider.php') ?: [];
        $checked = 0;

        foreach ($providers as $path) {
            $source = (string) file_get_contents($path);
            if (! preg_match('/function registerConfig\(\): void\s*\{(.*?)\n    \}/s', $source, $match)) {
                continue;
            }

            $checked++;
            $body = $match[1];
            $guard = strpos($body, 'configurationIsCached()');
            $read = strpos($body, 'module_path(');

            $this->assertNotFalse($guard, basename($path).' must skip registerConfig when config is cached.');
            $this->assertLessThan($read, $guard, basename($path).' must check the config cache before reading files.');
        }

        $this->assertGreaterThan(0, $checked, 'No module providers with registerConfig were found.');
    }
}
