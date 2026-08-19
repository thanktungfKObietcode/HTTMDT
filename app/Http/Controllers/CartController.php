<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);

        $product = Product::findOrFail($request->product_id);
        $cart = $this->getOrCreateCart();

        $item = $cart->items()->where('product_id', $product->id)->first();

        if ($item) {
            $item->quantity += (int) $request->input('quantity', 1);
            $item->unit_price = $product->sale_price ?: $product->price;
            $item->save();
        } else {
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => (int) $request->input('quantity', 1),
                'unit_price' => $product->sale_price ?: $product->price,
            ]);
        }

        return redirect()->route('cart.index')->with('success', 'Sản phẩm đã được thêm vào giỏ hàng.');
    }

    public function update(Request $request, CartItem $item): RedirectResponse
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $item->quantity = (int) $request->input('quantity');
        $item->save();

        return redirect()->route('cart.index')->with('success', 'Giỏ hàng đã được cập nhật.');
    }

    public function remove(CartItem $item): RedirectResponse
    {
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
