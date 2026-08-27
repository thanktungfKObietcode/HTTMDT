<!DOCTYPE html>
<html lang="vi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $pageTitle ?? 'Admin' }}</title>
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        <style>.admin-error{display:block;color:#b42318;margin-top:4px}.admin-success{padding:12px 14px;border-radius:8px;background:#eaf7ef;color:#23613a}</style>
    </head>
    <body style="background:#f3f5f7; color:#1f2933;">
        <div style="min-height:100vh; display:grid; grid-template-columns:240px minmax(0,1fr);">
            <aside style="background:#1f2933; color:#fff; padding:24px 18px;">
                <a href="{{ route('admin.dashboard') }}" style="display:block; color:#fff; font-size:1.2rem; font-weight:700; margin-bottom:30px;">Silver Atelier Admin</a>
                <nav aria-label="Admin navigation" style="display:grid; gap:8px;">
                    <a href="{{ route('admin.dashboard') }}" style="padding:10px 12px; border-radius:8px; background:rgba(255,255,255,.12); color:#fff;">Dashboard</a>
                    <a href="{{ route('admin.orders.index') }}" style="padding:10px 12px; border-radius:8px; color:#fff;">Đơn hàng</a>
                    <a href="{{ route('admin.users.index') }}" style="padding:10px 12px; border-radius:8px; color:#fff;">Người dùng</a>
                    <a href="{{ route('admin.products.index') }}" style="padding:10px 12px; border-radius:8px; color:#fff;">Sản phẩm</a>
                    <a href="{{ route('admin.categories.index') }}" style="padding:10px 12px; border-radius:8px; color:#fff;">Danh mục</a>
                    <a href="{{ route('admin.collections.index') }}" style="padding:10px 12px; border-radius:8px; color:#fff;">Bộ sưu tập</a>
                    <a href="{{ route('admin.materials.index') }}" style="padding:10px 12px; border-radius:8px; color:#fff;">Chất liệu</a>
                    <a href="{{ route('admin.coupons.index') }}" style="padding:10px 12px; border-radius:8px; color:#fff;">Mã giảm giá</a>
                    <a href="{{ route('admin.shipping.index') }}" style="padding:10px 12px; border-radius:8px; color:#fff;">Vận chuyển</a>
                </nav>
            </aside>
            <div style="min-width:0;">
                <header style="min-height:72px; background:#fff; border-bottom:1px solid #dfe4e8; padding:16px 28px; display:flex; justify-content:space-between; align-items:center; gap:16px;">
                    <div><strong>{{ $pageTitle ?? 'Dashboard' }}</strong></div>
                    <div style="display:flex; align-items:center; gap:16px;">
                        <span>{{ auth()->user()->name }} · {{ auth()->user()->email }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-secondary" style="min-height:38px; padding:0 16px;">Đăng xuất</button>
                        </form>
                    </div>
                </header>
                <main style="padding:28px;">
                    @yield('content')
                </main>
            </div>
        </div>
    </body>
</html>