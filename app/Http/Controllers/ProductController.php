<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Material;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Support\CategoryTree;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $activeCategories = Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'slug']);
        $fineJewelryRoot = $activeCategories->firstWhere('slug', 'trang-suc-vang');
        $catalogCategoryIds = $fineJewelryRoot
            ? CategoryTree::activeSubtreeIds($activeCategories, $fineJewelryRoot->id)
            : [];
        $catalogCategories = $fineJewelryRoot
            ? $activeCategories->whereIn('id', $catalogCategoryIds)->values()
            : $activeCategories;

        $query = Product::query()
            ->with(['category', 'material', 'collections', 'activeImages', 'variants'])
            ->where('is_active', true);

        if ($fineJewelryRoot) {
            $query->whereIn('category_id', $catalogCategoryIds);
        }

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
            $selectedCategory = $catalogCategories->first(
                fn (Category $category) => $category->slug === $categorySlug || (string) $category->id === $categorySlug
            );

            if ($selectedCategory) {
                $query->whereIn(
                    'category_id',
                    CategoryTree::activeSubtreeIds($catalogCategories, $selectedCategory->id)
                );
            } else {
                $query->whereRaw('1 = 0');
            }
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
                $query->orderBy('sort_order')->latest();
                break;
        }

        $products = $query->paginate(12)->appends($request->query());

        return view('storefront.products', [
            'pageTitle' => 'Sản phẩm | Silver Atelier',
            'products' => $products,
            'categories' => $catalogCategories,
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
        $activeCategories = Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'slug']);
        $fineJewelryRoot = $activeCategories->firstWhere('slug', 'trang-suc-vang');
        $catalogCategoryIds = $fineJewelryRoot
            ? CategoryTree::activeSubtreeIds($activeCategories, $fineJewelryRoot->id)
            : [];

        $product = Product::query()
            ->with([
                'category',
                'material',
                'collections',
                'activeImages',
                'variants',
                'activeSpecifications',
                'reviews.user',
            ])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->when($fineJewelryRoot, fn ($query) => $query->whereIn('category_id', $catalogCategoryIds))
            ->firstOrFail();

        $product->increment('views');

        $relatedProducts = Product::query()
            ->with(['category', 'activeImages', 'variants'])
            ->where('is_active', true)
            ->where('id', '!=', $product->id)
            ->when($product->category_id, function ($builder) use ($product) {
                $builder->where('category_id', $product->category_id);
            })
            ->limit(4)
            ->get();

        $averageRating = $product->reviews()->where('is_active', true)->avg('rating');
        $existingReview = auth()->check()
            ? ProductReview::where('product_id', $product->id)
                ->where('user_id', auth()->id())
                ->first()
            : null;
        $canReview = auth()->check()
            ? Order::query()
                ->where('user_id', auth()->id())
                ->where('status', 'delivered')
                ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
                ->exists()
            : false;

        return view('storefront.product', [
            'pageTitle' => $product->name . ' | Silver Atelier',
            'product' => $product,
            'relatedProducts' => $relatedProducts,
            'averageRating' => $averageRating ? round((float) $averageRating, 1) : ($product->average_rating ?: 0),
            'reviews' => $product->reviews()->with('user')->where('is_active', true)->latest()->get(),
            'specifications' => $product->activeSpecifications()->get(),
            'variants' => $product->variants()->where('is_active', true)->get(),
            'images' => $product->activeImages()->get(),
            'canReview' => $canReview,
            'existingReview' => $existingReview,
            'categoryBreadcrumbs' => CategoryTree::activeLineage($activeCategories, $product->category_id),
        ]);
    }
}
