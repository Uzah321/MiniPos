<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Manages the shared, Nginx-published asset tree beside the Laravel app.
 *
 * A production install at /var/www/servenest/api therefore publishes files
 * from /var/www/servenest/assets. Deployment must make that root writable by
 * the PHP-FPM group; this service preserves setgid/group-write inheritance for
 * every directory created below it.
 */
class PublicAssetStorage
{
    public const DIRECTORY_MODE = 02775;

    public const FILE_MODE = 0664;

    private string $root;

    public function __construct(?string $root = null)
    {
        $resolvedRoot = $root ?? dirname(base_path()).DIRECTORY_SEPARATOR.'assets';
        $resolvedRoot = rtrim($resolvedRoot, '/\\');

        if ($resolvedRoot === '') {
            throw new InvalidArgumentException('The public asset root cannot be empty.');
        }

        $this->root = $resolvedRoot;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relativePath = ''): string
    {
        $segments = $this->relativeSegments($relativePath);

        return $segments === []
            ? $this->root
            : $this->root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * Ensure the asset root and every requested child directory are writable
     * and use setgid 2775 on Unix. Creating one segment at a time prevents a
     * restrictive process umask from leaving intermediate folders at 0755.
     */
    public function ensureDirectory(string $relativeDirectory): string
    {
        $segments = $this->relativeSegments($relativeDirectory);
        $current = $this->root;

        $this->ensureDirectoryPath($current);

        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            $this->ensureDirectoryPath($current);
        }

        return $current;
    }

    public function hardenFile(string $absolutePath): void
    {
        $resolvedRoot = realpath($this->root);
        $resolvedFile = realpath($absolutePath);

        if ($resolvedRoot === false || $resolvedFile === false || ! is_file($resolvedFile)) {
            throw new RuntimeException('The uploaded public asset was not saved correctly.');
        }

        $rootPrefix = rtrim($resolvedRoot, '/\\').DIRECTORY_SEPARATOR;
        $comparisonRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($rootPrefix) : $rootPrefix;
        $comparisonFile = PHP_OS_FAMILY === 'Windows' ? strtolower($resolvedFile) : $resolvedFile;

        if (! str_starts_with($comparisonFile, $comparisonRoot)) {
            throw new RuntimeException('Refusing to change permissions outside the public asset root.');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            if (! @chmod($resolvedFile, self::FILE_MODE)) {
                throw new RuntimeException(
                    "Unable to apply mode 0664 to public asset file: {$resolvedFile}."
                );
            }

            clearstatcache(true, $resolvedFile);

            if (($this->permissions($resolvedFile) & 0777) !== self::FILE_MODE) {
                throw new RuntimeException(
                    "Public asset file does not have the required mode 0664: {$resolvedFile}."
                );
            }
        }
    }

    /**
     * Resolve an existing public asset without ever escaping the configured
     * asset root. This is also used by the development asset route when the
     * Laravel and React servers run on separate ports.
     */
    public function existingFile(string $relativePath): ?string
    {
        $candidate = $this->path($this->withoutAssetsPrefix($relativePath));
        $resolvedRoot = realpath($this->root);
        $resolvedFile = realpath($candidate);

        if ($resolvedRoot === false || $resolvedFile === false || ! is_file($resolvedFile)) {
            return null;
        }

        $rootPrefix = rtrim($resolvedRoot, '/\\').DIRECTORY_SEPARATOR;
        $comparisonRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($rootPrefix) : $rootPrefix;
        $comparisonFile = PHP_OS_FAMILY === 'Windows' ? strtolower($resolvedFile) : $resolvedFile;

        return str_starts_with($comparisonFile, $comparisonRoot) ? $resolvedFile : null;
    }

    /**
     * Delete a stored local asset. Remote/data URLs are intentionally ignored.
     */
    public function delete(?string $storedPath): bool
    {
        $path = trim((string) $storedPath);
        if ($path === '' || preg_match('/^(?:https?:|data:|blob:)/i', $path) === 1) {
            return false;
        }

        $file = $this->existingFile($path);

        return $file !== null && @unlink($file);
    }

    /** @return list<string> */
    private function relativeSegments(string $relativePath): array
    {
        if (str_contains($relativePath, "\0")) {
            throw new InvalidArgumentException('Public asset paths cannot contain null bytes.');
        }

        $normalized = str_replace('\\', '/', trim($relativePath));

        if (
            str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
        ) {
            throw new InvalidArgumentException('Public asset paths must be relative.');
        }

        if ($normalized === '') {
            return [];
        }

        $segments = array_values(array_filter(
            explode('/', $normalized),
            static fn (string $segment): bool => $segment !== ''
        ));

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Public asset paths cannot traverse directories.');
            }
        }

        return $segments;
    }

    private function withoutAssetsPrefix(string $path): string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');

        return str_starts_with(strtolower($normalized), 'assets/')
            ? substr($normalized, strlen('assets/'))
            : $normalized;
    }

    private function ensureDirectoryPath(string $path): void
    {
        if (file_exists($path) && ! is_dir($path)) {
            throw new RuntimeException("Public asset path is not a directory: {$path}.");
        }

        if (! is_dir($path) && ! @mkdir($path, self::DIRECTORY_MODE) && ! is_dir($path)) {
            throw $this->directoryConfigurationException($path);
        }

        if (! is_writable($path)) {
            throw $this->directoryConfigurationException($path);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        clearstatcache(true, $path);

        if (($this->permissions($path) & 07777) !== self::DIRECTORY_MODE) {
            @chmod($path, self::DIRECTORY_MODE);
            clearstatcache(true, $path);
        }

        if (($this->permissions($path) & 07777) !== self::DIRECTORY_MODE) {
            throw $this->directoryConfigurationException($path);
        }
    }

    private function permissions(string $path): int
    {
        $permissions = @fileperms($path);

        return $permissions === false ? 0 : $permissions;
    }

    private function directoryConfigurationException(string $path): RuntimeException
    {
        return new RuntimeException(
            "Public asset directory is not writable with mode 2775: {$path}. "
            .'Configure the deployment owner/group for PHP-FPM group write access; do not use 0777.'
        );
    }
}
