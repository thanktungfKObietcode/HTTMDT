<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PaymentTransaction;
use App\Models\ShippingMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function index(): View|RedirectResponse
    {
        $cart = $this->currentCart();

        if ($cart->items()->count() === 0) {
            return redirect()->route('cart.index')->with('error', 'Giỏ hàng của bạn đang trống.');
        }

        $items = $cart->items()->with(['product', 'productVariant'])->get();
        $subtotal = $items->sum(fn ($item) => (int) $item->quantity * (float) ($item->unit_price ?: ($item->product?->sale_price ?: $item->product?->price ?: 0)));

        return view('storefront.checkout', [
            'pageTitle' => 'Thanh toán | Silver Atelier',
            'cart' => $cart,
            'items' => $items,
            'subtotal' => $subtotal,
            'shippingMethods' => ShippingMethod::where('is_active', true)->get(),
            'savedAddresses' => auth()->user()?->addresses()->where('is_active', true)->get() ?? collect(),
            'defaultAddress' => auth()->user()?->addresses()->where('is_default', true)->where('is_active', true)->first(),
            'user' => auth()->user(),
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
            'payment_method' => 'required|string|max:50',
            'coupon_code' => 'nullable|string|max:100',
        ]);

        $cart = $this->currentCart();

        if ($cart->items()->count() === 0) {
            return redirect()->route('cart.index')->with('error', 'Giỏ hàng của bạn đang trống.');
        }

        $shippingMethod = ShippingMethod::where('is_active', true)->findOrFail($validated['shipping_method_id']);
        $items = $cart->items()->with(['product', 'productVariant'])->get();

        DB::beginTransaction();

        try {
            $validatedItems = $this->validateAndBuildCartItems($items);
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

                if ($variant) {
                    $variant->decrement('stock', $entry['quantity']);
                } else {
                    $product->decrement('stock', $entry['quantity']);
                }
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

            PaymentTransaction::create([
                'order_id' => $order->id,
                'gateway' => $validated['payment_method'],
                'transaction_id' => 'TXN-' . $order->id . '-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
                'payment_status' => 'pending',
                'amount' => $totalAmount,
                'payload' => [
                    'created_from' => 'checkout',
                ],
            ]);

            $cart->items()->delete();

            DB::commit();

            return redirect()->route('order.show', $order)->with('success', 'Đặt hàng thành công.');
        } catch (\Throwable $e) {
            DB::rollBack();

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

        return view('storefront.order-detail', [
            'pageTitle' => 'Chi tiết đơn hàng | Silver Atelier',
            'order' => $order,
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

    protected function currentCart(): Cart
    {
        $sessionId = session()->getId();
        $cartId = session()->get('cart_id');

        $cart = null;

        if (auth()->check()) {
            $cart = Cart::where('user_id', auth()->id())->latest('id')->first();
        }

        if (! $cart && $cartId) {
            $cart = Cart::find($cartId);
        }

        if (! $cart) {
            $cart = Cart::where('session_id', $sessionId)->first();
        }

        if (! $cart) {
            $cart = Cart::create([
                'session_id' => $sessionId,
                'user_id' => auth()->id(),
            ]);
        } elseif (auth()->check() && ! $cart->user_id) {
            $cart->user_id = auth()->id();
            $cart->save();
        }

        session()->put('cart_id', $cart->id);

        if (! $cart->session_id) {
            $cart->session_id = $sessionId;
            $cart->save();
        }

        return $cart;
    }

    protected function validateAndBuildCartItems(Collection $items): Collection
    {
        return $items->map(function ($item) {
            $product = $item->product;

            if (! $product || ! $product->is_active) {
                throw new \RuntimeException('Một sản phẩm trong giỏ hàng không còn khả dụng.');
            }

            $variant = $item->productVariant;

            if ($variant && ! $variant->is_active) {
                throw new \RuntimeException('Biến thể sản phẩm không còn khả dụng.');
            }

            $quantity = (int) $item->quantity;
            $stock = (int) ($variant?->stock ?? $product->stock ?? 0);

            if ($quantity > $stock) {
                throw new \RuntimeException('Sản phẩm ' . $product->name . ' không đủ số lượng trong kho.');
            }

            $unitPrice = (float) ($variant?->sale_price ?: $variant?->price ?: $product->sale_price ?: $product->price ?: 0);

            return [
                'product' => $product,
                'variant' => $variant,
                'item' => $item,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
            ];
        });
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
