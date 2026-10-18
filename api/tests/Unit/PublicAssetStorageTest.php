<?php

namespace Tests\Unit;

use App\Services\PublicAssetStorage;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class PublicAssetStorageTest extends TestCase
{
    private string $assetRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assetRoot = storage_path(
            'framework/testing/public-assets-'.bin2hex(random_bytes(6))
        );
    }

    protected function tearDown(): void
    {
        $testingRoot = realpath(storage_path('framework/testing'));
        $assetRoot = realpath($this->assetRoot);

        if (
            $testingRoot !== false
            && $assetRoot !== false
            && str_starts_with($assetRoot, $testingRoot.DIRECTORY_SEPARATOR)
        ) {
            File::deleteDirectory($assetRoot);
        }

        parent::tearDown();
    }

    public function test_it_creates_nested_asset_directories_and_hardens_uploaded_files(): void
    {
        $storage = new PublicAssetStorage($this->assetRoot);
        $directory = $storage->ensureDirectory('category-images/company-1');

        $this->assertDirectoryExists($directory);
        $this->assertSame(
            $this->assetRoot.DIRECTORY_SEPARATOR.'category-images'.DIRECTORY_SEPARATOR.'company-1',
            $directory
        );

        $file = $directory.DIRECTORY_SEPARATOR.'sample.png';
        file_put_contents($file, 'test-image-bytes');

        $storage->hardenFile($file);

        $this->assertFileExists($file);

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(PublicAssetStorage::DIRECTORY_MODE, fileperms($directory) & 07777);
            $this->assertSame(PublicAssetStorage::FILE_MODE, fileperms($file) & 0777);
        }
    }

    public function test_laravel_resolves_the_shared_sibling_asset_root(): void
    {
        $storage = $this->app->make(PublicAssetStorage::class);

        $this->assertSame(
            dirname(base_path()).DIRECTORY_SEPARATOR.'assets',
            $storage->root()
        );
    }

    public function test_it_rejects_absolute_and_traversing_asset_paths(): void
    {
        $storage = new PublicAssetStorage($this->assetRoot);

        foreach (['../outside', 'category-images/../outside', '/absolute/path', 'C:/absolute/path'] as $path) {
            try {
                $storage->path($path);
                $this->fail("Expected path to be rejected: {$path}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_refuses_to_harden_a_file_outside_the_asset_root(): void
    {
        $storage = new PublicAssetStorage($this->assetRoot);
        $storage->ensureDirectory('product-images/company-1');

        $outside = storage_path('framework/testing/outside-'.bin2hex(random_bytes(6)).'.png');
        file_put_contents($outside, 'outside');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('outside the public asset root');

            $storage->hardenFile($outside);
        } finally {
            File::delete($outside);
        }
    }

    public function test_it_resolves_deletes_and_serves_only_files_inside_the_asset_root(): void
    {
        $storage = new PublicAssetStorage($this->assetRoot);
        $this->app->instance(PublicAssetStorage::class, $storage);
        $directory = $storage->ensureDirectory('hierarchy/brands/brand-1');
        $file = $directory.DIRECTORY_SEPARATOR.'logo.png';
        file_put_contents($file, 'public-image-bytes');

        $this->assertSame(realpath($file), $storage->existingFile('assets/hierarchy/brands/brand-1/logo.png'));
        $this->get('/assets/hierarchy/brands/brand-1/logo.png')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/assets/../outside.png')->assertNotFound();

        $this->assertFalse($storage->delete('https://cdn.example.test/logo.png'));
        $this->assertTrue($storage->delete('assets/hierarchy/brands/brand-1/logo.png'));
        $this->assertFileDoesNotExist($file);
    }
}
