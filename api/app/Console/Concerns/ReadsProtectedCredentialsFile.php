<?php

namespace App\Console\Concerns;

use JsonException;
use RuntimeException;

/**
 * Reads the short-lived, Administrators/SYSTEM-only JSON file an installer
 * writes for a bootstrap command, so credentials never appear on a command
 * line, in process listings or in installer logs.
 */
trait ReadsProtectedCredentialsFile
{
    /** A non-sensitive failure the command may print. */
    abstract protected function credentialsFileException(string $message): RuntimeException;

    /** @return array<string,mixed> */
    protected function readCredentialsFile(string $path): array
    {
        $path = trim($path);
        $absolute = preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 || str_starts_with($path, '/');
        if (! $absolute || str_contains($path, "\0") || str_contains($path, '://')) {
            throw $this->credentialsFileException('The credentials file must be an absolute local path.');
        }

        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath) || is_link($path)) {
            throw $this->credentialsFileException('The protected credentials file cannot be read.');
        }
        if (strtolower((string) pathinfo($realPath, PATHINFO_EXTENSION)) !== 'json') {
            throw $this->credentialsFileException('The protected credentials file must use the .json extension.');
        }

        $size = filesize($realPath);
        if (! is_int($size) || $size < 2 || $size > 32768) {
            throw $this->credentialsFileException('The protected credentials file has an invalid size.');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $permissions = fileperms($realPath);
            if (! is_int($permissions) || ($permissions & 0077) !== 0) {
                throw $this->credentialsFileException(
                    'The credentials file must be readable and writable only by its owner (mode 0600).'
                );
            }
        }

        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            throw $this->credentialsFileException('The protected credentials file cannot be opened.');
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                throw $this->credentialsFileException('The protected credentials file cannot be locked for reading.');
            }
            $contents = stream_get_contents($handle, 32769);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (! is_string($contents) || strlen($contents) > 32768) {
            throw $this->credentialsFileException('The protected credentials file cannot be read safely.');
        }
        // Windows installers may write UTF-8 with a byte-order mark.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        try {
            $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->credentialsFileException('The protected credentials file does not contain valid JSON.');
        } finally {
            $contents = '';
        }

        if (! is_array($decoded)) {
            throw $this->credentialsFileException('The protected credentials file root must be a JSON object.');
        }

        return $decoded;
    }
}
