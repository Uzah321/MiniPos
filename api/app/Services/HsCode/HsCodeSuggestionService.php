<?php

namespace App\Services\HsCode;

final class HsCodeSuggestionService
{
    public const TARIFF_URL = 'https://etariff.zimra.co.zw/';
    public const HANDBOOK_URL = 'https://www.zimra.co.zw/customs/classification-of-goods/tariff-handbook';

    /**
     * These are heading-level search suggestions, not product classifications.
     * The current ZIMRA eTariff and the item's actual materials must be checked
     * before an administrator approves any code for fiscal receipts.
     */
    private const FOOTWEAR_HEADINGS = [
        ['code' => '6401', 'description' => 'Waterproof footwear with outer soles and uppers of rubber or plastics.', 'check' => 'Confirm waterproof construction and rubber/plastic uppers and soles.'],
        ['code' => '6402', 'description' => 'Other footwear with outer soles and uppers of rubber or plastics.', 'check' => 'Confirm both outer sole and upper are rubber or plastics.'],
        ['code' => '6403', 'description' => 'Footwear with outer soles of rubber, plastics, leather or composition leather and uppers of leather.', 'check' => 'Confirm the upper is leather and check the outer sole.'],
        ['code' => '6404', 'description' => 'Footwear with outer soles of rubber, plastics, leather or composition leather and uppers of textile materials.', 'check' => 'Confirm the upper is textile and check the outer sole.'],
        ['code' => '6405', 'description' => 'Other footwear.', 'check' => 'Use only if the footwear does not fall under another heading.'],
    ];

    public function suggest(string $description): array
    {
        $normalised = mb_strtolower(trim($description));
        $footwear = preg_match('/\b(shoe|shoes|boot|boots|sandal|sandals|sneaker|sneakers|trainer|trainers|footwear)\b/u', $normalised) === 1;

        return [
            'query' => $description,
            'source_name' => 'ZIMRA Tariff Handbook, Chapter 64',
            'source_url' => self::HANDBOOK_URL,
            'verification_url' => self::TARIFF_URL,
            'requires_approval' => true,
            'candidates' => $footwear ? self::FOOTWEAR_HEADINGS : [],
            'missing_details' => $footwear
                ? ['upper material', 'outer sole material', 'waterproof construction', 'special purpose or protective toe cap']
                : [],
            'message' => $footwear
                ? 'These headings are suggestions only. Confirm the item materials and the current ZIMRA tariff before approval.'
                : 'No reviewed automatic suggestion is available for this description. Search the current ZIMRA eTariff and approve the verified code in Back Office.',
        ];
    }
}
