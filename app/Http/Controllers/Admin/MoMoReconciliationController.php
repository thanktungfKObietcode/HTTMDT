<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Payments\PaymentEventOutcome;
use App\Services\MoMoReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

final class MoMoReconciliationController extends Controller
{
    public function __invoke(Request $request, Order $order, PaymentTransaction $transaction,
        MoMoReconciliationService $service): RedirectResponse
    {
        abort_unless((int) $transaction->order_id === (int) $order->id && $transaction->gateway === 'momo', 404);
        try {
            $outcome = $service->reconcile($transaction, (int) $request->user()->id);
            $message = in_array($outcome, [PaymentEventOutcome::Processed, PaymentEventOutcome::AlreadyProcessed], true)
                ? 'Đã đối soát MoMo; trạng thái thanh toán được xác nhận.'
                : 'Trạng thái MoMo chưa kết luận. Không cập nhật thanh toán thủ công.';
            return redirect()->route('admin.orders.show', $order)->with('success', $message);
        } catch (Throwable) {
            return redirect()->route('admin.orders.show', $order)->withErrors([
                'payment' => 'Đối soát MoMo chưa hoàn tất. Không cập nhật thanh toán thủ công.',
            ]);
        }
    }
}
