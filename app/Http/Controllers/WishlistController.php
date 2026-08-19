<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WishlistController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        if (!$user) {
            return view('storefront.wishlist', [
                'pageTitle' => 'Yêu thích | Silver Atelier',
                'items' => [],
                'isAuthenticated' => false,
            ]);
        }

        $items = $user->wishlist()
            ->with('product.category')
            ->orderByDesc('created_at')
            ->get();

        return view('storefront.wishlist', [
            'pageTitle' => 'Yêu thích | Silver Atelier',
            'items' => $items,
            'isAuthenticated' => true,
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $user = auth()->user();

        if (!$user) {
            return redirect()->back()->with('error', 'Vui lòng đăng nhập để thêm sản phẩm vào danh sách yêu thích.');
        }

        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $product = Product::findOrFail($request->product_id);

        $exists = Wishlist::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->exists();

        if (!$exists) {
            Wishlist::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
            ]);
        }

        return redirect()->back()->with('success', 'Sản phẩm đã được thêm vào danh sách yêu thích.');
    }

    public function remove(Product $product): RedirectResponse
    {
        $user = auth()->user();

        if (!$user) {
            return redirect()->route('login');
        }

        Wishlist::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->delete();

        return redirect()->back()->with('success', 'Sản phẩm đã được xóa khỏi danh sách yêu thích.');
    }

    public function check(Product $product)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['in_wishlist' => false]);
        }

        $inWishlist = Wishlist::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->exists();

        return response()->json(['in_wishlist' => $inWishlist]);
    }
}
