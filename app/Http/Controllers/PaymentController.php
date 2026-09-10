<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderLifecycleService;
use App\Services\PaymentService;
use App\Support\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly OrderLifecycleService $lifecycleService,
    ) {
    }

    public function show(Order $order): View|RedirectResponse
    {
        $this->authorizeOrderOwner($order);

        try {
            $transaction = $order->payment_method === PaymentMethod::VNPAY
                ? $order->paymentTransactions()->latest('id')->first()
                : $this->paymentService->createOrGetPendingTransaction($order);
        } catch (\Throwable $exception) {
            return redirect()->route('order.show', $order)
                ->withErrors(['payment' => \App\Support\PaymentError::message($exception)]);
        }

        return view('storefront.payment', [
            'pageTitle' => 'Thanh toan | Silver Atelier',
            'order' => $order->load(['paymentTransactions' => fn ($query) => $query->latest('id')]),
            'transaction' => $transaction,
            'canPayVnPay' => $this->paymentService->canInitiateVnPay($order),
        ]);
    }

    public function callback(Request $request): JsonResponse
    {
        // The APP_KEY-based simulation is never a production settlement endpoint.
        abort_if(config('app.env') === 'production', 404);
        $payload = $request->validate([
            'order_number' => 'required|string',
            'transaction_id' => 'required|string',
            'payment_status' => ['required', Rule::in([PaymentService::PAYMENT_PAID, PaymentService::PAYMENT_FAILED])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'gateway' => ['required', Rule::in($this->paymentService->callbackPaymentMethods())],
            'signature' => 'required|string',
        ]);

        if (! $this->isValidSignature($payload)) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        try {
            $transaction = $this->paymentService->handleCallback($payload);

            return response()->json([
                'message' => 'Callback processed',
                'transaction_id' => $transaction->transaction_id,
                'payment_status' => $transaction->payment_status,
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => \App\Support\PaymentError::message($exception),
            ], 422);
        }
    }

    public function requestRefund(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrderOwner($order);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $this->paymentService->requestRefund(
                $order,
                (string) $validated['amount'],
                $validated['reason'] ?? null,
                (int) auth()->id()
            );

            return redirect()->route('order.show', $order)
                ->with('success', 'Yêu cầu hoàn tiền đã được gửi và đang chờ duyệt.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['refund' => \App\Support\PaymentError::message($exception)]);
        }
    }

    public function cancel(Order $order): RedirectResponse
    {
        $this->authorizeOrderOwner($order);

        try {
            $this->lifecycleService->transitionAsCustomer(
                $order,
                OrderLifecycleService::STATUS_CANCELLED,
                'Khach hang huy don',
                auth()->id()
            );

            return redirect()->route('order.show', $order)->with('success', 'Don hang da duoc huy.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['status' => \App\Support\PaymentError::message($exception)]);
        }
    }

    public function confirmDelivered(Order $order): RedirectResponse
    {
        $this->authorizeOrderOwner($order);

        try {
            $this->lifecycleService->transitionAsCustomer(
                $order,
                OrderLifecycleService::STATUS_DELIVERED,
                'Khach hang xac nhan da nhan hang',
                auth()->id()
            );

            return redirect()->route('order.show', $order)->with('success', 'Don hang da duoc xac nhan giao thanh cong.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['status' => \App\Support\PaymentError::message($exception)]);
        }
    }

    private function authorizeOrderOwner(Order $order): void
    {
        if ((int) auth()->id() !== (int) $order->user_id) {
            abort(403, 'Ban khong co quyen truy cap don hang nay.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isValidSignature(array $payload): bool
    {
        $expected = $this->paymentService->callbackSignature($payload);

        return hash_equals($expected, (string) $payload['signature']);
    }
}
