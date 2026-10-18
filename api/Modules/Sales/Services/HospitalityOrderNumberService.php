<?php

namespace Modules\Sales\Services;

use App\Services\CompanySettingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\FastFoodOrderSequence;
use Modules\Sales\Models\SalesTransaction;
use RuntimeException;

/**
 * Allocates a public order number for one shop trading day.
 *
 * The persisted sequence is monotonically increasing within a trading day,
 * but its first visible value is selected with a cryptographically secure
 * random seed. Consequently the ticket remains easy for a kitchen to call in
 * order while not disclosing a shop's lifetime or daily order count.
 */
class HospitalityOrderNumberService
{
    private const DEFAULT_START_MIN = 20;

    private const DEFAULT_START_MAX = 899;

    private const DEFAULT_WIDTH = 4;

    public function __construct(
        private readonly CompanySettingService $settings
    ) {}

    /**
     * @return array{enabled: bool, order_number: string, order_sequence: int, business_date: string, trading_day_id: ?string, prefix: string}
     */
    public function allocate(
        string $companyId,
        string $branchId,
        string $businessDate,
        ?string $tradingDayId = null
    ): array {
        if (! Schema::hasTable('fast_food_order_sequences')) {
            throw new RuntimeException('The Hospitality order sequence schema is not installed.');
        }

        [$minimum, $maximum] = $this->configuredSeedRange($companyId, $branchId);
        $prefix = $this->configuredPrefix($companyId, $branchId);
        $width = $this->configuredWidth($companyId, $branchId);

        // A duplicate-key race is possible only while the first sequence row
        // for a new trading date is being inserted. insertOrIgnore normally
        // resolves it; these retries cover drivers that surface that race.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use (
                    $companyId,
                    $branchId,
                    $businessDate,
                    $tradingDayId,
                    $minimum,
                    $maximum,
                    $prefix,
                    $width
                ): array {
                    $seed = $this->randomStart($minimum, $maximum);

                    DB::table('fast_food_order_sequences')->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'till_id' => null,
                        'trading_day_id' => $tradingDayId,
                        'business_date' => $businessDate,
                        'prefix' => $prefix,
                        'start_number' => $seed,
                        'start_minimum' => $minimum,
                        'start_maximum' => $maximum,
                        'number_width' => $width,
                        'last_number' => $seed - 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    /** @var FastFoodOrderSequence|null $sequence */
                    $sequence = FastFoodOrderSequence::query()
                        ->where('company_id', $companyId)
                        ->where('branch_id', $branchId)
                        ->where('business_date', $businessDate)
                        ->lockForUpdate()
                        ->first();

                    if (! $sequence) {
                        throw new RuntimeException('Unable to initialise the Hospitality order number sequence.');
                    }

                    // Upgrade a row created by an older release without ever
                    // resetting a sequence which has already issued numbers.
                    if (! $sequence->start_number) {
                        $legacyLast = max(0, (int) $sequence->last_number);
                        $start = $legacyLast > 0 ? $legacyLast + 1 : $seed;
                        $sequence->forceFill([
                            'start_number' => $start,
                            'start_minimum' => $minimum,
                            'start_maximum' => $maximum,
                            'number_width' => $width,
                            'last_number' => $legacyLast > 0 ? $legacyLast : $start - 1,
                        ]);
                    }

                    $effectivePrefix = $sequence->prefix ?: $prefix;
                    $effectiveWidth = max(3, min(8, (int) ($sequence->number_width ?: $width)));
                    $nextNumber = ((int) $sequence->last_number) + 1;

                    // Older data or a manual import may already occupy the
                    // next visible value. Skip it while holding the day row.
                    do {
                        $candidate = $effectivePrefix.'-'.str_pad(
                            (string) $nextNumber,
                            $effectiveWidth,
                            '0',
                            STR_PAD_LEFT
                        );

                        $saleExists = Schema::hasTable('sales_transactions')
                            && Schema::hasColumn('sales_transactions', 'order_number')
                            && SalesTransaction::query()
                                ->where('company_id', $companyId)
                                ->where('branch_id', $branchId)
                                ->where('order_business_date', $businessDate)
                                ->where('order_number', $candidate)
                                ->exists();
                        $openOrderExists = Schema::hasTable('hospitality_table_orders')
                            && Schema::hasColumn('hospitality_table_orders', 'order_number')
                            && DB::table('hospitality_table_orders')
                                ->where('company_id', $companyId)
                                ->where('branch_id', $branchId)
                                ->where('order_business_date', $businessDate)
                                ->where('order_number', $candidate)
                                ->exists();

                        if (! $saleExists && ! $openOrderExists) {
                            break;
                        }

                        $nextNumber++;
                    } while (true);

                    $sequence->forceFill([
                        'prefix' => $effectivePrefix,
                        'last_number' => $nextNumber,
                        'trading_day_id' => $tradingDayId ?: $sequence->trading_day_id,
                    ])->save();

                    return [
                        'enabled' => true,
                        'order_number' => $candidate,
                        // This is the visible, randomly-offset daily sequence,
                        // not a count of orders processed by the business.
                        'order_sequence' => $nextNumber,
                        'business_date' => $businessDate,
                        'trading_day_id' => $tradingDayId ?: $sequence->trading_day_id,
                        'prefix' => $effectivePrefix,
                    ];
                }, 3);
            } catch (QueryException $exception) {
                if ($attempt === 3 || (string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to allocate a Hospitality order number.');
    }

    /** @return array{0: int, 1: int} */
    private function configuredSeedRange(string $companyId, string $branchId): array
    {
        $minimum = max(1, (int) $this->settings->get(
            $companyId,
            'hospitality_order_start_minimum',
            self::DEFAULT_START_MIN,
            $branchId
        ));
        $maximum = min(999999, (int) $this->settings->get(
            $companyId,
            'hospitality_order_start_maximum',
            self::DEFAULT_START_MAX,
            $branchId
        ));

        // Never permit a fixed seed: even a misconfigured range must retain
        // the non-disclosure property the public order number is designed for.
        if ($maximum <= $minimum) {
            $maximum = min(999999, $minimum + 879);
            if ($maximum <= $minimum) {
                $minimum = max(1, $maximum - 879);
            }
        }

        return [$minimum, $maximum];
    }

    private function configuredPrefix(string $companyId, string $branchId): string
    {
        $configured = strtoupper(trim((string) $this->settings->get(
            $companyId,
            'hospitality_order_prefix',
            'ORD',
            $branchId
        )));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $configured) ?: 'ORD';

        return substr($prefix, 0, 12);
    }

    private function configuredWidth(string $companyId, string $branchId): int
    {
        return max(3, min(8, (int) $this->settings->get(
            $companyId,
            'hospitality_order_number_width',
            self::DEFAULT_WIDTH,
            $branchId
        )));
    }

    protected function randomStart(int $minimum, int $maximum): int
    {
        return random_int($minimum, $maximum);
    }
}
