<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderLifecycleService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly OrderLifecycleService $lifecycleService,
    ) {
    }

    public function show(Order $order): View
    {
        $this->authorizeOrderOwner($order);

        $transaction = $this->paymentService->createOrGetPendingTransaction($order);

        return view('storefront.payment', [
            'pageTitle' => 'Thanh toan | Silver Atelier',
            'order' => $order->load(['paymentTransactions' => fn ($query) => $query->latest('id')]),
            'transaction' => $transaction,
        ]);
    }

    public function callback(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'order_number' => 'required|string',
            'transaction_id' => 'required|string',
            'payment_status' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'gateway' => 'nullable|string',
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
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function requestRefund(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrderOwner($order);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $this->paymentService->requestRefund(
                $order,
                (float) $validated['amount'],
                $validated['reason'] ?? null,
                auth()->id()
            );

            return redirect()->route('order.show', $order)->with('success', 'Hoan tien da duoc ghi nhan.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['refund' => $exception->getMessage()]);
        }
    }

    public function cancel(Order $order): RedirectResponse
    {
        $this->authorizeOrderOwner($order);

        try {
            $this->lifecycleService->transition(
                $order,
                OrderLifecycleService::STATUS_CANCELLED,
                'Khach hang huy don',
                auth()->id()
            );

            return redirect()->route('order.show', $order)->with('success', 'Don hang da duoc huy.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }
    }

    public function confirmDelivered(Order $order): RedirectResponse
    {
        $this->authorizeOrderOwner($order);

        try {
            $this->lifecycleService->transition(
                $order,
                OrderLifecycleService::STATUS_DELIVERED,
                'Khach hang xac nhan da nhan hang',
                auth()->id()
            );

            return redirect()->route('order.show', $order)->with('success', 'Don hang da duoc xac nhan giao thanh cong.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
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
        $secret = (string) config('app.key');
        $plain = implode('|', [
            $payload['order_number'],
            $payload['transaction_id'],
            strtolower((string) $payload['payment_status']),
            number_format((float) $payload['amount'], 2, '.', ''),
        ]);

        $expected = hash_hmac('sha256', $plain, $secret);

        return hash_equals($expected, (string) $payload['signature']);
    }
}
