<!DOCTYPE html>
<html lang="vi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Silver Atelier - Trang sức bạc cao cấp, thiết kế tinh tế cho phong cách hiện đại.">
        <title>{{ $pageTitle ?? 'Silver Atelier' }}</title>
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        <script src="{{ asset('js/app.js') }}" defer></script>
    </head>
    <body>
        <header class="site-header">
            <div class="container header-inner">
                <a href="{{ url('/') }}" class="brand" aria-label="Silver Atelier home">
                    <span class="brand-mark">S</span>
                    <span class="brand-text">
                        <strong>Silver</strong>
                        <small>Atelier</small>
                    </span>
                </a>

                <nav class="main-nav" aria-label="Main navigation">
                    <a href="{{ url('/') }}">Trang chủ</a>
                    <a href="{{ url('/san-pham') }}">Sản phẩm</a>
                    <a href="#collection">Bộ sưu tập</a>
                    <a href="#story">Câu chuyện</a>
                    <a href="#journal">Tin tức</a>
                </nav>

                <div class="header-tools">
                    <form class="search-box" method="GET" action="{{ route('products.index') }}" role="search">
                        <label for="header-search" class="sr-only">Tìm kiếm sản phẩm</label>
                        <span>⌕</span>
                        <input id="header-search" type="search" name="q" value="{{ request('q') }}" placeholder="Tìm kiếm..." aria-label="Search">
                    </form>
                    <a href="{{ route('wishlist.index') }}" class="icon-link" aria-label="Wishlist">♡</a>
                    <a href="{{ route('cart.index') }}" class="icon-link" aria-label="Cart">🛍</a>
                    @auth
                        <div style="position:relative; display:inline-block;">
                            <button class="icon-link" onclick="toggleAccountMenu()" aria-label="Account" style="background:none; border:none; cursor:pointer; padding:0; font-size:20px;">👤</button>
                            <div id="accountMenu" style="display:none; position:absolute; top:100%; right:0; background:white; border:1px solid rgba(31,28,26,0.1); border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.1); min-width:200px; z-index:100; overflow:hidden;">
                                <div style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); font-size:14px;">
                                    <p style="margin:0; color:#666;">Xin chào</p>
                                    <p style="margin:4px 0 0; font-weight:600;">{{ auth()->user()->name }}</p>
                                </div>
                                <a href="{{ route('account.index') }}" style="display:block; padding:12px 16px; color:#1f1c1a; text-decoration:none; border-bottom:1px solid rgba(31,28,26,0.1);">📋 Tài khoản</a>
                                <a href="{{ route('account.orders') }}" style="display:block; padding:12px 16px; color:#1f1c1a; text-decoration:none; border-bottom:1px solid rgba(31,28,26,0.1);">🧾 Đơn hàng</a>
                                <a href="{{ route('address.index') }}" style="display:block; padding:12px 16px; color:#1f1c1a; text-decoration:none; border-bottom:1px solid rgba(31,28,26,0.1);">📍 Địa chỉ</a>
                                <a href="{{ route('wishlist.index') }}" style="display:block; padding:12px 16px; color:#1f1c1a; text-decoration:none; border-bottom:1px solid rgba(31,28,26,0.1);">♡ Yêu thích</a>
                                <form method="POST" action="{{ route('logout') }}" style="display:block;">
                                    @csrf
                                    <button type="submit" style="width:100%; padding:12px 16px; text-align:left; border:none; background:none; color:#1f1c1a; cursor:pointer; font-size:14px;">🚪 Đăng xuất</button>
                                </form>
                            </div>
                        </div>
                    @else
                        <a href="{{ route('login') }}" class="icon-link" aria-label="Login" style="text-decoration:none; font-size:14px; padding:8px 12px;">🔐</a>
                    @endauth
                    <button class="menu-toggle" type="button" aria-label="Open menu">☰</button>
                </div>
            </div>
        </header>

        <main>
            @yield('content')
        </main>

        <footer class="site-footer">
            <div class="container footer-grid">
                <div>
                    <a href="{{ url('/') }}" class="brand footer-brand">
                        <span class="brand-mark">S</span>
                        <span class="brand-text">
                            <strong>Silver</strong>
                            <small>Atelier</small>
                        </span>
                    </a>
                    <p class="footer-copy">Thiết kế bạc tinh xảo cho những khoảnh khắc đáng nhớ, kết hợp vẻ đẹp cổ điển với cảm hứng hiện đại.</p>
                </div>

                <div>
                    <h3>Danh mục</h3>
                    <ul>
                        <li><a href="{{ url('/san-pham') }}">Nhẫn bạc</a></li>
                        <li><a href="{{ url('/san-pham') }}">Dây chuyền</a></li>
                        <li><a href="{{ url('/san-pham') }}">Lắc tay</a></li>
                        <li><a href="{{ url('/san-pham') }}">Bông tai</a></li>
                    </ul>
                </div>

                <div>
                    <h3>Hỗ trợ</h3>
                    <ul>
                        <li><a href="#">Chính sách đổi trả</a></li>
                        <li><a href="#">Vận chuyển</a></li>
                        <li><a href="#">Bảo hành</a></li>
                        <li><a href="#">Liên hệ</a></li>
                    </ul>
                </div>

                <div>
                    <h3>Liên hệ</h3>
                    <ul>
                        <li>Hotline: 1900 1234</li>
                        <li>Email: hello@silveratelier.vn</li>
                        <li>Showroom: 18 Trần Hưng Đạo, Hà Nội</li>
                    </ul>
                </div>
            </div>

            <div class="container footer-bottom">
                <span>© <span id="currentYear"></span> Silver Atelier</span>
                <span>Thiết kế bằng bạc 925 • Gửi tặng ý nghĩa</span>
            </div>
        </footer>
    </body>
</html>
