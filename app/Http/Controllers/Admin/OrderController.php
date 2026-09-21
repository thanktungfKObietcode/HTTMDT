<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Services\OrderLifecycleService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderLifecycleService $lifecycleService,
        private readonly PaymentService $paymentService,
    ) {
    }

    public function index(Request $request): View
    {
        $orders = Order::with('user')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%' . trim($request->string('q')->toString()) . '%';
                $query->where(function ($builder) use ($term) {
                    $builder->where('order_number', 'like', $term)
                        ->orWhere('customer_name', 'like', $term)
                        ->orWhere('customer_email', 'like', $term);
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('payment_status'), fn ($query) => $query->where('payment_status', $request->input('payment_status')))
            ->latest()
            ->paginate(15)
            ->appends($request->query());

        return view('admin.orders.index', [
            'pageTitle' => 'Quản lý đơn hàng',
            'orders' => $orders,
            'orderStatuses' => $this->orderStatuses(),
            'paymentStatuses' => [PaymentService::PAYMENT_PENDING, PaymentService::PAYMENT_PAID, PaymentService::PAYMENT_FAILED, PaymentService::PAYMENT_REFUNDED],
        ]);
    }

    public function show(Order $order): View
    {
        $order->load([
            'user',
            'shippingMethod',
            'items.product',
            'items.productVariant',
            'statusHistory.changedBy',
            'paymentTransactions' => fn ($query) => $query->latest('id'),
            'refunds' => fn ($query) => $query
                ->with(['requestedBy', 'reviewedBy', 'processedBy', 'paymentTransaction'])
                ->latest('id'),
        ]);

        $availableTransitions = collect($this->orderStatuses())
            ->reject(fn ($status) => $status === OrderLifecycleService::STATUS_REFUNDED)
            ->filter(fn ($status) => $this->lifecycleService->canTransition((string) $order->status, $status))
            ->values()
            ->all();

        return view('admin.orders.show', [
            'pageTitle' => 'Chi tiết đơn hàng',
            'order' => $order,
            'availableTransitions' => $availableTransitions,
        ]);
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in($this->orderStatuses())],
            'note' => 'nullable|string|max:1000',
        ]);

        if ($validated['status'] === OrderLifecycleService::STATUS_REFUNDED) {
            return back()->withErrors([
                'status' => 'Trạng thái refunded chỉ được cập nhật qua refund workflow đã hoàn tất.',
            ]);
        }

        try {
            $this->lifecycleService->transition(
                $order,
                $validated['status'],
                $validated['note'] ?? 'Cập nhật bởi quản trị viên.',
                auth()->id()
            );

            return back()->with('success', 'Trạng thái đơn hàng đã được cập nhật.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['status' => \App\Support\PaymentError::message($exception)]);
        }
    }

    public function approveRefund(Request $request, Refund $refund): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => 'nullable|string|max:1000',
        ]);

        try {
            $this->paymentService->approveRefund(
                $refund,
                (int) auth()->id(),
                $validated['admin_note'] ?? null
            );

            return back()->with('success', 'Yêu cầu hoàn tiền đã được duyệt.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['refund' => \App\Support\PaymentError::message($exception)]);
        }
    }

    public function rejectRefund(Request $request, Refund $refund): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => 'required|string|max:1000',
        ]);

        try {
            $this->paymentService->rejectRefund(
                $refund,
                (int) auth()->id(),
                $validated['admin_note']
            );

            return back()->with('success', 'Yêu cầu hoàn tiền đã bị từ chối.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['refund' => \App\Support\PaymentError::message($exception)]);
        }
    }

    public function executeRefund(Request $request, Refund $refund): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => 'nullable|string|max:1000',
        ]);

        try {
            $executedRefund = $refund->paymentTransaction?->gateway === 'momo'
                ? app(\App\Services\MoMoRefundService::class)->execute($refund, (int) auth()->id(), $validated['admin_note'] ?? null)
                : $this->paymentService->executeRefund($refund, (int) auth()->id(), $validated['admin_note'] ?? null);

            $message = $executedRefund->status === Refund::STATUS_COMPLETED
                ? ($refund->paymentTransaction?->gateway === 'momo'
                    ? 'MoMo đã xác nhận hoàn tiền.' : 'Hoàn tiền nội bộ đã hoàn tất.')
                : 'Yêu cầu hoàn tiền đang được xử lý.';

            return back()->with('success', $message);
        } catch (\Throwable $exception) {
            return back()->withErrors(['refund' => \App\Support\PaymentError::message($exception)]);
        }
    }

    /** @return array<int, string> */
    private function orderStatuses(): array
    {
        return [
            OrderLifecycleService::STATUS_PENDING,
            OrderLifecycleService::STATUS_CONFIRMED,
            OrderLifecycleService::STATUS_PROCESSING,
            OrderLifecycleService::STATUS_SHIPPED,
            OrderLifecycleService::STATUS_DELIVERED,
            OrderLifecycleService::STATUS_CANCELLED,
            OrderLifecycleService::STATUS_REFUNDED,
        ];
    }
}
