<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\GeneralSettings\Models\PosTill;
use Modules\GeneralSettings\Models\Printer;
use Modules\GeneralSettings\Models\QzCertificate;
use Modules\Sales\Models\HospitalityOutputConfiguration;

class HospitalityOutputConfigurationService
{
    public const MODE_KITCHEN_PRINTER = 'kitchen_printer';

    public const MODE_KDS = 'kds';

    /**
     * @return array<string, mixed>
     */
    public function forTill(PosTill $till): array
    {
        $configuration = Schema::hasTable('hospitality_output_configurations')
            ? HospitalityOutputConfiguration::query()
                ->where('company_id', $till->company_id)
                ->where('branch_id', $till->branch_id)
                ->where('till_id', $till->id)
                ->first()
            : null;

        $mode = (string) ($configuration?->mode ?: self::MODE_KITCHEN_PRINTER);
        // KDS is intentionally advertised but cannot become operational until
        // its dedicated module is delivered.
        if ($mode !== self::MODE_KITCHEN_PRINTER) {
            $mode = self::MODE_KITCHEN_PRINTER;
        }

        $printer = $this->resolveKitchenPrinter($till);
        $qzConfigured = $this->hasExactQzIdentity($till);
        $autoPrint = $configuration ? (bool) $configuration->auto_print : true;
        $usesQz = strtolower((string) ($printer?->printer_type ?? '')) === 'qz';
        $ready = $mode === self::MODE_KITCHEN_PRINTER
            && $autoPrint
            && $printer !== null
            && $usesQz
            && $qzConfigured;

        return [
            'mode' => $mode,
            'auto_print' => $autoPrint,
            'copies' => max(1, min(5, (int) ($configuration?->copies ?: 1))),
            'dispatch_on' => 'order_submit',
            'capabilities' => [
                'kitchen_printer' => [
                    'available' => true,
                    'transport' => 'qz',
                ],
                'kds' => [
                    'available' => false,
                    'status' => 'planned',
                ],
            ],
            'printer' => $printer?->toArray(),
            'qz' => [
                'certificate_configured' => $qzConfigured,
                'transport' => 'qz',
            ],
            'ready' => $ready,
            'version' => (int) ($configuration?->version ?: 0),
        ];
    }

    /**
     * @param  array{mode?: string, auto_print?: bool, copies?: int}  $input
     * @return array<string, mixed>
     */
    public function updateForTill(PosTill $till, array $input, ?string $actorId): array
    {
        $mode = strtolower(trim((string) ($input['mode'] ?? self::MODE_KITCHEN_PRINTER)));

        if ($mode !== self::MODE_KITCHEN_PRINTER) {
            throw new \DomainException('KDS_NOT_AVAILABLE');
        }

        DB::transaction(function () use ($till, $input, $actorId, $mode): void {
            /** @var HospitalityOutputConfiguration|null $configuration */
            $configuration = HospitalityOutputConfiguration::query()
                ->where('company_id', $till->company_id)
                ->where('branch_id', $till->branch_id)
                ->where('till_id', $till->id)
                ->lockForUpdate()
                ->first();

            if (! $configuration) {
                $configuration = new HospitalityOutputConfiguration;
                $configuration->id = (string) Str::uuid();
                $configuration->company_id = $till->company_id;
                $configuration->branch_id = $till->branch_id;
                $configuration->till_id = $till->id;
                $configuration->version = 0;
            }

            $configuration->mode = $mode;
            $configuration->auto_print = array_key_exists('auto_print', $input)
                ? (bool) $input['auto_print']
                : ($configuration->exists ? (bool) $configuration->auto_print : true);
            $configuration->copies = max(1, min(5, (int) (
                $input['copies'] ?? ($configuration->copies ?: 1)
            )));
            $configuration->dispatch_on = 'order_submit';
            $configuration->configured_by = $actorId;
            $configuration->version = ((int) $configuration->version) + 1;
            $configuration->save();

            // Advance the till's content version so the cloud snapshot and
            // activated edge can detect this exact terminal configuration.
            if (Schema::hasColumn('pos_tills', 'configuration_version')) {
                DB::table('pos_tills')
                    ->where('id', $till->id)
                    ->where('company_id', $till->company_id)
                    ->where('branch_id', $till->branch_id)
                    ->update([
                        'configuration_version' => DB::raw('COALESCE(configuration_version, 0) + 1'),
                    ]);
            }
        }, 3);

        return $this->forTill($till);
    }

    private function resolveKitchenPrinter(PosTill $till): ?Printer
    {
        if (! Schema::hasTable('printers')) {
            return null;
        }

        return Printer::query()
            ->where('company_id', $till->company_id)
            ->where('target_printing', 'kitchen')
            ->where('is_active', true)
            ->where(function ($query) use ($till): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
            })
            ->where(function ($query) use ($till): void {
                $query->whereNull('till_id')->orWhere('till_id', $till->id);
            })
            ->orderByRaw('CASE WHEN till_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('is_default')
            ->orderBy('printer_name')
            ->first();
    }

    private function hasExactQzIdentity(PosTill $till): bool
    {
        if (! Schema::hasTable('qz_certificates')) {
            return false;
        }

        $identity = QzCertificate::query()
            ->where('company_id', $till->company_id)
            ->where('branch_id', $till->branch_id)
            ->where('till_id', $till->id)
            ->where('is_active', true)
            ->first();

        return $identity !== null
            && filled($identity->certificate_path)
            && filled($identity->private_key_path)
            && Storage::disk('qz_private')->exists($identity->certificate_path)
            && Storage::disk('qz_private')->exists($identity->private_key_path);
    }
}
