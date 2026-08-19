<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductVariantController extends Controller
{
    public function index(Product $product): View
    {
        return view('admin.products.variants.index', [
            'pageTitle' => 'Biến thể sản phẩm',
            'product' => $product,
            'variants' => ProductVariant::where('product_id', $product->id)->latest()->get(),
        ]);
    }

    public function create(Product $product): View
    {
        return view('admin.products.variants.create', [
            'pageTitle' => 'Thêm biến thể',
            'product' => $product,
            'variant' => null,
        ]);
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        ProductVariant::create(array_merge($this->validated($request), [
            'product_id' => $product->id,
        ]));

        return redirect()->route('admin.products.variants.index', $product)->with('success', 'Biến thể đã được tạo.');
    }

    public function edit(Product $product, ProductVariant $variant): View
    {
        $this->ensureBelongsToProduct($product, $variant);

        return view('admin.products.variants.edit', [
            'pageTitle' => 'Chỉnh sửa biến thể',
            'product' => $product,
            'variant' => $variant,
        ]);
    }

    public function update(Request $request, Product $product, ProductVariant $variant): RedirectResponse
    {
        $this->ensureBelongsToProduct($product, $variant);
        $variant->update($this->validated($request, $variant));

        return redirect()->route('admin.products.variants.index', $product)->with('success', 'Biến thể đã được cập nhật.');
    }

    public function destroy(Product $product, ProductVariant $variant): RedirectResponse
    {
        $this->ensureBelongsToProduct($product, $variant);

        if (OrderItem::where('product_variant_id', $variant->id)->exists() || CartItem::where('product_variant_id', $variant->id)->exists()) {
            $variant->update(['is_active' => false]);

            return back()->with('success', 'Biến thể đã được ngừng sử dụng để bảo toàn dữ liệu tham chiếu.');
        }

        $variant->delete();

        return back()->with('success', 'Biến thể đã được xóa.');
    }

    private function validated(Request $request, ?ProductVariant $variant = null): array
    {
        return $request->validate([
            'sku' => ['required', 'string', 'max:255', Rule::unique('product_variants', 'sku')->ignore($variant?->id)],
            'size' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:255',
            'metal_type' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric|min:0',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0|lte:price',
            'stock' => 'required|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function ensureBelongsToProduct(Product $product, ProductVariant $variant): void
    {
        abort_unless((int) $variant->product_id === (int) $product->id, 404);
    }
}