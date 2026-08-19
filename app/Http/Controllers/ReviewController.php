<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $hasPurchased = Order::query()
            ->where('user_id', auth()->id())
            ->where('status', 'delivered')
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
            ->exists();

        if (! $hasPurchased) {
            return back()->withErrors([
                'review' => 'Bạn chỉ có thể đánh giá sản phẩm sau khi đã nhận hàng.',
            ])->withInput();
        }

        if (ProductReview::where('product_id', $product->id)->where('user_id', auth()->id())->exists()) {
            return back()->withErrors([
                'review' => 'Bạn đã đánh giá sản phẩm này rồi.',
            ]);
        }

        ProductReview::create([
            'product_id' => $product->id,
            'user_id' => auth()->id(),
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
            'is_verified_purchase' => true,
            'is_active' => true,
        ]);

        return redirect()
            ->route('product.show', $product->slug)
            ->with('success', 'Đánh giá của bạn đã được gửi.');
    }
}