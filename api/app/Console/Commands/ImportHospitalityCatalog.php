<?php

namespace App\Console\Commands;

use App\Services\Hospitality\HospitalityCatalogImporter;
use App\Services\Hospitality\HospitalityCatalogWorkbookReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ImportHospitalityCatalog extends Command
{
    protected $signature = 'hospitality:catalog-import
        {path : Absolute path to a Pizza Inn or Chicken Inn GAAP .xlsx workbook}
        {--company= : Company UUID, code, domain, or exact name}
        {--brand= : Brand UUID, code, or exact name within the company}
        {--branch= : Optional company branch UUID assigned to the selected brand}
        {--currency=USD : Three-letter catalogue currency}
        {--source=gaap : Stable source-system key used for idempotency}
        {--created-by= : Optional PosUser UUID recorded as the importer}
        {--dry-run : Parse and validate without writing to the database}
        {--force : Re-apply a workbook that already completed successfully}
        {--json : Emit the result as JSON}';

    protected $description = 'Validate or import a hospitality GAAP BOM catalogue';

    public function handle(
        HospitalityCatalogWorkbookReader $reader,
        HospitalityCatalogImporter $importer
    ): int {
        try {
            $this->components->task('Reading and validating the hospitality workbook');
            $catalog = $reader->read((string) $this->argument('path'));

            if ($this->option('dry-run')) {
                $result = [
                    'status' => 'validated',
                    'format' => $catalog['format'],
                    'source' => $catalog['source'],
                    'brand_hint' => $catalog['brand_hint'],
                    'market_hint' => $catalog['market_hint'],
                    'statistics' => $catalog['statistics'],
                    'warnings' => $catalog['warnings'],
                ];
                $this->renderResult($result);

                return self::SUCCESS;
            }

            if (config('servnest.deployment_mode') !== 'cloud') {
                $this->components->error('Catalogue imports are cloud-only. Use --dry-run on an edge installation.');

                return self::FAILURE;
            }
            $company = $this->resolveCompany(trim((string) $this->option('company')));
            $brand = $this->resolveBrand(trim((string) $this->option('brand')), (string) $company->id);
            $result = $importer->import(
                $catalog,
                (string) $company->id,
                (string) $brand->id,
                (string) $this->option('currency'),
                (string) $this->option('source'),
                $this->option('created-by') ?: null,
                (bool) $this->option('force'),
                $this->option('branch') ?: null
            );
            $this->renderResult([
                ...$result,
                'format' => $catalog['format'],
                'company' => ['id' => $company->id, 'name' => $company->company_name],
                'brand' => ['id' => $brand->id, 'name' => $brand->name],
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());
            if ($this->output->isVerbose()) {
                $this->line($exception->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    private function resolveCompany(string $identifier): object
    {
        if ($identifier === '') {
            throw new \RuntimeException('--company is required for a real import.');
        }
        $query = DB::table('companies')->where(function ($scope) use ($identifier): void {
            $scope->where('id', $identifier)
                ->orWhere('domain', $identifier)
                ->orWhere('company_name', $identifier);
            if (Schema::hasColumn('companies', 'company_code')) {
                $scope->orWhere('company_code', $identifier);
            }
        });
        $matches = $query->limit(2)->get();
        if ($matches->count() !== 1) {
            throw new \RuntimeException($matches->isEmpty()
                ? 'The requested company was not found.'
                : 'The company identifier is ambiguous; use its UUID.');
        }

        return $matches->first();
    }

    private function resolveBrand(string $identifier, string $companyId): object
    {
        if ($identifier === '') {
            throw new \RuntimeException('--brand is required for a real import.');
        }
        $matches = DB::table('brands')
            ->where('company_id', $companyId)
            ->where(function ($scope) use ($identifier): void {
                $scope->where('id', $identifier)
                    ->orWhere('code', $identifier)
                    ->orWhere('name', $identifier);
            })
            ->limit(2)
            ->get();
        if ($matches->count() !== 1) {
            throw new \RuntimeException($matches->isEmpty()
                ? 'The requested brand was not found for that company.'
                : 'The brand identifier is ambiguous; use its UUID.');
        }

        return $matches->first();
    }

    /** @param array<string, mixed> $result */
    private function renderResult(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return;
        }

        $this->newLine();
        $this->components->info('Hospitality catalogue '.($result['status'] ?? 'processed').'.');
        if (isset($result['format'])) {
            $this->line('Format: '.$result['format']);
        }
        $statistics = $result['statistics'] ?? [];
        if (is_array($statistics) && $statistics !== []) {
            $this->table(
                ['Entity', 'Count'],
                collect($statistics)
                    ->filter(fn ($value): bool => is_int($value) || is_float($value))
                    ->map(fn ($value, $key): array => [(string) $key, (string) $value])
                    ->values()
                    ->all()
            );
        }
        $warnings = $result['warnings'] ?? [];
        if (is_array($warnings) && $warnings !== []) {
            $this->components->warn(count($warnings).' validation warning(s); showing the first 20.');
            foreach (array_slice($warnings, 0, 20) as $warning) {
                $this->line(' - '.$warning);
            }
        }
    }
}
