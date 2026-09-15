<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use App\Rules\SafeContentReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductContentController extends Controller
{
    public function storeImage(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'image_path' => ['nullable', 'string', 'max:2048', 'required_without:image_upload', new SafeContentReference],
            'image_upload' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'alt_text' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0|max:4294967295',
            'is_primary' => 'nullable|boolean',
        ]);

        if ($request->hasFile('image_upload')) {
            $data['image_path'] = $request->file('image_upload')
                ->store('products/'.$product->id.'/gallery', 'public');
        }
        unset($data['image_upload']);

        DB::transaction(function () use ($data, $product): void {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $primary = (bool) ($data['is_primary'] ?? false)
                || ! ProductImage::query()->where('product_id', $product->id)->where('is_active', true)->exists();
            if ($primary) {
                ProductImage::query()->where('product_id', $product->id)->update(['is_primary' => false]);
            }
            $product->images()->create([
                ...$data,
                'is_primary' => $primary,
                'is_active' => true,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);
        });

        return back()->with('success', 'Ảnh sản phẩm đã được thêm.');
    }

    public function updateImage(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        $this->ensureOwner($product, $image->product_id);
        $data = $request->validate([
            'alt_text' => 'nullable|string|max:255',
            'sort_order' => 'required|integer|min:0|max:4294967295',
            'is_primary' => 'nullable|boolean',
            'is_active' => 'required|boolean',
        ]);

        DB::transaction(function () use ($data, $product, $image): void {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedImage = ProductImage::query()->whereKey($image->id)
                ->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            $wasPrimary = $lockedImage->is_primary;
            $active = (bool) $data['is_active'];
            $primary = $active && ((bool) ($data['is_primary'] ?? false)
                || ! ProductImage::query()->where('product_id', $product->id)
                    ->where('id', '!=', $image->id)->where('is_active', true)->exists());
            if ($primary) {
                ProductImage::query()->where('product_id', $product->id)->where('id', '!=', $image->id)->update(['is_primary' => false]);
            }
            $lockedImage->update([...$data, 'is_primary' => $primary, 'is_active' => $active]);
            if ($wasPrimary && ! $primary) {
                $this->promoteFirstImage($product->id);
            }
        });

        return back()->with('success', 'Ảnh sản phẩm đã được cập nhật.');
    }

    public function destroyImage(Product $product, ProductImage $image): RedirectResponse
    {
        $this->ensureOwner($product, $image->product_id);
        abort_unless($image->is_active, 404);

        DB::transaction(function () use ($product, $image): void {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedImage = ProductImage::query()->whereKey($image->id)
                ->where('product_id', $product->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $wasPrimary = $lockedImage->is_primary;
            $lockedImage->update(['is_active' => false, 'is_primary' => false]);
            if ($wasPrimary) {
                $this->promoteFirstImage($product->id);
            }
        });

        return back()->with('success', 'Ảnh sản phẩm đã được ngừng hiển thị.');
    }

    public function storeSpecification(Request $request, Product $product): RedirectResponse
    {
        $data = $this->specificationData($request);
        $product->specifications()->create($data);

        return back()->with('success', 'Thông số đã được thêm.');
    }

    public function updateSpecification(Request $request, Product $product, ProductSpecification $specification): RedirectResponse
    {
        $this->ensureOwner($product, $specification->product_id);
        $specification->update($this->specificationData($request, $specification->is_active));

        return back()->with('success', 'Thông số đã được cập nhật.');
    }

    public function destroySpecification(Product $product, ProductSpecification $specification): RedirectResponse
    {
        $this->ensureOwner($product, $specification->product_id);
        abort_unless($specification->is_active, 404);
        $specification->update(['is_active' => false]);

        return back()->with('success', 'Thông số đã được ngừng hiển thị.');
    }

    private function specificationData(Request $request, bool $defaultActive = true): array
    {
        $data = $request->validate([
            'label' => 'required|string|max:255',
            'value' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0|max:4294967295',
            'is_active' => 'nullable|boolean',
        ]);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = (bool) ($data['is_active'] ?? $defaultActive);

        return $data;
    }

    private function ensureOwner(Product $product, int $productId): void
    {
        abort_unless((int) $product->id === $productId, 404);
    }

    private function promoteFirstImage(int $productId): void
    {
        ProductImage::query()->where('product_id', $productId)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->first()?->update(['is_primary' => true]);
    }
}
