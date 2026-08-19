@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Tài khoản</span>
            <h1>Đăng ký</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(400px, 1fr)); gap:40px; margin:0 auto; max-width:900px;">
            <div class="filter-box">
                <h2>Tạo tài khoản mới</h2>

                <form method="POST" action="{{ route('register.store') }}" style="display:grid; gap:16px; margin-top:24px;">
                    @csrf

                    <div>
                        <label for="name" style="display:block; margin-bottom:8px; font-weight:600;">Họ tên *</label>
                        <input type="text" name="name" id="name" value="{{ old('name') }}" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                        @error('name')
                            <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label for="email" style="display:block; margin-bottom:8px; font-weight:600;">Email *</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                        @error('email')
                            <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label for="phone" style="display:block; margin-bottom:8px; font-weight:600;">Số điện thoại</label>
                        <input type="tel" name="phone" id="phone" value="{{ old('phone') }}" style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                        @error('phone')
                            <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label for="password" style="display:block; margin-bottom:8px; font-weight:600;">Mật khẩu *</label>
                        <input type="password" name="password" id="password" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                        @error('password')
                            <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" style="display:block; margin-bottom:8px; font-weight:600;">Xác nhận mật khẩu *</label>
                        <input type="password" name="password_confirmation" id="password_confirmation" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                    </div>

                    <button type="submit" class="btn btn-primary large" style="margin-top:12px;">Đăng ký</button>

                    <p style="text-align:center; font-size:14px; margin-top:16px;">
                        Đã có tài khoản?
                        <a href="{{ route('login') }}" style="color:#8b7355; text-decoration:none; font-weight:600;">Đăng nhập</a>
                    </p>
                </form>
            </div>

            <div class="filter-box">
                <h3>Tại sao đăng ký?</h3>
                <ul style="padding-left:20px;">
                    <li style="margin-bottom:12px;">✓ Lưu thông tin cá nhân và địa chỉ</li>
                    <li style="margin-bottom:12px;">✓ Theo dõi đơn hàng của bạn</li>
                    <li style="margin-bottom:12px;">✓ Lưu sản phẩm yêu thích</li>
                    <li style="margin-bottom:12px;">✓ Thanh toán nhanh chóng</li>
                    <li style="margin-bottom:12px;">✓ Nhận ưu đãi độc quyền</li>
                </ul>
                <p style="margin-top:20px; font-size:13px; color:#666;">
                    Thông tin của bạn sẽ được bảo mật theo chính sách bảo vệ dữ liệu của chúng tôi.
                </p>
            </div>
        </div>
    </section>
@endsection
