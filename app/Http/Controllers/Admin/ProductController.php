<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Material;
use App\Models\OrderItem;
use App\Models\Product;
use App\Rules\SafeContentReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::with(['category', 'material'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = trim($request->string('q')->toString());
                $query->where(function ($builder) use ($term) {
                    $builder->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->latest()
            ->paginate(15)
            ->appends($request->query());

        return view('admin.products.index', [
            'pageTitle' => 'Quản lý sản phẩm',
            'products' => $products,
            'categories' => Category::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.create', array_merge($this->formData(), ['product' => null]));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedProduct($request);

        $product = DB::transaction(function () use ($validated, $request): Product {
            $product = Product::create($this->productAttributes($validated));
            $product->collections()->sync($request->input('collection_ids', []));

            return $product;
        });

        $this->storeFeaturedImage($request, $product);

        return redirect()->route('admin.products.index')->with('success', 'Sản phẩm đã được tạo.');
    }

    public function edit(Product $product): View
    {
        return view('admin.products.edit', array_merge($this->formData(), [
            'product' => $product->load(['collections', 'images', 'specifications']),
        ]));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $this->validatedProduct($request, $product);

        if ($request->hasFile('featured_image_upload')) {
            $validated['featured_image'] = $request->file('featured_image_upload')
                ->store('products/'.$product->id, 'public');
        }

        DB::transaction(function () use ($validated, $request, $product): void {
            $product->update($this->productAttributes($validated));
            $product->collections()->sync($request->input('collection_ids', []));
        });

        return redirect()->route('admin.products.index')->with('success', 'Sản phẩm đã được cập nhật.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        if (OrderItem::where('product_id', $product->id)->exists()) {
            $product->update(['is_active' => false]);

            return redirect()->route('admin.products.index')->with('success', 'Sản phẩm đã được ngừng bán để bảo toàn lịch sử đơn hàng.');
        }

        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Sản phẩm đã được xóa.');
    }

    private function formData(): array
    {
        return [
            'pageTitle' => 'Sản phẩm',
            'categories' => Category::where('is_active', true)->orderBy('name')->get(),
            'materials' => Material::where('is_active', true)->orderBy('name')->get(),
            'collections' => Collection::where('is_active', true)->orderBy('name')->get(),
        ];
    }

    private function validatedProduct(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product?->id)],
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($product?->id)],
            'category_id' => 'nullable|exists:categories,id',
            'material_id' => 'nullable|exists:materials,id',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0|lte:price',
            'featured_image' => ['nullable', 'string', 'max:2048', new SafeContentReference],
            'featured_image_upload' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'stock' => 'required|integer|min:0',
            'featured' => 'nullable|boolean',
            'is_new_arrival' => 'nullable|boolean',
            'is_bestseller' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:4294967295',
            'is_active' => 'nullable|boolean',
            'collection_ids' => 'nullable|array',
            'collection_ids.*' => 'integer|exists:collections,id',
        ]);
    }

    private function productAttributes(array $validated): array
    {
        $validated['featured'] = (bool) ($validated['featured'] ?? false);
        $validated['is_new_arrival'] = (bool) ($validated['is_new_arrival'] ?? false);
        $validated['is_bestseller'] = (bool) ($validated['is_bestseller'] ?? false);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);
        unset($validated['collection_ids'], $validated['featured_image_upload']);

        return $validated;
    }

    private function storeFeaturedImage(Request $request, Product $product): void
    {
        if (! $request->hasFile('featured_image_upload')) {
            return;
        }

        $product->update([
            'featured_image' => $request->file('featured_image_upload')
                ->store('products/'.$product->id, 'public'),
        ]);
    }
}
