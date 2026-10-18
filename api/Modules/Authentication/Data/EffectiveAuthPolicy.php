<?php

namespace Modules\Authentication\Data;

use DateTimeImmutable;

final readonly class EffectiveAuthPolicy
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $appliedPolicyIds
     */
    public function __construct(
        public string $policyKey,
        public array $settings,
        public array $appliedPolicyIds,
        public string $fingerprint,
        public DateTimeImmutable $resolvedAt,
    ) {}

    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->settings, $path, $default);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'policy_key' => $this->policyKey,
            'settings' => $this->settings,
            'applied_policy_ids' => $this->appliedPolicyIds,
            'fingerprint' => $this->fingerprint,
            'resolved_at' => $this->resolvedAt->format(DATE_ATOM),
        ];
    }
}
