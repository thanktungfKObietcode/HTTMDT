<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CartController extends Controller
{
    public function index(): View
    {
        $cart = $this->getOrCreateCart();

        $items = $cart->items()->with('product')->get();

        return view('storefront.cart', [
            'pageTitle' => 'Giỏ hàng | Silver Atelier',
            'cart' => $cart,
            'items' => $items,
            'subtotal' => $items->sum(fn ($item) => $item->quantity * ($item->unit_price ?: ($item->product?->sale_price ?: $item->product?->price ?: 0))),
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'nullable|integer|min:1',
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where(function ($query) use ($request) {
                    $query->where('product_id', $request->input('product_id'))
                        ->where('is_active', true);
                }),
            ],
        ]);

        $product = Product::where('is_active', true)->findOrFail($request->product_id);
        $variant = $request->filled('product_variant_id')
            ? ProductVariant::where('product_id', $product->id)
                ->where('is_active', true)
                ->findOrFail($request->integer('product_variant_id'))
            : null;
        $cart = $this->getOrCreateCart();

        $item = $cart->items()
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->first();
        $unitPrice = (float) ($variant?->sale_price ?: $variant?->price ?: $product->sale_price ?: $product->price);
        $quantity = (int) $request->input('quantity', 1);
        $requestedQuantity = ($item?->quantity ?? 0) + $quantity;
        $stock = (int) ($variant?->stock ?? $product->stock);

        if ($requestedQuantity > $stock) {
            throw ValidationException::withMessages([
                'quantity' => 'Số lượng sản phẩm vượt quá tồn kho hiện tại.',
            ]);
        }

        if ($item) {
            $item->quantity = $requestedQuantity;
            $item->unit_price = $unitPrice;
            $item->save();
        } else {
            $cart->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]);
        }

        return redirect()->route('cart.index')->with('success', 'Sản phẩm đã được thêm vào giỏ hàng.');
    }

    public function update(Request $request, CartItem $item): RedirectResponse
    {
        $cart = $this->getOrCreateCart();
        $item = $cart->items()->findOrFail($item->id);

        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $item->quantity = (int) $request->input('quantity');
        $item->save();

        return redirect()->route('cart.index')->with('success', 'Giỏ hàng đã được cập nhật.');
    }

    public function remove(CartItem $item): RedirectResponse
    {
        $cart = $this->getOrCreateCart();
        $item = $cart->items()->findOrFail($item->id);
        $item->delete();

        return redirect()->route('cart.index')->with('success', 'Sản phẩm đã được xóa khỏi giỏ hàng.');
    }

    protected function getOrCreateCart(): Cart
    {
        $sessionId = session()->getId();
        $cartId = session()->get('cart_id');

        $cart = $cartId
            ? Cart::query()->find($cartId)
            : null;

        if (! $cart) {
            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->first();
        }

        if (! $cart) {
            $cart = Cart::create([
                'session_id' => $sessionId,
            ]);
        }

        session()->put('cart_id', $cart->id);

        if (! $cart->session_id) {
            $cart->session_id = $sessionId;
            $cart->save();
        }

        return $cart;
    }
}
