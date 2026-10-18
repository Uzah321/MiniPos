<?php
// app/Services/InnbucksCredentialResolver.php
namespace App\Services;

use Modules\Inbucks\Models\InnbucksCredidentials;

class InnbucksCredentialResolver
{
    public function resolve(string $brandId, string $complexId, ?string $environment = null):InnbucksCredidentials
    {
        $env = $environment ?: (app()->environment('Production') ? 'Production' : 'Staging');

        return InnbucksCredidentials::query()
            ->where('brand_id', $brandId)
            ->where('complex_id', $complexId)
            ->where('environment', $env)
            ->where('active', true)
            ->firstOrFail();
    }
}
