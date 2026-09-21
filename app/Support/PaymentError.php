<?php

namespace App\Support;

use App\Payments\VnPayCancellationPending;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PaymentError
{
    public const MESSAGE = 'Không thể hoàn tất thao tác thanh toán/đơn hàng. Vui lòng kiểm tra trạng thái đơn hoặc liên hệ cửa hàng.';

    public static function isPaymentRequest(Request $request): bool
    {
        return $request->is('thanh-toan', 'thanh-toan/*', 'don-hang/*', 'admin/orders', 'admin/orders/*', 'admin/refunds/*');
    }

    public static function message(Throwable $exception): string
    {
        // Class/category only, never getMessage(), trace, previous exception or request.
        Log::warning('Payment operation failed safely.', ['exception_type' => $exception::class]);
        if ($exception instanceof VnPayCancellationPending) {
            return VnPayCancellationPending::MESSAGE;
        }

        return self::MESSAGE;
    }
}
