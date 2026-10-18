<?php

use Illuminate\Support\Facades\Route;
use App\Services\PublicAssetStorage;

Route::get('/', function () {
    return view('welcome');
});

/*
 * `php artisan serve` publishes only api/public while production Nginx/XAMPP
 * publishes the sibling assets directory directly. Keep uploaded images
 * reachable in both deployment profiles without copying or duplicating them.
 */
Route::get('/assets/{path}', function (string $path, PublicAssetStorage $assets) {
    try {
        $file = $assets->existingFile($path);
    } catch (\InvalidArgumentException) {
        $file = null;
    }

    abort_unless($file, 404);

    return response()->file($file, [
        'Cache-Control' => 'public, max-age=86400',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->where('path', '.*')->name('public-assets.show');
