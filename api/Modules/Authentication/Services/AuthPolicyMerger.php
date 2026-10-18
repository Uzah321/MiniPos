<?php

namespace Modules\Authentication\Services;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use Modules\Authentication\Data\EffectiveAuthPolicy;
use Modules\Authentication\Enums\EnterpriseScope;
use Modules\Authentication\Models\AuthPolicy;

final class AuthPolicyMerger
{
    /**
     * Select the winning version at each scope, then overlay each winner from
     * global to till. Numeric arrays are replaced, not merged by index.
     *
     * @param  array<string, mixed>  $defaults
     * @param  iterable<AuthPolicy|array<string, mixed>>  $layers
     *
     * @throws JsonException
     */
    public function merge(string $policyKey, array $defaults, iterable $layers): EffectiveAuthPolicy
    {
        /** @var array<string, array<string, mixed>> $winners */
        $winners = [];
        foreach ($layers as $layer) {
            $normalised = $this->normaliseLayer($layer);
            $scopeKey = $normalised['scope_key'];

            if (! isset($winners[$scopeKey])
                || $this->compareLayers($normalised, $winners[$scopeKey]) > 0) {
                $winners[$scopeKey] = $normalised;
            }
        }

        uasort($winners, function (array $left, array $right): int {
            return [$left['specificity'], $left['scope_key']]
                <=> [$right['specificity'], $right['scope_key']];
        });

        $settings = $defaults;
        $appliedPolicyIds = [];
        foreach ($winners as $winner) {
            $settings = $this->deepReplace($settings, $winner['settings']);
            if ($winner['id'] !== '') {
                $appliedPolicyIds[] = $winner['id'];
            }
        }

        $fingerprintPayload = [
            'policy_key' => $policyKey,
            'settings' => $this->canonicalise($settings),
            'applied_policy_ids' => $appliedPolicyIds,
        ];

        return new EffectiveAuthPolicy(
            policyKey: $policyKey,
            settings: $settings,
            appliedPolicyIds: $appliedPolicyIds,
            fingerprint: hash('sha256', json_encode(
                $fingerprintPayload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            )),
            resolvedAt: new DateTimeImmutable,
        );
    }

    /**
     * @param  AuthPolicy|array<string, mixed>  $layer
     * @return array{id: string, scope_key: string, specificity: int, priority: int, version: int, settings: array<string, mixed>, updated_at: string}
     */
    private function normaliseLayer(AuthPolicy|array $layer): array
    {
        $values = $layer instanceof Model ? $layer->getAttributes() : $layer;
        $settings = $layer instanceof AuthPolicy ? $layer->settings : ($values['settings'] ?? []);
        if (is_string($settings)) {
            $settings = json_decode($settings, true, 512, JSON_THROW_ON_ERROR);
        }
        if (! is_array($settings)) {
            $settings = [];
        }

        $scopeType = EnterpriseScope::tryFrom((string) ($values['scope_type'] ?? ''))
            ?? EnterpriseScope::Global;
        $scopeKey = trim((string) ($values['scope_key'] ?? ''));
        if ($scopeKey === '') {
            $scopeKey = $scopeType->value;
        }

        return [
            'id' => trim((string) ($values['id'] ?? '')),
            'scope_key' => $scopeKey,
            'specificity' => $scopeType->specificity(),
            'priority' => (int) ($values['priority'] ?? 0),
            'version' => (int) ($values['version'] ?? 1),
            'settings' => $settings,
            'updated_at' => (string) ($values['updated_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareLayers(array $left, array $right): int
    {
        return [
            $left['priority'],
            $left['version'],
            $left['updated_at'],
            $left['id'],
        ] <=> [
            $right['priority'],
            $right['version'],
            $right['updated_at'],
            $right['id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function deepReplace(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (isset($base[$key])
                && is_array($base[$key])
                && is_array($value)
                && ! array_is_list($base[$key])
                && ! array_is_list($value)) {
                $base[$key] = $this->deepReplace($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalise($item);
        }

        return $value;
    }
}
