<?php

namespace App\Services\Hospitality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class HospitalityCatalogReviewService
{
    /**
     * Validate a parsed workbook against the authenticated company and the
     * explicitly selected brand. The workbook never chooses its own tenant.
     *
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>
     */
    public function review(
        array $catalog,
        string $companyId,
        string $brandId,
        ?string $branchId = null
    ): array {
        $brand = DB::table('brands')
            ->where('company_id', $companyId)
            ->where('id', $brandId)
            ->first();
        if (! $brand
            || (property_exists($brand, 'deleted_at') && $brand->deleted_at !== null)
            || (property_exists($brand, 'status') && strtolower((string) $brand->status) !== 'active')) {
            throw new RuntimeException('The selected brand is not active in this company.');
        }

        $branch = $this->resolveBranch($companyId, $brandId, $branchId);
        $issues = $this->normaliseIssues($catalog);
        $brandHints = $this->brandHints($catalog);
        $unmatchedHints = array_values(array_filter(
            $brandHints,
            fn (string $hint): bool => ! $this->brandMatches(
                $hint,
                (string) ($brand->code ?? ''),
                (string) ($brand->name ?? ''),
                (string) ($catalog['format'] ?? '')
            )
        ));

        if ($unmatchedHints !== []) {
            $crossBrand = count($brandHints) > 1;
            $issues[] = [
                'severity' => 'error',
                'code' => $crossBrand ? 'CROSS_BRAND_WORKBOOK' : 'BRAND_MISMATCH',
                'message' => $crossBrand
                    ? 'The workbook contains brand values that do not match the selected brand.'
                    : "Workbook brand {$unmatchedHints[0]} does not match the selected brand.",
                'sheet' => null,
                'row' => null,
                'entity_code' => null,
                'field' => 'brand_id',
            ];
        }

        $issues = $this->uniqueIssues($issues);
        $warnings = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['severity'] ?? '') === 'warning'
        ));
        $errors = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['severity'] ?? '') === 'error'
        ));
        $source = $catalog['source'] ?? [];

        return [
            'brand' => [
                'id' => (string) $brand->id,
                'code' => $brand->code ?? null,
                'name' => $brand->name ?? null,
            ],
            'branch' => $branch,
            'format' => $catalog['format'] ?? null,
            'source' => [
                'name' => $source['name'] ?? null,
                'sha256' => $source['sha256'] ?? null,
                'version' => $source['version'] ?? null,
            ],
            'brand_hints' => $brandHints,
            'sheets' => array_values($catalog['sheet_manifest'] ?? []),
            'statistics' => $catalog['statistics'] ?? [],
            'warnings' => $warnings,
            'errors' => $errors,
            'can_commit' => $errors === [],
        ];
    }

    /** @return array<string, mixed>|null */
    private function resolveBranch(string $companyId, string $brandId, ?string $branchId): ?array
    {
        $branchId = trim((string) $branchId);
        if ($branchId === '') {
            return null;
        }

        $branch = DB::table('company_branches')
            ->where('company_id', $companyId)
            ->where('id', $branchId)
            ->first();
        if (! $branch) {
            throw new RuntimeException('The selected branch does not belong to this company.');
        }

        if (Schema::hasTable('shops')) {
            $shop = DB::table('shops')
                ->where('company_id', $companyId)
                ->where('brand_id', $brandId)
                ->where('branch_id', $branchId);
            if (Schema::hasColumn('shops', 'deleted_at')) {
                $shop->whereNull('deleted_at');
            }
            if (! $shop->exists()) {
                throw new RuntimeException('The selected branch is not assigned to the selected brand.');
            }
        }

        return [
            'id' => (string) $branch->id,
            'code' => $branch->branch_code ?? null,
            'name' => $branch->branch_name ?? null,
        ];
    }

    /** @param array<string, mixed> $catalog @return array<int, array<string, mixed>> */
    private function normaliseIssues(array $catalog): array
    {
        $issues = [];
        foreach ($catalog['issues'] ?? [] as $issue) {
            if (! is_array($issue)) {
                continue;
            }
            $issues[] = [
                'severity' => in_array(($issue['severity'] ?? null), ['warning', 'error'], true)
                    ? $issue['severity']
                    : 'warning',
                'code' => (string) ($issue['code'] ?? 'WORKBOOK_WARNING'),
                'message' => (string) ($issue['message'] ?? 'Workbook warning.'),
                'sheet' => $issue['sheet'] ?? null,
                'row' => isset($issue['row']) ? (int) $issue['row'] : null,
                'entity_code' => $issue['entity_code'] ?? null,
                'field' => $issue['field'] ?? null,
            ];
        }

        if ($issues === []) {
            foreach ($catalog['warnings'] ?? [] as $message) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'WORKBOOK_WARNING',
                    'message' => (string) $message,
                    'sheet' => null,
                    'row' => null,
                    'entity_code' => null,
                    'field' => null,
                ];
            }
        }

        return $issues;
    }

    /** @param array<string, mixed> $catalog @return array<int, string> */
    private function brandHints(array $catalog): array
    {
        $hints = $catalog['brand_hints'] ?? [];
        if (! is_array($hints)) {
            $hints = [];
        }
        if ($hints === [] && trim((string) ($catalog['brand_hint'] ?? '')) !== '') {
            $hints[] = (string) $catalog['brand_hint'];
        }

        $unique = [];
        foreach ($hints as $hint) {
            $hint = trim((string) $hint);
            if ($hint !== '') {
                $unique[$this->normaliseBrand($hint)] = $hint;
            }
        }

        return array_values($unique);
    }

    private function brandMatches(string $hint, string $code, string $name, string $format): bool
    {
        $hint = $this->normaliseBrand($hint);
        $code = $this->normaliseBrand($code);
        $name = $this->normaliseBrand($name);
        if ($hint === '' || in_array($hint, [$code, $name], true)) {
            return true;
        }

        return match ($format) {
            'pizza_inn_gaap' => in_array($hint, ['PZ', 'PIZZAINN'], true)
                && ($code === 'PZ' || str_contains($name, 'PIZZAINN')),
            'chicken_inn_gaap' => in_array($hint, ['CH', 'CI', 'CHICKENINN'], true)
                && (in_array($code, ['CH', 'CI'], true) || str_contains($name, 'CHICKENINN')),
            default => strlen($hint) >= 4 && (str_contains($name, $hint) || str_contains($hint, $name)),
        };
    }

    private function normaliseBrand(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', trim($value)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function uniqueIssues(array $issues): array
    {
        $unique = [];
        foreach ($issues as $issue) {
            $key = implode('|', [
                $issue['severity'] ?? '',
                $issue['code'] ?? '',
                $issue['sheet'] ?? '',
                $issue['row'] ?? '',
                $issue['entity_code'] ?? '',
                $issue['message'] ?? '',
            ]);
            $unique[$key] = $issue;
        }

        return array_values($unique);
    }
}
