<?php

use App\Http\Controllers\ProductController;
use App\Models\Category;
use App\Models\Banner;
use App\Models\BlogPost;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Collection;
use App\Support\CategoryTree;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $activeCategories = Category::query()
        ->where('is_active', true)
        ->orderBy('name')
        ->get(['id', 'parent_id', 'name', 'slug', 'image']);

    $newsPosts = BlogPost::with('category')
        ->where('is_published', true)
        ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('blog_category_id')->orWhereHas('category', fn ($category) => $category->where('is_active', true)))
        ->orderByDesc('published_at')
        ->orderByDesc('id')
        ->limit(9)
        ->get();

    $banners = Banner::where('is_active', true)
        ->where('position', 'home')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->get();

    $storyCampaign = Banner::where('is_active', true)
        ->where('position', 'home_story')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->first();

    $engagementVideo = Banner::where('is_active', true)
        ->where('position', 'home_engagement_video')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->first();

    $newArrivalsCampaign = Banner::where('is_active', true)
        ->where('position', 'home_new_arrivals_campaign')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->first();

    $editorialGallery = Banner::where('is_active', true)
        ->where('position', 'home_editorial_gallery')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get();

    $highJewelryVideo = Banner::where('is_active', true)
        ->where('position', 'home_high_jewelry_video')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->first();

    $highJewelryImages = Banner::where('is_active', true)
        ->where('position', 'home_high_jewelry_image')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get();

    $highJewelryCampaign = Banner::where('is_active', true)
        ->where('position', 'home_high_jewelry_campaign')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->orderBy('id')
        ->first();

    $highJewelryEditorial = Banner::where('is_active', true)
        ->where('position', 'home_high_jewelry_editorial')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->orderBy('id')
        ->first();

    $highJewelryCategory = $activeCategories->firstWhere('slug', 'trang-suc-kim-cuong');
    $highJewelryProducts = $highJewelryCategory
        ? Product::query()
            ->with(['category', 'activeImages', 'variants'])
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereIn('category_id', CategoryTree::activeSubtreeIds($activeCategories, $highJewelryCategory->id))
            ->orderByDesc('featured')
            ->orderByDesc('is_bestseller')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(4)
            ->get()
        : collect();

    $czEditorial = Banner::where('is_active', true)
        ->where('position', 'home_cz_editorial')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->orderBy('id')
        ->first();

    $czCategory = $activeCategories->firstWhere('slug', 'trang-suc-cz');
    $czProducts = $czCategory
        ? Product::query()
            ->with(['category', 'activeImages', 'variants'])
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereIn('category_id', CategoryTree::activeSubtreeIds($activeCategories, $czCategory->id))
            ->orderByDesc('featured')
            ->orderByDesc('is_bestseller')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(4)
            ->get()
        : collect();

    $coloredGemstoneEditorial = Banner::where('is_active', true)
        ->where('position', 'home_colored_gemstone_editorial')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->orderBy('id')
        ->first();

    $coloredGemstoneCategory = $activeCategories->firstWhere('slug', 'trang-suc-da-mau');
    $coloredGemstoneProducts = $coloredGemstoneCategory
        ? Product::query()
            ->with(['category', 'activeImages', 'variants'])
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereIn('category_id', CategoryTree::activeSubtreeIds($activeCategories, $coloredGemstoneCategory->id))
            ->orderByDesc('featured')
            ->orderByDesc('is_bestseller')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(4)
            ->get()
        : collect();

    $pearlEditorial = Banner::where('is_active', true)
        ->where('position', 'home_pearl_editorial')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->orderBy('id')
        ->first();

    $pearlCategory = $activeCategories->firstWhere('slug', 'trang-suc-ngoc-trai');
    $pearlProducts = $pearlCategory
        ? Product::query()
            ->with(['category', 'activeImages', 'variants'])
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereIn('category_id', CategoryTree::activeSubtreeIds($activeCategories, $pearlCategory->id))
            ->orderByDesc('featured')
            ->orderByDesc('is_bestseller')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(4)
            ->get()
        : collect();

    $weddingEditorial = Banner::where('is_active', true)
        ->where('position', 'home_wedding_editorial')
        ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->orderBy('sort_order')
        ->orderBy('id')
        ->first();

    $weddingCollection = Collection::query()
        ->where('slug', 'trang-suc-cuoi')
        ->where('is_active', true)
        ->first();
    $weddingCarouselProducts = $weddingCollection
        ? $weddingCollection->products()
            ->with(['category', 'activeImages', 'variants'])
            ->where('products.is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->orderByDesc('featured')
            ->orderByDesc('is_bestseller')
            ->orderBy('sort_order')
            ->orderBy('products.id')
            ->limit(8)
            ->get()
        : collect();

    $testimonials = ProductReview::query()
        ->with([
            'user:id,name,avatar',
            'product:id,is_active',
        ])
        ->where('is_active', true)
        ->where('rating', '>=', 4)
        ->whereNotNull('comment')
        ->whereRaw("TRIM(comment) <> ''")
        ->whereHas('user')
        ->whereHas('product', fn ($query) => $query->where('is_active', true))
        ->latest()
        ->limit(3)
        ->get();

    $newArrivalProducts = Product::query()
        ->with(['category', 'activeImages', 'variants'])
        ->where('is_active', true)
        ->where('is_new_arrival', true)
        ->whereHas('category', fn ($query) => $query->where('is_active', true))
        ->orderBy('sort_order')
        ->latest()
        ->limit(8)
        ->get();

    $engagementRingCategoryIds = Category::query()
        ->where('is_active', true)
        ->where('slug', 'nhan-kim-cuong')
        ->pluck('id');

    $engagementProducts = Product::query()
        ->with(['category', 'activeImages', 'variants'])
        ->where('is_active', true)
        ->whereHas('category', fn ($query) => $query->where('is_active', true))
        ->whereIn('category_id', $engagementRingCategoryIds)
        ->orderByDesc('featured')
        ->orderByDesc('is_bestseller')
        ->orderBy('sort_order')
        ->limit(4)
        ->get();

    if ($engagementProducts->count() < 4) {
        $diamondRootId = Category::query()
            ->where('is_active', true)
            ->where('slug', 'trang-suc-kim-cuong')
            ->value('id');

        $diamondCategoryIds = Category::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('slug', 'trang-suc-kim-cuong')
                ->when($diamondRootId !== null, fn ($query) => $query->orWhere('parent_id', $diamondRootId)))
            ->pluck('id');

        $engagementProducts = $engagementProducts->concat(
            Product::query()
                ->with(['category', 'activeImages', 'variants'])
                ->where('is_active', true)
                ->whereHas('category', fn ($query) => $query->where('is_active', true))
                ->whereIn('category_id', $diamondCategoryIds)
                ->where('name', 'like', '%Nhẫn%')
                ->whereNotIn('id', $engagementProducts->pluck('id'))
                ->orderByDesc('featured')
                ->orderByDesc('is_bestseller')
                ->orderBy('sort_order')
                ->limit(4 - $engagementProducts->count())
                ->get()
        )->values();
    }

    return view('storefront.home', [
        'pageTitle' => 'Silver Atelier | Fine Jewelry',
        'newsPosts' => $newsPosts,
        'banners' => $banners,
        'storyCampaign' => $storyCampaign,
        'engagementVideo' => $engagementVideo,
        'engagementProducts' => $engagementProducts,
        'newArrivalsCampaign' => $newArrivalsCampaign,
        'newArrivalProducts' => $newArrivalProducts,
        'editorialGallery' => $editorialGallery,
        'highJewelryVideo' => $highJewelryVideo,
        'highJewelryImages' => $highJewelryImages,
        'highJewelryCampaign' => $highJewelryCampaign,
        'highJewelryEditorial' => $highJewelryEditorial,
        'highJewelryProducts' => $highJewelryProducts,
        'czEditorial' => $czEditorial,
        'czProducts' => $czProducts,
        'coloredGemstoneEditorial' => $coloredGemstoneEditorial,
        'coloredGemstoneProducts' => $coloredGemstoneProducts,
        'pearlEditorial' => $pearlEditorial,
        'pearlProducts' => $pearlProducts,
        'weddingEditorial' => $weddingEditorial,
        'weddingCollection' => $weddingCollection,
        'weddingCarouselProducts' => $weddingCarouselProducts,
        'testimonials' => $testimonials,
    ]);
})->name('home');

    Route::get('/tin-tuc', [\App\Http\Controllers\StorefrontContentController::class, 'blogIndex'])->name('blog.index');
    Route::get('/tin-tuc/{slug}', [\App\Http\Controllers\StorefrontContentController::class, 'blogShow'])->name('blog.show');
    Route::get('/lien-he', [\App\Http\Controllers\StorefrontContentController::class, 'contact'])->name('contact');
    Route::post('/lien-he', [\App\Http\Controllers\StorefrontContentController::class, 'submitContact'])->name('contact.store');
    Route::post('/dang-ky-nhan-tin', [\App\Http\Controllers\StorefrontContentController::class, 'subscribe'])->name('newsletter.subscribe');
    Route::get('/showroom', [\App\Http\Controllers\StorefrontContentController::class, 'showrooms'])->name('showrooms');
    Route::get('/chinh-sach/{policy}', [\App\Http\Controllers\StorefrontContentController::class, 'policy'])->name('policy.show');

    Route::prefix('admin')->name('admin.')->middleware(['auth', 'active', 'backoffice'])->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->middleware('permission:dashboard.view')->name('dashboard');
        Route::get('/dashboard', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->middleware('permission:dashboard.view')->name('dashboard.page');
        Route::get('/orders', [\App\Http\Controllers\Admin\OrderController::class, 'index'])->middleware('permission:orders.view')->name('orders.index');
        Route::get('/orders/{order}', [\App\Http\Controllers\Admin\OrderController::class, 'show'])->middleware('permission:orders.view')->name('orders.show');
        Route::post('/orders/{order}/status', [\App\Http\Controllers\Admin\OrderController::class, 'updateStatus'])->middleware('permission:orders.update')->name('orders.status');
        Route::post('/orders/{order}/payments/{transaction}/reconcile', \App\Http\Controllers\Admin\VnPayReconciliationController::class)
            ->middleware(['admin', 'permission:orders.update', 'throttle:payment-reconciliation'])->name('orders.payments.reconcile');
        Route::post('/orders/{order}/payments/{transaction}/momo/reconcile', \App\Http\Controllers\Admin\MoMoReconciliationController::class)
            ->middleware(['admin', 'permission:orders.update', 'throttle:payment-reconciliation'])->name('orders.payments.momo.reconcile');
        Route::post('/refunds/{refund}/approve', [\App\Http\Controllers\Admin\OrderController::class, 'approveRefund'])->middleware('permission:orders.refund')->name('refunds.approve');
        Route::post('/refunds/{refund}/reject', [\App\Http\Controllers\Admin\OrderController::class, 'rejectRefund'])->middleware('permission:orders.refund')->name('refunds.reject');
        Route::post('/refunds/{refund}/execute', [\App\Http\Controllers\Admin\OrderController::class, 'executeRefund'])->middleware('permission:orders.refund')->name('refunds.execute');
        Route::get('/users', [\App\Http\Controllers\Admin\UserController::class, 'index'])->middleware('permission:customers.view,staff.manage')->name('users.index');
        Route::get('/users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'show'])->middleware('permission:customers.view,staff.manage')->name('users.show');
        Route::put('/users/{user}/roles', [\App\Http\Controllers\Admin\UserController::class, 'updateRoles'])->middleware('permission:staff.manage')->name('users.roles.update');
        Route::post('/users/{user}/deactivate', [\App\Http\Controllers\Admin\UserController::class, 'deactivate'])->middleware('permission:customers.update,staff.manage')->name('users.deactivate');
        Route::post('/users/{user}/activate', [\App\Http\Controllers\Admin\UserController::class, 'activate'])->middleware('permission:customers.update,staff.manage')->name('users.activate');
        Route::get('/products', [\App\Http\Controllers\Admin\ProductController::class, 'index'])->middleware('permission:products.view')->name('products.index');
        Route::get('/products/create', [\App\Http\Controllers\Admin\ProductController::class, 'create'])->middleware('permission:products.create')->name('products.create');
        Route::post('/products', [\App\Http\Controllers\Admin\ProductController::class, 'store'])->middleware('permission:products.create')->name('products.store');
        Route::get('/products/{product}/edit', [\App\Http\Controllers\Admin\ProductController::class, 'edit'])->middleware('permission:products.update')->name('products.edit');
        Route::put('/products/{product}', [\App\Http\Controllers\Admin\ProductController::class, 'update'])->middleware('permission:products.update')->name('products.update');
        Route::delete('/products/{product}', [\App\Http\Controllers\Admin\ProductController::class, 'destroy'])->middleware('permission:products.delete')->name('products.destroy');
        Route::post('/products/{product}/images', [\App\Http\Controllers\Admin\ProductContentController::class, 'storeImage'])->middleware('permission:products.update')->name('products.images.store');
        Route::put('/products/{product}/images/{image}', [\App\Http\Controllers\Admin\ProductContentController::class, 'updateImage'])->middleware('permission:products.update')->name('products.images.update');
        Route::delete('/products/{product}/images/{image}', [\App\Http\Controllers\Admin\ProductContentController::class, 'destroyImage'])->middleware('permission:products.update')->name('products.images.destroy');
        Route::post('/products/{product}/specifications', [\App\Http\Controllers\Admin\ProductContentController::class, 'storeSpecification'])->middleware('permission:products.update')->name('products.specifications.store');
        Route::put('/products/{product}/specifications/{specification}', [\App\Http\Controllers\Admin\ProductContentController::class, 'updateSpecification'])->middleware('permission:products.update')->name('products.specifications.update');
        Route::delete('/products/{product}/specifications/{specification}', [\App\Http\Controllers\Admin\ProductContentController::class, 'destroySpecification'])->middleware('permission:products.update')->name('products.specifications.destroy');
        Route::get('/products/{product}/variants', [\App\Http\Controllers\Admin\ProductVariantController::class, 'index'])->middleware('permission:products.view')->name('products.variants.index');
        Route::get('/products/{product}/variants/create', [\App\Http\Controllers\Admin\ProductVariantController::class, 'create'])->middleware('permission:products.create')->name('products.variants.create');
        Route::post('/products/{product}/variants', [\App\Http\Controllers\Admin\ProductVariantController::class, 'store'])->middleware('permission:products.create')->name('products.variants.store');
        Route::get('/products/{product}/variants/{variant}/edit', [\App\Http\Controllers\Admin\ProductVariantController::class, 'edit'])->middleware('permission:products.update')->name('products.variants.edit');
        Route::put('/products/{product}/variants/{variant}', [\App\Http\Controllers\Admin\ProductVariantController::class, 'update'])->middleware('permission:products.update')->name('products.variants.update');
        Route::delete('/products/{product}/variants/{variant}', [\App\Http\Controllers\Admin\ProductVariantController::class, 'destroy'])->middleware('permission:products.delete')->name('products.variants.destroy');
        Route::get('/categories', [\App\Http\Controllers\Admin\CategoryController::class, 'index'])->middleware('permission:products.view')->name('categories.index');
        Route::get('/categories/create', [\App\Http\Controllers\Admin\CategoryController::class, 'create'])->middleware('permission:products.create')->name('categories.create');
        Route::post('/categories', [\App\Http\Controllers\Admin\CategoryController::class, 'store'])->middleware('permission:products.create')->name('categories.store');
        Route::get('/categories/{category}/edit', [\App\Http\Controllers\Admin\CategoryController::class, 'edit'])->middleware('permission:products.update')->name('categories.edit');
        Route::put('/categories/{category}', [\App\Http\Controllers\Admin\CategoryController::class, 'update'])->middleware('permission:products.update')->name('categories.update');
        Route::delete('/categories/{category}', [\App\Http\Controllers\Admin\CategoryController::class, 'destroy'])->middleware('permission:products.delete')->name('categories.destroy');
        Route::get('/collections', [\App\Http\Controllers\Admin\CollectionController::class, 'index'])->middleware('permission:products.view')->name('collections.index');
        Route::get('/collections/create', [\App\Http\Controllers\Admin\CollectionController::class, 'create'])->middleware('permission:products.create')->name('collections.create');
        Route::post('/collections', [\App\Http\Controllers\Admin\CollectionController::class, 'store'])->middleware('permission:products.create')->name('collections.store');
        Route::get('/collections/{collection}/edit', [\App\Http\Controllers\Admin\CollectionController::class, 'edit'])->middleware('permission:products.update')->name('collections.edit');
        Route::put('/collections/{collection}', [\App\Http\Controllers\Admin\CollectionController::class, 'update'])->middleware('permission:products.update')->name('collections.update');
        Route::delete('/collections/{collection}', [\App\Http\Controllers\Admin\CollectionController::class, 'destroy'])->middleware('permission:products.delete')->name('collections.destroy');
        Route::get('/materials', [\App\Http\Controllers\Admin\MaterialController::class, 'index'])->middleware('permission:products.view')->name('materials.index');
        Route::get('/materials/create', [\App\Http\Controllers\Admin\MaterialController::class, 'create'])->middleware('permission:products.create')->name('materials.create');
        Route::post('/materials', [\App\Http\Controllers\Admin\MaterialController::class, 'store'])->middleware('permission:products.create')->name('materials.store');
        Route::get('/materials/{material}/edit', [\App\Http\Controllers\Admin\MaterialController::class, 'edit'])->middleware('permission:products.update')->name('materials.edit');
        Route::put('/materials/{material}', [\App\Http\Controllers\Admin\MaterialController::class, 'update'])->middleware('permission:products.update')->name('materials.update');
        Route::delete('/materials/{material}', [\App\Http\Controllers\Admin\MaterialController::class, 'destroy'])->middleware('permission:products.delete')->name('materials.destroy');
        Route::get('/coupons', [\App\Http\Controllers\Admin\CouponController::class, 'index'])->middleware('permission:coupons.manage')->name('coupons.index');
        Route::get('/coupons/create', [\App\Http\Controllers\Admin\CouponController::class, 'create'])->middleware('permission:coupons.manage')->name('coupons.create');
        Route::post('/coupons', [\App\Http\Controllers\Admin\CouponController::class, 'store'])->middleware('permission:coupons.manage')->name('coupons.store');
        Route::get('/coupons/{coupon}/edit', [\App\Http\Controllers\Admin\CouponController::class, 'edit'])->middleware('permission:coupons.manage')->name('coupons.edit');
        Route::put('/coupons/{coupon}', [\App\Http\Controllers\Admin\CouponController::class, 'update'])->middleware('permission:coupons.manage')->name('coupons.update');
        Route::delete('/coupons/{coupon}', [\App\Http\Controllers\Admin\CouponController::class, 'destroy'])->middleware('permission:coupons.manage')->name('coupons.destroy');
        Route::post('/coupons/{coupon}/activate', [\App\Http\Controllers\Admin\CouponController::class, 'activate'])->middleware('permission:coupons.manage')->name('coupons.activate');
        Route::post('/coupons/{coupon}/deactivate', [\App\Http\Controllers\Admin\CouponController::class, 'deactivate'])->middleware('permission:coupons.manage')->name('coupons.deactivate');
        Route::get('/shipping', [\App\Http\Controllers\Admin\ShippingMethodController::class, 'index'])->middleware('permission:shipping.manage')->name('shipping.index');
        Route::get('/shipping/create', [\App\Http\Controllers\Admin\ShippingMethodController::class, 'create'])->middleware('permission:shipping.manage')->name('shipping.create');
        Route::post('/shipping', [\App\Http\Controllers\Admin\ShippingMethodController::class, 'store'])->middleware('permission:shipping.manage')->name('shipping.store');
        Route::get('/shipping/{shipping}/edit', [\App\Http\Controllers\Admin\ShippingMethodController::class, 'edit'])->middleware('permission:shipping.manage')->name('shipping.edit');
        Route::put('/shipping/{shipping}', [\App\Http\Controllers\Admin\ShippingMethodController::class, 'update'])->middleware('permission:shipping.manage')->name('shipping.update');
        Route::delete('/shipping/{shipping}', [\App\Http\Controllers\Admin\ShippingMethodController::class, 'destroy'])->middleware('permission:shipping.manage')->name('shipping.destroy');
        Route::post('/shipping/{shipping}/activate', [\App\Http\Controllers\Admin\ShippingMethodController::class, 'activate'])->middleware('permission:shipping.manage')->name('shipping.activate');
        Route::post('/shipping/{shipping}/deactivate', [\App\Http\Controllers\Admin\ShippingMethodController::class, 'deactivate'])->middleware('permission:shipping.manage')->name('shipping.deactivate');
        Route::get('/roles', [\App\Http\Controllers\Admin\RoleController::class, 'index'])->middleware('permission:roles.manage')->name('roles.index');
        Route::get('/roles/{role}', [\App\Http\Controllers\Admin\RoleController::class, 'show'])->middleware('permission:roles.manage')->name('roles.show');
        Route::put('/roles/{role}/permissions', [\App\Http\Controllers\Admin\RoleController::class, 'updatePermissions'])->middleware('permission:roles.manage')->name('roles.permissions.update');
        Route::resource('blog-posts', \App\Http\Controllers\Admin\BlogPostController::class)->except(['show', 'destroy'])->middleware('permission:content.manage');
        Route::resource('blog-categories', \App\Http\Controllers\Admin\BlogCategoryController::class)->only(['index', 'store', 'update', 'destroy'])->middleware('permission:content.manage');
        Route::resource('banners', \App\Http\Controllers\Admin\BannerController::class)->except('show')->middleware('permission:content.manage');
        Route::resource('showrooms', \App\Http\Controllers\Admin\ShowroomController::class)->except('show')->middleware('permission:content.manage');
        Route::resource('contact-messages', \App\Http\Controllers\Admin\ContactMessageController::class)->only(['index', 'show', 'update'])->middleware('permission:content.manage');
        Route::resource('newsletter-subscribers', \App\Http\Controllers\Admin\NewsletterSubscriberController::class)->only(['index', 'update'])->middleware('permission:content.manage');
    });

