<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Services\CartService;
use App\Services\PaymentService;
use App\Services\VnPayPaymentService;
use App\Support\PaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly CartService $cartService
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $cart = $this->cartService->currentCart($request);

        if ($cart->items()->count() === 0) {
            return redirect()->route('cart.index')->with('error', 'Giỏ hàng của bạn đang trống.');
        }

        $cartSummary = $this->cartService->summarize($cart);
        if (! $cartSummary['can_checkout']) {
            return redirect()->route('cart.index')->with('error', 'Vui lòng cập nhật các sản phẩm không còn khả dụng trước khi thanh toán.');
        }

        $items = $cartSummary['entries']->pluck('item');
        $subtotal = $cartSummary['subtotal'];
        $shippingMethods = ShippingMethod::where('is_active', true)->get();
        $selectedShippingMethodId = (int) old('shipping_method_id', $shippingMethods->first()?->id);
        $selectedShippingMethod = $shippingMethods->firstWhere('id', $selectedShippingMethodId) ?: $shippingMethods->first();
        $couponCode = trim((string) $request->input('coupon_code', old('coupon_code', '')));
        $couponDiscount = 0.0;
        $couponError = null;

        if ($couponCode !== '') {
            try {
                [, $couponDiscount] = $this->resolveCoupon($couponCode, $subtotal);
            } catch (\Throwable $exception) {
                $couponError = $exception->getMessage();
            }
        }

        return view('storefront.checkout', [
            'pageTitle' => 'Thanh toán | Silver Atelier',
            'cart' => $cart,
            'items' => $items,
            'cartEntries' => $cartSummary['entries'],
            'subtotal' => $subtotal,
            'shippingMethods' => $shippingMethods,
            'selectedShippingMethod' => $selectedShippingMethod,
            'couponCode' => $couponCode,
            'couponDiscount' => $couponDiscount,
            'couponError' => $couponError,
            'checkoutToken' => old('checkout_token', (string) Str::uuid()),
            'savedAddresses' => auth()->user()?->addresses()->where('is_active', true)->get() ?? collect(),
            'defaultAddress' => auth()->user()?->addresses()->where('is_default', true)->where('is_active', true)->first(),
            'user' => auth()->user(),
            'paymentMethods' => $this->paymentService->checkoutPaymentMethods(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:20',
            'customer_email' => 'nullable|email|max:255',
            'province' => 'required|string|max:255',
            'district' => 'required|string|max:255',
            'ward' => 'required|string|max:255',
            'address_line' => 'required|string|max:500',
            'shipping_method_id' => 'required|exists:shipping_methods,id',
            'payment_method' => ['required', Rule::in($this->paymentService->checkoutPaymentMethods())],
            'coupon_code' => 'nullable|string|max:100',
            'checkout_token' => ['required', 'uuid'],
        ]);

        $existingOrder = $this->findExistingOrder($validated['checkout_token']);

        if ($existingOrder) {
            return redirect()->route('order.show', $existingOrder);
        }

        if ($this->checkoutTokenBelongsToAnotherUser($validated['checkout_token'])) {
            return back()->withErrors([
                'checkout' => 'Checkout token không hợp lệ.',
            ])->withInput();
        }

        $cart = $this->cartService->currentCart($request);

        if ($request->session()->has('cart_notices')) {
            return redirect()->route('cart.index')->with(
                'error',
                'Giỏ hàng vừa được đồng bộ hoặc điều chỉnh. Vui lòng kiểm tra lại trước khi đặt hàng.'
            );
        }

        $shippingMethod = ShippingMethod::where('is_active', true)->findOrFail($validated['shipping_method_id']);

        try {
            $order = DB::transaction(function () use ($cart, $validated, $shippingMethod): Order {
                $lockedCart = Cart::query()
                    ->whereKey($cart->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $items = CartItem::query()
                    ->where('cart_id', $lockedCart->id)
                    ->orderBy('product_id')
                    ->orderByRaw('product_variant_id IS NULL')
                    ->orderBy('product_variant_id')
                    ->lockForUpdate()
                    ->get();

                if ($items->isEmpty()) {
                    throw new \RuntimeException('Giỏ hàng của bạn đang trống.');
                }

                $validatedItems = $this->lockAndBuildCartItems($items);
                $subtotal = $validatedItems->sum(fn (array $entry) => $entry['quantity'] * $entry['unit_price']);

                $shippingFee = (float) $shippingMethod->base_fee;
                $couponCode = trim((string) ($validated['coupon_code'] ?? ''));
                $coupon = null;
                $discountAmount = 0.0;

                if ($couponCode !== '') {
                    [$coupon, $discountAmount] = $this->resolveCoupon($couponCode, $subtotal);
                }

                $totalAmount = $subtotal + $shippingFee - $discountAmount;

                $order = Order::create([
                    'user_id' => auth()->id(),
                    'shipping_method_id' => $shippingMethod->id,
                    'order_number' => $this->generateOrderNumber(),
                    'checkout_token' => $validated['checkout_token'],
                    'status' => 'pending',
                    'payment_method' => $validated['payment_method'],
                    'payment_status' => 'pending',
                    'customer_name' => $validated['customer_name'],
                    'customer_phone' => $validated['customer_phone'],
                    'customer_email' => $validated['customer_email'] ?? null,
                    'shipping_address' => trim($validated['address_line'] . ', ' . $validated['ward'] . ', ' . $validated['district'] . ', ' . $validated['province']),
                    'subtotal' => $subtotal,
                    'shipping_fee' => $shippingFee,
                    'discount_amount' => $discountAmount,
                    'total_amount' => $totalAmount,
                ]);

                foreach ($validatedItems as $entry) {
                    $product = $entry['product'];
                    $item = $entry['item'];
                    $variant = $entry['variant'];

                    $this->decrementLockedStock($entry['inventory'], $entry['quantity']);

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'product_name' => $product->name,
                        'sku' => $variant?->sku ?: $product->sku,
                        'quantity' => $entry['quantity'],
                        'unit_price' => $entry['unit_price'],
                        'total_price' => $entry['unit_price'] * $entry['quantity'],
                    ]);
                }

                if ($coupon) {
                    CouponUsage::create([
                        'coupon_id' => $coupon->id,
                        'user_id' => auth()->id(),
                        'order_number' => $order->order_number,
                        'discount_amount' => $discountAmount,
                    ]);
                }

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'status' => 'pending',
                    'note' => 'Đơn hàng được tạo thành công.',
                    'changed_by' => auth()->id(),
                ]);

                $this->paymentService->createOrGetPendingTransaction($order);

                $lockedCart->items()->delete();

                return $order;
            }, 3);

            if ($order->payment_method === PaymentMethod::VNPAY) {
                try {
                    $url = app(VnPayPaymentService::class)->paymentUrl($order, (int) auth()->id(), $request->ip());

                    return redirect()->away($url);
                } catch (\Throwable) {
                    return redirect()->route('order.show', $order)->withErrors([
                        'payment' => 'Đơn hàng đã được lưu. Chưa thể mở VNPay; bạn có thể thanh toán lại từ chi tiết đơn.',
                    ]);
                }
            }

            return redirect()->route('order.show', $order)->with('success', 'Đặt hàng thành công.');
        } catch (\Throwable $e) {
            $existingOrder = $this->findExistingOrder($validated['checkout_token']);

            if ($existingOrder) {
                return redirect()->route('order.show', $existingOrder);
            }

            if ($this->checkoutTokenBelongsToAnotherUser($validated['checkout_token'])) {
                return back()->withErrors([
                    'checkout' => 'Checkout token không hợp lệ.',
                ])->withInput();
            }

            return back()->withErrors([
                'checkout' => $e->getMessage(),
            ])->withInput();
        }
    }

    public function show(Order $order): View|RedirectResponse
    {
        if (auth()->id() !== $order->user_id) {
            abort(403, 'Bạn không có quyền xem đơn hàng của người khác.');
        }

        $order->load([
            'items.product',
            'shippingMethod',
            'statusHistory',
            'paymentTransactions' => fn ($query) => $query->latest('id'),
            'refunds' => fn ($query) => $query->latest('id'),
        ]);

        $refundEligibility = $this->paymentService->refundEligibility($order);

        return view('storefront.order-detail', [
            'pageTitle' => 'Chi tiết đơn hàng | Silver Atelier',
            'order' => $order,
            'refundEligibility' => $refundEligibility,
            'canPayVnPay' => $this->paymentService->canInitiateVnPay($order),
        ]);
    }

    public function accountOrders(): View
    {
        $orders = Order::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->get();

        return view('storefront.account.orders', [
            'pageTitle' => 'Đơn hàng của tôi | Silver Atelier',
            'orders' => $orders,
        ]);
    }

    protected function lockAndBuildCartItems(Collection $items): Collection
    {
        return $items->map(function ($item) {
            $product = Product::query()
                ->whereKey($item->product_id)
                ->lockForUpdate()
                ->first();

            if (! $product || ! $product->is_active) {
                throw new \RuntimeException('Một sản phẩm trong giỏ hàng không còn khả dụng.');
            }

            $variant = $item->product_variant_id
                ? ProductVariant::query()
                    ->whereKey($item->product_variant_id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($item->product_variant_id && (! $variant || ! $variant->is_active)) {
                throw new \RuntimeException('Biến thể sản phẩm không còn khả dụng.');
            }

            if (! $item->product_variant_id) {
                $activeVariants = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($activeVariants->isNotEmpty()) {
                    throw new \RuntimeException('Sản phẩm '.$product->name.' cần chọn một phiên bản hợp lệ.');
                }
            }

            if (! $item->product_variant_id) {
                $activeVariants = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($activeVariants->isNotEmpty()) {
                    throw new \RuntimeException('Sản phẩm '.$product->name.' cần chọn phiên bản trước khi thanh toán.');
                }
            }

            $quantity = (int) $item->quantity;
            if ($quantity < 1) {
                throw new \RuntimeException('Số lượng sản phẩm không hợp lệ.');
            }

            $inventory = $variant ?: $product;
            $stock = (int) $inventory->stock;

            if ($quantity > $stock) {
                throw new \RuntimeException('Sản phẩm ' . $product->name . ' không đủ số lượng trong kho.');
            }

            // Product/variant rows are locked above; checkout still independently
            // derives its financial price from those current database values.
            $unitPrice = (float) $this->cartService->canonicalPrice($product, $variant);

            return [
                'product' => $product,
                'variant' => $variant,
                'item' => $item,
                'inventory' => $inventory,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
            ];
        });
    }

    protected function decrementLockedStock(Product|ProductVariant $inventory, int $quantity): void
    {
        $updated = $inventory->newQuery()
            ->whereKey($inventory->getKey())
            ->where('stock', '>=', $quantity)
            ->decrement('stock', $quantity);

        if ($updated !== 1) {
            throw new \RuntimeException('Sản phẩm không đủ số lượng trong kho.');
        }
    }

    protected function findExistingOrder(string $checkoutToken): ?Order
    {
        $order = Order::query()
            ->where('checkout_token', $checkoutToken)
            ->where('user_id', auth()->id())
            ->first();

        return $order;
    }

    protected function checkoutTokenBelongsToAnotherUser(string $checkoutToken): bool
    {
        return Order::query()
            ->where('checkout_token', $checkoutToken)
            ->where(function ($query) {
                $query->whereNull('user_id')
                    ->orWhere('user_id', '<>', auth()->id());
            })
            ->exists();
    }

    protected function resolveCoupon(string $couponCode, float $subtotal): array
    {
        $coupon = Coupon::whereRaw('LOWER(code) = ?', [strtolower($couponCode)])->first();

        if (! $coupon) {
            throw new \RuntimeException('Mã giảm giá không tồn tại.');
        }

        if (! $coupon->is_active) {
            throw new \RuntimeException('Mã giảm giá đã bị vô hiệu hóa.');
        }

        $now = now();

        if ($coupon->starts_at && $coupon->starts_at->gt($now)) {
            throw new \RuntimeException('Mã giảm giá chưa bắt đầu hiệu lực.');
        }

        if ($coupon->ends_at && $coupon->ends_at->lt($now)) {
            throw new \RuntimeException('Mã giảm giá đã hết hạn.');
        }

        if ($coupon->minimum_order_amount && $subtotal < (float) $coupon->minimum_order_amount) {
            throw new \RuntimeException('Đơn hàng chưa đạt giá trị tối thiểu để áp dụng mã giảm giá.');
        }

        if ($coupon->usage_limit !== null) {
            $usageCount = CouponUsage::where('coupon_id', $coupon->id)->count();
            if ($usageCount >= (int) $coupon->usage_limit) {
                throw new \RuntimeException('Mã giảm giá đã hết lượt sử dụng.');
            }
        }

        $discount = 0.0;
        $type = strtolower((string) $coupon->type);
        $value = (float) $coupon->value;

        if ($type === 'percent' || $type === 'percentage') {
            $discount = ($subtotal * $value) / 100;
        } else {
            $discount = $value;
        }

        $discount = max(0, min($discount, $subtotal));

        return [$coupon, $discount];
    }

    protected function generateOrderNumber(): string
    {
        do {
            $prefix = 'SA-' . now()->format('Ymd');
            $suffix = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $orderNumber = $prefix . '-' . $suffix;
        } while (Order::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }
}
