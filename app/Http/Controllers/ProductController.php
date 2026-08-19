<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Material;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query()
            ->with(['category', 'material', 'collections'])
            ->where('is_active', true);

        if ($request->filled('q')) {
            $search = trim($request->input('q'));
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $categorySlug = trim($request->input('category'));
            $query->whereHas('category', function ($builder) use ($categorySlug) {
                $builder->where('slug', $categorySlug)
                    ->orWhere('id', $categorySlug);
            });
        }

        if ($request->filled('material')) {
            $materialSlug = trim($request->input('material'));
            $query->whereHas('material', function ($builder) use ($materialSlug) {
                $builder->where('slug', $materialSlug)
                    ->orWhere('code', $materialSlug)
                    ->orWhere('id', $materialSlug);
            });
        }

        if ($request->filled('collection')) {
            $collectionSlug = trim($request->input('collection'));
            $query->whereHas('collections', function ($builder) use ($collectionSlug) {
                $builder->where('slug', $collectionSlug)
                    ->orWhere('id', $collectionSlug);
            });
        }

        $sort = $request->input('sort', 'newest');

        switch ($sort) {
            case 'price_asc':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            case 'name_asc':
                $query->orderBy('name', 'asc');
                break;
            default:
                $query->latest();
                break;
        }

        $products = $query->paginate(12)->appends($request->query());

        return view('storefront.products', [
            'pageTitle' => 'Sản phẩm | Silver Atelier',
            'products' => $products,
            'categories' => Category::where('is_active', true)->get(),
            'materials' => Material::where('is_active', true)->get(),
            'collections' => Collection::where('is_active', true)->get(),
            'selectedCategory' => $request->input('category'),
            'selectedMaterial' => $request->input('material'),
            'selectedCollection' => $request->input('collection'),
            'sort' => $sort,
            'search' => $request->input('q', ''),
        ]);
    }

    public function show(string $slug)
    {
        $product = Product::query()
            ->with([
                'category',
                'material',
                'collections',
                'images',
                'variants',
                'specifications',
                'reviews.user',
            ])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $product->increment('views');

        $relatedProducts = Product::query()
            ->with('category')
            ->where('is_active', true)
            ->where('id', '!=', $product->id)
            ->when($product->category_id, function ($builder) use ($product) {
                $builder->where('category_id', $product->category_id);
            })
            ->limit(4)
            ->get();

        $averageRating = $product->reviews()->where('is_active', true)->avg('rating');

        return view('storefront.product', [
            'pageTitle' => $product->name . ' | Silver Atelier',
            'product' => $product,
            'relatedProducts' => $relatedProducts,
            'averageRating' => $averageRating ? round((float) $averageRating, 1) : ($product->average_rating ?: 0),
            'reviews' => $product->reviews()->with('user')->where('is_active', true)->latest()->get(),
            'specifications' => $product->specifications()->get(),
            'variants' => $product->variants()->where('is_active', true)->get(),
            'images' => $product->images()->orderBy('sort_order')->get(),
        ]);
    }
}
