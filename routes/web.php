<?php

use App\Http\Controllers\ProductController;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $rootCategory = Category::where('slug', 'trang-suc-bac')->first();
    $categories = $rootCategory
        ? $rootCategory->children()->where('is_active', true)->get()
        : Category::where('is_active', true)->limit(4)->get();

    $featuredProducts = Product::with('category')
        ->where('is_active', true)
        ->where(function ($query) {
            $query->where('featured', true)
                ->orWhere('sale_price', '>', 0);
        })
        ->limit(4)
        ->get();

    return view('storefront.home', [
        'pageTitle' => 'Silver Atelier | Trang sức bạc',
        'categories' => $categories,
        'featuredProducts' => $featuredProducts,
    ]);
})->name('home');

Route::get('/san-pham', [ProductController::class, 'index'])->name('products.index');
Route::get('/san-pham/{slug}', [ProductController::class, 'show'])->name('product.show');

Route::get('/gio-hang', [\App\Http\Controllers\CartController::class, 'index'])->name('cart.index');
Route::post('/gio-hang/them', [\App\Http\Controllers\CartController::class, 'add'])->name('cart.add');
Route::put('/gio-hang/cap-nhat/{item}', [\App\Http\Controllers\CartController::class, 'update'])->name('cart.update');
Route::delete('/gio-hang/xoa/{item}', [\App\Http\Controllers\CartController::class, 'remove'])->name('cart.remove');

Route::get('/yeu-thich', [\App\Http\Controllers\WishlistController::class, 'index'])->name('wishlist.index');
Route::post('/yeu-thich/them', [\App\Http\Controllers\WishlistController::class, 'add'])->name('wishlist.add');
Route::delete('/yeu-thich/xoa/{product}', [\App\Http\Controllers\WishlistController::class, 'remove'])->name('wishlist.remove');
Route::get('/yeu-thich/kiem-tra/{product}', [\App\Http\Controllers\WishlistController::class, 'check'])->name('wishlist.check');

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
Route::middleware('auth')->group(function () {
    Route::get('/thanh-toan', [\App\Http\Controllers\CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('/thanh-toan', [\App\Http\Controllers\CheckoutController::class, 'store'])->name('checkout.store');
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
