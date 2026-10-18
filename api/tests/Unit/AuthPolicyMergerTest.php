<?php

namespace Tests\Unit;

use Modules\Authentication\Services\AuthPolicyMerger;
use PHPUnit\Framework\TestCase;

class AuthPolicyMergerTest extends TestCase
{
    public function test_it_selects_one_winner_per_scope_and_overlays_broad_to_specific(): void
    {
        $merger = new AuthPolicyMerger;
        $effective = $merger->merge('authentication', [
            'pos' => ['login_mode' => 'username_password', 'require_device' => true],
            'trusted_networks' => ['hq'],
        ], [
            $this->layer('global-old', 'global', 'global', 1, 1, [
                'pos' => ['login_mode' => 'code'],
            ]),
            $this->layer('global-winner', 'global', 'global', 1, 2, [
                'pos' => ['login_mode' => 'code_or_username_password'],
            ]),
            $this->layer('company-winner', 'company', 'company:one', 0, 3, [
                'pos' => ['require_device' => false],
                'trusted_networks' => ['shop-lan'],
            ]),
            $this->layer('shop-winner', 'shop', 'shop:one', 0, 1, [
                'pos' => ['login_mode' => 'code'],
            ]),
        ]);

        $this->assertSame('code', $effective->get('pos.login_mode'));
        $this->assertFalse($effective->get('pos.require_device'));
        $this->assertSame(['shop-lan'], $effective->get('trusted_networks'));
        $this->assertSame([
            'global-winner',
            'company-winner',
            'shop-winner',
        ], $effective->appliedPolicyIds);
    }

    public function test_priority_wins_before_version_at_the_same_scope(): void
    {
        $effective = (new AuthPolicyMerger)->merge('credential', [], [
            $this->layer('new-low-priority', 'company', 'company:one', 1, 99, [
                'value' => 'low',
            ]),
            $this->layer('older-high-priority', 'company', 'company:one', 10, 2, [
                'value' => 'high',
            ]),
        ]);

        $this->assertSame('high', $effective->get('value'));
        $this->assertSame(['older-high-priority'], $effective->appliedPolicyIds);
    }

    public function test_fingerprint_is_independent_of_associative_key_order(): void
    {
        $merger = new AuthPolicyMerger;

        $left = $merger->merge('session', ['a' => 1, 'b' => ['c' => 2]], []);
        $right = $merger->merge('session', ['b' => ['c' => 2], 'a' => 1], []);

        $this->assertSame($left->fingerprint, $right->fingerprint);
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function layer(
        string $id,
        string $scopeType,
        string $scopeKey,
        int $priority,
        int $version,
        array $settings
    ): array {
        return compact('id', 'scopeType', 'scopeKey', 'priority', 'version', 'settings') + [
            'scope_type' => $scopeType,
            'scope_key' => $scopeKey,
        ];
    }
}
