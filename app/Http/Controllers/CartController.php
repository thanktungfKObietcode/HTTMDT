<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Services\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    public function index(Request $request): View
    {
        $cart = $this->cartService->currentCart($request);
        $summary = $this->cartService->summarize($cart);

        return view('storefront.cart', [
            'pageTitle' => 'Giỏ hàng | Silver Atelier',
            'cart' => $cart,
            'entries' => $summary['entries'],
            'subtotal' => $summary['subtotal'],
            'subtotalDisplay' => $summary['subtotal_display'],
            'canCheckout' => $summary['can_checkout'],
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'quantity' => 'nullable|integer|min:1',
            'product_variant_id' => 'nullable|integer',
        ]);

        $cart = $this->cartService->currentCart($request);
        $this->cartService->addItem(
            $cart,
            $request->user(),
            $this->cartService->ownershipSessionId($request),
            (int) $validated['product_id'],
            isset($validated['product_variant_id']) ? (int) $validated['product_variant_id'] : null,
            (int) ($validated['quantity'] ?? 1)
        );

        return redirect()->route('cart.index')->with('success', 'Sản phẩm đã được thêm vào giỏ hàng.');
    }

    public function update(Request $request, CartItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = $this->cartService->currentCart($request);
        $this->cartService->updateItemQuantity(
            $cart,
            $item,
            $request->user(),
            $this->cartService->ownershipSessionId($request),
            (int) $validated['quantity']
        );

        return redirect()->route('cart.index')->with('success', 'Giỏ hàng đã được cập nhật.');
    }

    public function remove(Request $request, CartItem $item): RedirectResponse
    {
        $cart = $this->cartService->currentCart($request);
        $this->cartService->removeItem(
            $cart,
            $item,
            $request->user(),
            $this->cartService->ownershipSessionId($request)
        );

        return redirect()->route('cart.index')->with('success', 'Sản phẩm đã được xóa khỏi giỏ hàng.');
    }
}
