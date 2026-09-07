<?php

namespace App\Sales;

use App\Discounts\Coupon;
use App\Customers\Customer;
use App\Sales\Sale;
use App\Sales\SaleLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ManagerVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Section 4 of the guide: attaching/registering/removing a customer, manual
 * discounts, coupon redemption and loyalty point redemption. Everything
 * here adjusts a sale's economics or customer attachment before payment;
 * basket line CRUD itself stays in BasketService.
 */
class SaleAdjustmentService
{
    private const SCALE = 4;

    public function __construct(
        private readonly BasketService $basketService,
        private readonly AuditLogger $auditLogger,
        private readonly ManagerVerifier $managerVerifier,
    ) {}

    public function attachCustomer(Sale $sale, Customer $customer, User $actor): Sale
    {
        $sale->update(['customer_id' => $customer->id]);

        $this->auditLogger->log($actor, 'sale.customer.attached', $sale, after: ['customer_id' => $customer->id]);

        return $sale->fresh();
    }

    /**
     * @param  array{name: string, phone?: ?string, email?: ?string}  $data
     */
    public function registerAndAttachCustomer(Sale $sale, array $data, User $actor): Sale
    {
        if (empty($data['phone']) && empty($data['email'])) {
            throw ValidationException::withMessages([
                'phone' => ['Provide at least a phone number or an email address.'],
            ]);
        }

        $duplicate = Customer::query()
            ->when($data['phone'] ?? null, fn ($query, $phone) => $query->orWhere('phone', $phone))
            ->when($data['email'] ?? null, fn ($query, $email) => $query->orWhere('email', $email))
            ->first();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'phone' => ['A customer with this phone number or email already exists.'],
            ]);
        }

        $customer = Customer::create([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'account_status' => 'none',
        ]);

        $sale->update(['customer_id' => $customer->id]);

        $this->auditLogger->log(
            $actor,
            'sale.customer.registered',
            $sale,
            after: ['customer_id' => $customer->id, 'consent' => true],
        );

        return $sale->fresh();
    }

    public function removeCustomer(Sale $sale, User $actor): Sale
    {
        if ($sale->loyalty_points_redeemed > 0) {
            throw ValidationException::withMessages([
                'customer' => ['Reverse the loyalty point redemption before removing this customer.'],
            ]);
        }

        $before = ['customer_id' => $sale->customer_id];
        $sale->update(['customer_id' => null]);

        $this->auditLogger->log($actor, 'sale.customer.removed', $sale, before: $before);

        return $sale->fresh();
    }

    /**
     * Manual discount: applied to one line, or spread across the whole
     * basket when no line is given. A discount at or above the configured
     * threshold requires a manager PIN.
     */
    public function applyManualDiscount(
        Sale $sale,
        ?SaleLine $line,
        string $type,
        string $value,
        string $reason,
        ?string $managerPin,
        User $actor,
    ): Sale {
        if (! in_array($type, ['percent', 'fixed'], true)) {
            throw ValidationException::withMessages(['type' => ['Discount type must be percent or fixed.']]);
        }

        if (bccomp($value, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages(['value' => ['Discount value must be greater than zero.']]);
        }

        if ($type === 'percent' && bccomp($value, '100', self::SCALE) > 0) {
            throw ValidationException::withMessages(['value' => ['A percentage discount cannot exceed 100.']]);
        }

        $manager = $this->resolveManagerIfNeeded($type, $value, $managerPin);

        if ($line) {
            $lineSubtotal = bcmul((string) $line->quantity, (string) $line->unit_price, self::SCALE);
            $amount = $type === 'percent'
                ? bcmul($lineSubtotal, bcdiv($value, '100', self::SCALE), self::SCALE)
                : $value;

            if (bccomp($amount, $lineSubtotal, self::SCALE) > 0) {
                $amount = $lineSubtotal;
            }

            $this->basketService->setLineDiscount($sale, $line, $amount);
        } else {
            $amount = $type === 'percent'
                ? bcmul((string) $sale->subtotal, bcdiv($value, '100', self::SCALE), self::SCALE)
                : $value;

            $this->basketService->setBasketDiscount($sale, $amount);
        }

        $sale = $sale->fresh();

        $this->auditLogger->log(
            $manager ?? $actor,
            'sale.discount.manual',
            $sale,
            after: ['type' => $type, 'value' => $value, 'line_id' => $line?->id],
            reason: $reason,
        );

        return $sale;
    }

    /**
     * Coupon redemption: validated against date window, active flag and
     * usage limit, then applied as a basket-level discount on the current
     * (pre-coupon) total.
     */
    public function applyCoupon(Sale $sale, string $code, User $actor): Sale
    {
        $this->basketService->assertMutable($sale);

        if ($sale->coupon_id) {
            throw ValidationException::withMessages(['code' => ['This sale already has a coupon applied.']]);
        }

        if (! $sale->lines()->exists()) {
            throw ValidationException::withMessages(['code' => ['Add at least one item before applying a coupon.']]);
        }

        $coupon = Coupon::query()->where('code', $code)->first();

        if (! $coupon || ! $coupon->active) {
            throw ValidationException::withMessages(['code' => ['Coupon not found or inactive.']]);
        }

        $now = now();

        if (($coupon->starts_at && $now->lt($coupon->starts_at)) || ($coupon->ends_at && $now->gt($coupon->ends_at))) {
            throw ValidationException::withMessages(['code' => ['This coupon is not currently valid.']]);
        }

        if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
            throw ValidationException::withMessages(['code' => ['This coupon has already reached its usage limit.']]);
        }

        $runningTotal = (string) $sale->total;
        $amount = $coupon->discount_type === 'percent'
            ? bcmul($runningTotal, bcdiv((string) $coupon->discount_value, '100', self::SCALE), self::SCALE)
            : (string) $coupon->discount_value;

        if (bccomp($amount, $runningTotal, self::SCALE) > 0) {
            $amount = $runningTotal;
        }

        $sale->update(['coupon_id' => $coupon->id, 'coupon_discount_amount' => $amount]);
        $coupon->increment('times_used');
        $sale = $this->basketService->recalculate($sale);

        $this->auditLogger->log(
            $actor,
            'sale.coupon.applied',
            $sale,
            after: ['coupon_id' => $coupon->id, 'code' => $code, 'amount' => $amount],
        );

        return $sale;
    }

    /**
     * Loyalty redemption: reserves the member's points immediately (they
     * are deducted from the balance at redemption time, not merely at
     * completion) and applies the equivalent currency value as a discount.
     */
    public function redeemLoyalty(Sale $sale, int $points, User $actor): Sale
    {
        $this->basketService->assertMutable($sale);

        if ($points <= 0) {
            throw ValidationException::withMessages(['points' => ['Points must be greater than zero.']]);
        }

        if (! $sale->customer_id) {
            throw ValidationException::withMessages(['customer' => ['Attach a customer before redeeming loyalty points.']]);
        }

        if ($sale->loyalty_points_redeemed > 0) {
            throw ValidationException::withMessages(['points' => ['Loyalty points have already been redeemed on this sale.']]);
        }

        return DB::transaction(function () use ($sale, $points, $actor) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($sale->customer_id);

            if ($customer->loyalty_points_balance < $points) {
                throw ValidationException::withMessages(['points' => ['This customer does not have enough loyalty points.']]);
            }

            $rate = (string) config('pos.loyalty_redeem_rate');
            $amount = bcmul((string) $points, $rate, self::SCALE);
            $runningTotal = (string) $sale->total;

            if (bccomp($amount, $runningTotal, self::SCALE) > 0) {
                $amount = $runningTotal;
            }

            $customer->decrement('loyalty_points_balance', $points);
            $sale->update(['loyalty_points_redeemed' => $points, 'loyalty_discount_amount' => $amount]);
            $sale = $this->basketService->recalculate($sale);

            $this->auditLogger->log(
                $actor,
                'sale.loyalty.redeemed',
                $sale,
                after: ['points' => $points, 'amount' => $amount, 'customer_balance_after' => $customer->fresh()->loyalty_points_balance],
            );

            return $sale;
        });
    }

    private function resolveManagerIfNeeded(string $type, string $value, ?string $managerPin): ?User
    {
        $percentThreshold = (string) config('pos.discount_manager_threshold_percent');
        $amountThreshold = (string) config('pos.discount_manager_threshold_amount');

        $requiresManager = $type === 'percent'
            ? bccomp($value, $percentThreshold, self::SCALE) >= 0
            : bccomp($value, $amountThreshold, self::SCALE) >= 0;

        return $requiresManager ? $this->managerVerifier->verify($managerPin) : null;
    }
}