Route::get('/san-pham', [ProductController::class, 'index'])->name('products.index');
Route::get('/san-pham/{slug}', [ProductController::class, 'show'])->name('product.show');
Route::post('/san-pham/{product}/danh-gia', [\App\Http\Controllers\ReviewController::class, 'store'])
    ->name('product.reviews.store')
    ->middleware(['auth', 'active']);

Route::middleware('active')->group(function () {
    Route::get('/gio-hang', [\App\Http\Controllers\CartController::class, 'index'])->name('cart.index');
    Route::post('/gio-hang/them', [\App\Http\Controllers\CartController::class, 'add'])->name('cart.add');
    Route::put('/gio-hang/cap-nhat/{item}', [\App\Http\Controllers\CartController::class, 'update'])->name('cart.update');
    Route::delete('/gio-hang/xoa/{item}', [\App\Http\Controllers\CartController::class, 'remove'])->name('cart.remove');

    Route::get('/yeu-thich', [\App\Http\Controllers\WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/yeu-thich/them', [\App\Http\Controllers\WishlistController::class, 'add'])->name('wishlist.add');
    Route::delete('/yeu-thich/xoa/{product}', [\App\Http\Controllers\WishlistController::class, 'remove'])->name('wishlist.remove');
    Route::get('/yeu-thich/kiem-tra/{product}', [\App\Http\Controllers\WishlistController::class, 'check'])->name('wishlist.check');
});

// Authentication
Route::get('/dang-nhap', [\App\Http\Controllers\Auth\LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/dang-nhap', [\App\Http\Controllers\Auth\LoginController::class, 'login'])->name('login.store')->middleware('guest');
Route::post('/dang-xuat', [\App\Http\Controllers\Auth\LoginController::class, 'logout'])->name('logout');

Route::get('/dang-ky', [\App\Http\Controllers\Auth\RegisterController::class, 'showRegistrationForm'])->name('register')->middleware('guest');
Route::post('/dang-ky', [\App\Http\Controllers\Auth\RegisterController::class, 'register'])->name('register.store')->middleware('guest');

Route::post('/thanh-toan/callback', [\App\Http\Controllers\PaymentController::class, 'callback'])
    ->name('payment.callback')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

// Account
Route::get('/thanh-toan/vnpay/return', [\App\Http\Controllers\VnPayController::class, 'returnResult'])->middleware('throttle:payment-return')->name('vnpay.return');
Route::get('/thanh-toan/vnpay/ipn', [\App\Http\Controllers\VnPayController::class, 'ipn'])->name('vnpay.ipn');
Route::get('/thanh-toan/momo/return', [\App\Http\Controllers\MoMoController::class, 'returnResult'])->middleware('throttle:payment-return')->name('momo.return');
Route::post('/thanh-toan/momo/ipn', [\App\Http\Controllers\MoMoController::class, 'ipn'])->name('momo.ipn');

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/don-hang/{order}/vnpay', [\App\Http\Controllers\VnPayController::class, 'initiate'])->middleware('throttle:payment-initiation')->name('vnpay.initiate');
    Route::post('/don-hang/{order}/momo', [\App\Http\Controllers\MoMoController::class, 'initiate'])->middleware('throttle:payment-initiation')->name('momo.initiate');
    Route::get('/thanh-toan', [\App\Http\Controllers\CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('/thanh-toan', [\App\Http\Controllers\CheckoutController::class, 'store'])->middleware('throttle:payment-initiation')->name('checkout.store');
    Route::get('/don-hang/{order}', [\App\Http\Controllers\CheckoutController::class, 'show'])->name('order.show');
    Route::get('/don-hang/{order}/thanh-toan', [\App\Http\Controllers\PaymentController::class, 'show'])->name('payment.show');
    Route::post('/don-hang/{order}/refund', [\App\Http\Controllers\PaymentController::class, 'requestRefund'])->name('payment.refund');
    Route::post('/don-hang/{order}/huy', [\App\Http\Controllers\PaymentController::class, 'cancel'])->name('order.cancel');
    Route::post('/don-hang/{order}/da-giao', [\App\Http\Controllers\PaymentController::class, 'confirmDelivered'])->name('order.delivered');

    Route::get('/tai-khoan', [\App\Http\Controllers\AccountController::class, 'index'])->name('account.index');
    Route::get('/tai-khoan/thong-tin', [\App\Http\Controllers\AccountController::class, 'editProfile'])->name('account.profile.edit');
    Route::put('/tai-khoan/thong-tin', [\App\Http\Controllers\AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::get('/tai-khoan/doi-mat-khau', [\App\Http\Controllers\AccountController::class, 'changePassword'])->name('account.password.edit');
    Route::put('/tai-khoan/doi-mat-khau', [\App\Http\Controllers\AccountController::class, 'updatePassword'])->name('account.password.update');
    Route::get('/tai-khoan/don-hang', [\App\Http\Controllers\CheckoutController::class, 'accountOrders'])->name('account.orders');
    
    // Address Management
    Route::get('/tai-khoan/dia-chi', [\App\Http\Controllers\AddressController::class, 'index'])->name('address.index');
    Route::get('/tai-khoan/dia-chi/them', [\App\Http\Controllers\AddressController::class, 'create'])->name('address.create');
    Route::post('/tai-khoan/dia-chi', [\App\Http\Controllers\AddressController::class, 'store'])->name('address.store');
    Route::get('/tai-khoan/dia-chi/{address}/sua', [\App\Http\Controllers\AddressController::class, 'edit'])->name('address.edit');
    Route::put('/tai-khoan/dia-chi/{address}', [\App\Http\Controllers\AddressController::class, 'update'])->name('address.update');
    Route::delete('/tai-khoan/dia-chi/{address}', [\App\Http\Controllers\AddressController::class, 'delete'])->name('address.delete');
});
