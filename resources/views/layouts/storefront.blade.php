<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Silver Atelier - Fine jewelry được tuyển chọn cho những khoảnh khắc đáng nhớ.">
    <title>{{ $pageTitle ?? 'Silver Atelier' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @php
        $footerSettings = \App\Models\Setting::whereIn('key', ['app_email', 'app_phone'])->pluck('value', 'key');
        $fineJewelryRoot = \App\Models\Category::query()
            ->where('is_active', true)
            ->where('slug', 'trang-suc-vang')
            ->with(['children' => fn ($query) => $query
                ->where('is_active', true)
                ->with(['children' => fn ($children) => $children
                    ->where('is_active', true)
                    ->orderBy('name'),
                ])
                ->orderBy('name'),
            ])
            ->first();
        $megaMenuOrder = [
            'trang-suc-kim-cuong',
            'trang-suc-cz',
            'trang-suc-da-mau',
            'trang-suc-ngoc-trai',
            'trang-suc-khong-gan-da',
        ];
        $megaMenuCategories = $fineJewelryRoot
            ? collect($megaMenuOrder)
                ->map(fn ($slug) => $fineJewelryRoot->children->firstWhere('slug', $slug))
                ->filter()
                ->values()
            : collect();
        $goldCatalogUrl = $fineJewelryRoot
            ? route('products.index', ['category' => $fineJewelryRoot->slug])
            : route('products.index');
        $diamondCatalogUrl = route('products.index', ['category' => 'trang-suc-kim-cuong']);
        $weddingCatalogUrl = $goldCatalogUrl;
        $highJewelryCatalogUrl = $goldCatalogUrl;
        $newArrivalsUrl = route('products.index', ['sort' => 'newest']);
    @endphp

    <header class="site-header">
        <div class="container site-header__main">
            <div class="header-social" aria-label="Liên hệ nhanh">
                <a href="{{ route('contact') }}" class="header-social__link" aria-label="Liên hệ">
                    <svg class="header-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="1.5"></rect><path d="m4 7 8 6 8-6"></path></svg>
                </a>
                <a href="{{ route('showrooms') }}" class="header-social__link" aria-label="Showroom">
                    <svg class="header-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s6-5.2 6-10a6 6 0 1 0-12 0c0 4.8 6 10 6 10Z"></path><circle cx="12" cy="11" r="2"></circle></svg>
                </a>
                @if (!empty($footerSettings['app_phone']))
                    <a href="tel:{{ preg_replace('/\s+/', '', $footerSettings['app_phone']) }}" class="header-social__link" aria-label="Gọi tư vấn">
                        <svg class="header-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.2 3.8 5.5 5.5c-.8.8-.9 2-.4 3 2.1 4.1 5.3 7.3 9.4 9.4 1 .5 2.2.4 3-.4l1.7-1.7-3.1-3.1-1.9 1.3a13.5 13.5 0 0 1-4.7-4.7l1.3-1.9-3.1-3.1Z"></path></svg>
                    </a>
                @endif
            </div>

            <a href="{{ url('/') }}" class="brand" aria-label="Silver Atelier home">
                <span class="brand__mark" aria-hidden="true">S</span>
                <span class="brand__name">Silver <em>Atelier</em></span>
            </a>

            <div class="site-header__actions">
                <form method="GET" action="{{ route('products.index') }}" class="header-search-inline" role="search">
                    <label for="desktop-header-search" class="sr-only">Tìm kiếm sản phẩm</label>
                    <button type="submit" class="header-search-inline__submit" aria-label="Tìm kiếm">
                        <svg class="header-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"></circle><path d="m16 16 4.5 4.5"></path></svg>
                    </button>
                    <input id="desktop-header-search" type="search" name="q" value="{{ request('q') }}" placeholder="Tìm sản phẩm...">
                </form>
                <button type="button" class="icon-button header-search-toggle" data-search-toggle aria-expanded="false" aria-controls="headerSearchPanel" aria-label="Mở tìm kiếm">
                    <svg class="header-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"></circle><path d="m16 16 4.5 4.5"></path></svg>
                </button>
                <a href="{{ route('wishlist.index') }}" class="icon-button header-wishlist" aria-label="Danh sách yêu thích">
                    <svg class="header-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.2 4.6 13a4.8 4.8 0 0 1 6.8-6.8L12 6.8l.6-.6A4.8 4.8 0 0 1 19.4 13L12 20.2Z"></path></svg>
                </a>
                <a href="{{ route('cart.index') }}" class="icon-button header-cart" aria-label="Giỏ hàng">
                    <svg class="header-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8.5h14l-1 11H6l-1-11Z"></path><path d="M9 8.5V6a3 3 0 0 1 6 0v2.5"></path></svg>
                </a>
                @auth
                    <div class="account-menu-wrap header-account">
                        <button type="button" class="icon-button" data-account-menu-trigger aria-expanded="false" aria-controls="accountMenu" aria-label="Mở tài khoản">
                            <svg class="header-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.2"></circle><path d="M5.2 20a6.8 6.8 0 0 1 13.6 0"></path></svg>
                        </button>
                        <div id="accountMenu" class="account-menu" aria-hidden="true" hidden>
                            <div class="account-menu__heading"><span>Xin chào</span><strong>{{ auth()->user()->name }}</strong></div>
                            <a href="{{ route('account.index') }}">Tài khoản</a>
                            <a href="{{ route('account.orders') }}">Đơn hàng</a>
                            <a href="{{ route('address.index') }}">Địa chỉ</a>
                            <a href="{{ route('wishlist.index') }}">Yêu thích</a>
                            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Đăng xuất</button></form>
                        </div>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="icon-button header-account" aria-label="Đăng nhập">
                        <svg class="header-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.2"></circle><path d="M5.2 20a6.8 6.8 0 0 1 13.6 0"></path></svg>
                    </a>
                @endauth
                <button type="button" class="menu-button" data-mobile-menu-toggle aria-expanded="false" aria-controls="mobileDrawer" aria-label="Mở điều hướng">
                    <svg class="header-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
                </button>
            </div>

            <div id="headerSearchPanel" class="search-panel" data-search-panel hidden>
                <form method="GET" action="{{ route('products.index') }}" role="search">
                    <label for="header-search" class="sr-only">Tìm kiếm sản phẩm</label>
                    <input id="header-search" type="search" name="q" value="{{ request('q') }}" placeholder="Tìm kiếm trang sức">
                    <button type="submit" class="button button--dark">Tìm kiếm</button>
                </form>
            </div>
        </div>

        <div class="site-header__nav-row">
            <nav class="container desktop-nav" aria-label="Điều hướng chính">
                <a href="{{ route('home') }}">Trang chủ</a>
                @if ($fineJewelryRoot?->children->isNotEmpty())
                    <div class="desktop-nav__group" data-mega-group>
                        <button type="button" class="desktop-nav__trigger" data-mega-trigger aria-expanded="false" aria-controls="mainMegaMenu">
                            {{ $fineJewelryRoot->name }}
                        </button>
                        <div id="mainMegaMenu" class="mega-menu" data-mega-menu aria-label="{{ $fineJewelryRoot->name }}" hidden>
                            <div class="mega-menu__grid">
                                @foreach ($megaMenuCategories as $category)
                                    <section class="mega-menu__column">
                                        <a href="{{ route('products.index', ['category' => $category->slug]) }}" class="mega-menu__title">{{ $category->name }}</a>
                                        @if ($category->children->isNotEmpty())
                                            <ul>
                                                @foreach ($category->children as $child)
                                                    <li><a href="{{ route('products.index', ['category' => $child->slug]) }}">{{ $child->name }}</a></li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </section>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @else
                    <a href="{{ $goldCatalogUrl }}">Trang sức vàng</a>
                @endif
                <a href="{{ $weddingCatalogUrl }}">Trang sức cưới</a>
                <a href="{{ $highJewelryCatalogUrl }}">Trang sức cao cấp</a>
                <a href="{{ $diamondCatalogUrl }}">Kim cương</a>
                <a href="{{ $newArrivalsUrl }}">New arrivals</a>
                <a href="{{ route('blog.index') }}">Tin tức</a>
                <a href="{{ route('contact') }}">Liên hệ</a>
            </nav>
        </div>
    </header>

    <div class="drawer-backdrop" data-mobile-backdrop hidden></div>
    <aside id="mobileDrawer" class="mobile-drawer" data-mobile-drawer aria-label="Điều hướng trên điện thoại" hidden>
        <div class="mobile-drawer__top">
            <a href="{{ url('/') }}" class="brand" aria-label="Silver Atelier home"><span class="brand__mark" aria-hidden="true">S</span><span class="brand__name">Silver <em>Atelier</em></span></a>
            <button type="button" class="icon-button" data-mobile-menu-close aria-label="Đóng điều hướng"><span aria-hidden="true">×</span></button>
        </div>
        <form method="GET" action="{{ route('products.index') }}" role="search" class="mobile-search">
            <label for="mobile-search" class="sr-only">Tìm kiếm sản phẩm</label>
            <input id="mobile-search" type="search" name="q" value="{{ request('q') }}" placeholder="Tìm kiếm trang sức">
        </form>
        <nav class="mobile-nav" aria-label="Điều hướng trên điện thoại">
            <a href="{{ route('home') }}">Trang chủ</a>
            @if ($fineJewelryRoot?->children->isNotEmpty())
                <details class="mobile-category-tree">
                    <summary>{{ $fineJewelryRoot->name }}</summary>
                    <a class="mobile-category-tree__all" href="{{ route('products.index', ['category' => $fineJewelryRoot->slug]) }}">Xem tất cả {{ $fineJewelryRoot->name }}</a>
                    @foreach ($fineJewelryRoot->children as $category)
                        <details>
                            <summary>{{ $category->name }}</summary>
                            <a class="mobile-category-tree__all" href="{{ route('products.index', ['category' => $category->slug]) }}">Xem tất cả</a>
                            @foreach ($category->children as $child)
                                <a href="{{ route('products.index', ['category' => $child->slug]) }}">{{ $child->name }}</a>
                            @endforeach
                        </details>
                    @endforeach
                </details>
            @else
                <a href="{{ $goldCatalogUrl }}">Trang sức vàng</a>
            @endif
            <a href="{{ $weddingCatalogUrl }}">Trang sức cưới</a>
            <a href="{{ $highJewelryCatalogUrl }}">Trang sức cao cấp</a>
            <a href="{{ $diamondCatalogUrl }}">Kim cương</a>
            <a href="{{ $newArrivalsUrl }}">New arrivals</a>
            <a href="{{ route('blog.index') }}">Tin tức</a>
            <a href="{{ route('contact') }}">Liên hệ</a>
            @auth
                <a href="{{ route('account.index') }}">Tài khoản</a><a href="{{ route('account.orders') }}">Đơn hàng</a><a href="{{ route('wishlist.index') }}">Yêu thích</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Đăng xuất</button></form>
            @else
                <a href="{{ route('login') }}">Đăng nhập</a>
            @endauth
        </nav>
    </aside>

    <main>
        @if (session('cart_notices'))
            <div class="container notice-stack" aria-live="polite">
                @foreach ((array) session('cart_notices') as $notice)<p class="notice notice--warning">{{ $notice }}</p>@endforeach
            </div>
        @endif
        @yield('content')
    </main>

    <footer class="site-footer" aria-label="Thông tin Silver Atelier">
        <div class="container site-footer__grid">
            <section class="site-footer__brand" aria-labelledby="footer-brand-heading">
                <a href="{{ route('home') }}" class="brand footer-brand" aria-label="Silver Atelier trang chủ">
                    <span class="brand__mark" aria-hidden="true">S</span>
                    <span class="brand__name">Silver <em>Atelier</em></span>
                </a>
                <h2 id="footer-brand-heading" class="sr-only">Silver Atelier</h2>
                <p>Fine jewelry được tuyển chọn cho những khoảnh khắc đáng nhớ.</p>
            </section>

            <section class="site-footer__column" aria-labelledby="footer-contact-heading">
                <h2 id="footer-contact-heading">Kết nối</h2>
                <ul>
                    <li><a href="{{ route('contact') }}">Liên hệ Silver Atelier</a></li>
                    <li><a href="{{ route('showrooms') }}">Hệ thống Showroom</a></li>
                    @if (filled($footerSettings['app_phone'] ?? null))
                        <li><a href="tel:{{ preg_replace('/\s+/', '', $footerSettings['app_phone']) }}">{{ $footerSettings['app_phone'] }}</a></li>
                    @endif
                    @if (filled($footerSettings['app_email'] ?? null))
                        <li><a href="mailto:{{ $footerSettings['app_email'] }}">{{ $footerSettings['app_email'] }}</a></li>
                    @endif
                </ul>
            </section>

            <nav class="site-footer__column" aria-labelledby="footer-support-heading">
                <h2 id="footer-support-heading">Hỗ trợ khách hàng</h2>
                <ul>
                    <li><a href="{{ route('products.index') }}">Khám phá trang sức</a></li>
                    <li><a href="{{ route('blog.index') }}">Tin tức</a></li>
                    <li><a href="{{ route('contact') }}">Gửi yêu cầu hỗ trợ</a></li>
                </ul>
            </nav>

            <nav class="site-footer__column" aria-labelledby="footer-policy-heading">
                <h2 id="footer-policy-heading">Chính sách</h2>
                <ul>
                    <li><a href="{{ route('policy.show', 'returns') }}">Chính sách đổi trả</a></li>
                    <li><a href="{{ route('policy.show', 'shipping') }}">Chính sách vận chuyển</a></li>
                    <li><a href="{{ route('policy.show', 'warranty') }}">Chính sách bảo hành</a></li>
                </ul>
            </nav>
        </div>

        <div class="container site-footer__utility">
            <section class="site-footer__newsletter" aria-labelledby="footer-newsletter-heading">
                <div>
                    <h2 id="footer-newsletter-heading">Đăng ký nhận bản tin</h2>
                    <p>Nhận thông tin mới từ Silver Atelier.</p>
                </div>
                <form method="POST" action="{{ route('newsletter.subscribe') }}" class="site-footer__newsletter-form">
                    @csrf
                    <label for="footer-newsletter-email" class="sr-only">Địa chỉ email</label>
                    <input id="footer-newsletter-email" type="email" name="email" value="{{ old('email') }}" placeholder="Email của bạn" required>
                    <button type="submit">Đăng ký</button>
                </form>
                @if (session('newsletter_success'))
                    <p class="form-success" role="status">{{ session('newsletter_success') }}</p>
                @endif
                @error('email')
                    <p class="form-error" role="alert">{{ $message }}</p>
                @enderror
            </section>

            <section class="site-footer__payments" aria-labelledby="footer-payment-heading">
                <h2 id="footer-payment-heading">Phương thức thanh toán</h2>
                <span class="site-footer__payment-method">COD</span>
            </section>
        </div>

        <div class="site-footer__copyright">
            <div class="container">
                <span>© {{ now()->year }} Silver Atelier. All rights reserved.</span>
            </div>
        </div>
    </footer>
</body>
</html>
