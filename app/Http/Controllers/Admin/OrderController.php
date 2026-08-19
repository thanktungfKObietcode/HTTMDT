<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
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
            'refunds' => fn ($query) => $query->latest('id'),
        ]);

        $availableTransitions = collect($this->orderStatuses())
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

        try {
            $this->lifecycleService->transition(
                $order,
                $validated['status'],
                $validated['note'] ?? 'Cập nhật bởi quản trị viên.',
                auth()->id()
            );

            return back()->with('success', 'Trạng thái đơn hàng đã được cập nhật.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }
    }

    public function refund(Request $request, Order $order): RedirectResponse
    {
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

            return back()->with('success', 'Yêu cầu hoàn tiền đã được ghi nhận.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['refund' => $exception->getMessage()]);
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