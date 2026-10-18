<?php

namespace Tests\Unit;

use App\Services\HsCode\HsCodeSuggestionService;
use Tests\TestCase;

class HsCodeSuggestionServiceTest extends TestCase
{
    public function test_black_shoes_produce_candidates_but_no_automatic_classification(): void
    {
        $result = (new HsCodeSuggestionService)->suggest('BLACK SHOES');

        $this->assertTrue($result['requires_approval']);
        $this->assertSame(['6401', '6402', '6403', '6404', '6405'],
            array_column($result['candidates'], 'code'));
        $this->assertContains('upper material', $result['missing_details']);
        $this->assertSame(HsCodeSuggestionService::HANDBOOK_URL, $result['source_url']);
        $this->assertSame(HsCodeSuggestionService::TARIFF_URL, $result['verification_url']);
        $this->assertArrayNotHasKey('hs_code', $result);
    }

    public function test_unknown_product_is_not_given_a_guessed_hs_code(): void
    {
        $result = (new HsCodeSuggestionService)->suggest('Mystery item');

        $this->assertSame([], $result['candidates']);
        $this->assertTrue($result['requires_approval']);
    }
}
