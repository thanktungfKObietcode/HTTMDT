@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Tài khoản</span>
            <h1>Đăng nhập</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(400px, 1fr)); gap:40px; margin:0 auto; max-width:900px;">
            <div class="filter-box">
                <h2>Đăng nhập</h2>

                <form method="POST" action="{{ route('login.store') }}" style="display:grid; gap:16px; margin-top:24px;">
                    @csrf

                    <div>
                        <label for="email" style="display:block; margin-bottom:8px; font-weight:600;">Email</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                        @error('email')
                            <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label for="password" style="display:block; margin-bottom:8px; font-weight:600;">Mật khẩu</label>
                        <input type="password" name="password" id="password" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                        @error('password')
                            <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                        @enderror
                    </div>

                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="remember" id="remember" value="1" style="width:18px; height:18px;">
                        <label for="remember" style="margin:0; cursor:pointer;">Ghi nhớ thông tin</label>
                    </div>

                    <button type="submit" class="btn btn-primary large" style="margin-top:12px;">Đăng nhập</button>

                    <p style="text-align:center; font-size:14px; margin-top:16px;">
                        Chưa có tài khoản?
                        <a href="{{ route('register') }}" style="color:#8b7355; text-decoration:none; font-weight:600;">Đăng ký ngay</a>
                    </p>
                </form>
            </div>

            <div class="filter-box">
                <h3>Lợi ích khi đăng nhập</h3>
                <ul style="padding-left:20px;">
                    <li style="margin-bottom:12px;">✓ Xem lịch sử mua hàng của bạn</li>
                    <li style="margin-bottom:12px;">✓ Lưu danh sách yêu thích</li>
                    <li style="margin-bottom:12px;">✓ Quản lý địa chỉ giao hàng</li>
                    <li style="margin-bottom:12px;">✓ Nhanh chóng thanh toán</li>
                    <li style="margin-bottom:12px;">✓ Nhận khuyến mãi độc quyền</li>
                </ul>
            </div>
        </div>
    </section>
@endsection
