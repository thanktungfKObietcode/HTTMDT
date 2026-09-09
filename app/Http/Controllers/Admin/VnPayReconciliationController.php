<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Payments\PaymentEventOutcome;
use App\Services\VnPayReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class VnPayReconciliationController extends Controller
{
    public function __invoke(Request $request, Order $order, PaymentTransaction $transaction, VnPayReconciliationService $service): RedirectResponse
    {
        abort_unless((int) $transaction->order_id === (int) $order->id && $transaction->gateway === 'vnpay', 404);
        try {
            $result = $service->reconcile($transaction, (int) $request->user()->id);
            $message = match ($result->outcome) {
                PaymentEventOutcome::Processed => 'Đã đối soát kết quả VNPay đã xác minh.',
                PaymentEventOutcome::AlreadyProcessed => 'Đã đối soát; giao dịch đã được ghi nhận, không thu tiền lần nữa.',
                default => 'Chưa thể xác nhận trạng thái an toàn. Giao dịch cần tiếp tục đối soát; không tự hủy hoặc hoàn tiền.',
            };

            return redirect()->route('admin.orders.show', $order)->with('success', $message);
        } catch (Throwable) {
            return redirect()->route('admin.orders.show', $order)->withErrors([
                'payment' => 'Đối soát chưa hoàn tất. Không có thao tác ghi nhận thanh toán thủ công.',
            ]);
        }
    }
}
