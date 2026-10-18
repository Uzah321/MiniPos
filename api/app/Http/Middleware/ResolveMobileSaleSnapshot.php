<?php

namespace App\Http\Middleware;

use App\Services\Sync\MobileSaleSnapshotException;
use App\Services\Sync\MobileSaleSnapshotService;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ResolveMobileSaleSnapshot
{
    public function __construct(private readonly MobileSaleSnapshotService $snapshots) {}

    public function handle(Request $request, Closure $next)
    {
        try {
            $snapshot = $this->snapshots->resolve(
                app('sync.node'),
                app('company'),
                app('till'),
                $request->input('configuration_content_hash')
            );
        } catch (MobileSaleSnapshotException $error) {
            return response()->json([
                'message' => $error->getMessage(),
                'code' => $error->errorCode,
            ], $error->httpStatus);
        }

        try {
            $snapshot->assertSaleContext($request->input('business_date'));
            $baseCurrency = $snapshot->baseCurrency();
            $saleCurrency = $snapshot->currency((string) $request->input('currency_code'));
            $snapshot->exchangeRate((string) $baseCurrency->code, (string) $saleCurrency->code);
            $snapshot->paymentType((string) $request->input('payment_type_id'));
            foreach ((array) $request->input('payments', []) as $payment) {
                if (is_array($payment)) {
                    $snapshot->paymentType((string) ($payment['payment_type_id'] ?? ''));
                }
            }
            foreach ((array) $request->input('products', []) as $product) {
                if (is_array($product)) {
                    $price = $snapshot->productPrice(
                        (string) ($product['product_id'] ?? ''),
                        isset($product['product_price_id']) ? (string) $product['product_price_id'] : null
                    );
                    $snapshot->convert(
                        (float) $price->price,
                        (string) ($price->currency ?: $baseCurrency->code),
                        (string) $saleCurrency->code
                    );
                }
            }
        } catch (MobileSaleSnapshotException $error) {
            return response()->json([
                'message' => $error->getMessage(),
                'code' => $error->errorCode,
            ], $error->httpStatus);
        } catch (InvalidArgumentException $error) {
            return response()->json([
                'message' => $error->getMessage(),
                'code' => 'MOBILE_CONFIGURATION_REFERENCE_INVALID',
            ], 409);
        }

        $request->attributes->set('mobile_sale_snapshot', $snapshot);

        return $next($request);
    }
}
