<?php

namespace Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Modules\Authentication\Services\LoginCodeBlindIndex;
use PHPUnit\Framework\TestCase;

class LoginCodeBlindIndexTest extends TestCase
{
    public function test_digest_is_deterministic_company_scoped_and_preserves_leading_zeroes(): void
    {
        $index = new LoginCodeBlindIndex([
            'v1' => str_repeat('a', 32),
        ], 'v1');

        $first = $index->digestForCompany('company-1', '0123');

        $this->assertSame($first, $index->digestForCompany('company-1', '0123'));
        $this->assertNotSame($first, $index->digestForCompany('company-2', '0123'));
        $this->assertNotSame($first, $index->digestForCompany('company-1', '123'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertStringNotContainsString('0123', $first);
    }

    public function test_rotation_returns_a_candidate_for_each_available_key(): void
    {
        $index = new LoginCodeBlindIndex([
            'old' => str_repeat('o', 32),
            'new' => str_repeat('n', 32),
        ], 'new');

        $candidates = $index->candidateDigestsForCompany('company-1', '9087');

        $this->assertSame(['old', 'new'], array_keys($candidates));
        $this->assertNotSame($candidates['old'], $candidates['new']);
        $this->assertSame('new', $index->activeKeyId());
        $this->assertTrue($index->matches(
            $candidates['new'],
            'new',
            'company-1',
            '9087'
        ));
    }

    public function test_alphanumeric_codes_are_policy_agnostic_case_sensitive_and_trimmed(): void
    {
        $index = new LoginCodeBlindIndex(['v1' => str_repeat('a', 32)], 'v1');

        $digest = $index->digestForCompany('company-1', ' A1b2 ');

        $this->assertSame($digest, $index->digestForCompany('company-1', 'A1b2'));
        $this->assertNotSame($digest, $index->digestForCompany('company-1', 'a1b2'));
        $this->assertSame('A1b2', LoginCodeBlindIndex::canonicalizeCode("\tA1b2\n"));
    }

    public function test_empty_codes_are_rejected_before_hashing(): void
    {
        $index = new LoginCodeBlindIndex(['v1' => str_repeat('a', 32)], 'v1');

        $this->expectException(InvalidArgumentException::class);
        $index->digestForCompany('company-1', '   ');
    }

    public function test_short_or_missing_key_material_fails_closed(): void
    {
        $this->expectException(LogicException::class);

        new LoginCodeBlindIndex(['v1' => 'short'], 'v1');
    }
}
