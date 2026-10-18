<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * OpenSSL options with an explicit config file. PHP reads OPENSSL_CONF only
     * at startup and Windows builds have no default openssl.cnf, so key and
     * certificate generation fails unless a config file is passed per call.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function openSslOptions(array $options = []): array
    {
        $candidates = [
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
            'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
            'C:\\xampp\\apache\\conf\\openssl.cnf',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return ['config' => $candidate] + $options;
            }
        }

        return $options;
    }
}
