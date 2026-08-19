@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Quản lý tài khoản</span>
            <h1>Đổi mật khẩu</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; grid-template-columns:250px 1fr; gap:40px;">
            <aside style="display:grid; gap:12px;">
                <h3>Quản lý tài khoản</h3>
                <nav style="display:grid; gap:0; border:1px solid rgba(31,28,26,0.1); border-radius:10px; overflow:hidden;">
                    <a href="{{ route('account.index') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a;">📋 Thông tin</a>
                    <a href="{{ route('account.profile.edit') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a;">✏️ Chỉnh sửa hồ sơ</a>
                    <a href="{{ route('account.password.edit') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a; background:rgba(31,28,26,0.05); font-weight:600;">🔒 Đổi mật khẩu</a>
                    <a href="{{ route('address.index') }}" style="padding:12px 16px; text-decoration:none; color:#1f1c1a;">📍 Địa chỉ</a>
                </nav>
            </aside>

            <div>
                <div class="filter-box">
                    <h2>Thay đổi mật khẩu</h2>
                    <p style="color:#666; margin:12px 0 0;">Để bảo vệ tài khoản, vui lòng chọn mật khẩu mạnh.</p>

                    <form method="POST" action="{{ route('account.password.update') }}" style="margin-top:24px; display:grid; gap:20px;">
                        @csrf
                        @method('PUT')

                        <div>
                            <label for="current_password" style="display:block; margin-bottom:8px; font-weight:600;">Mật khẩu hiện tại *</label>
                            <input type="password" name="current_password" id="current_password" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            @error('current_password')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="new_password" style="display:block; margin-bottom:8px; font-weight:600;">Mật khẩu mới *</label>
                            <input type="password" name="new_password" id="new_password" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            <small style="color:#666; margin-top:4px; display:block;">Tối thiểu 8 ký tự</small>
                            @error('new_password')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="new_password_confirmation" style="display:block; margin-bottom:8px; font-weight:600;">Xác nhận mật khẩu mới *</label>
                            <input type="password" name="new_password_confirmation" id="new_password_confirmation" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            @error('new_password_confirmation')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div style="display:flex; gap:12px; margin-top:24px;">
                            <button type="submit" class="btn btn-primary">Cập nhật mật khẩu</button>
                            <a href="{{ route('account.index') }}" class="btn btn-secondary">Hủy</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection
