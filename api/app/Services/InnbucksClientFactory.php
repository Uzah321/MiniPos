<?php
namespace App\Services;

use App\Services\InnbucksClient;
use App\Services\InnbucksCredentialResolver;

class InnbucksClientFactory
{
    public function __construct(private InnbucksCredentialResolver $resolver) {}

    public function make(string $brandId, string $complexId, ?string $environment = null): InnbucksClient
    {
        $cred = $this->resolver->resolve($brandId, $complexId, $environment);


        return new InnbucksClient($cred);
    }
}
