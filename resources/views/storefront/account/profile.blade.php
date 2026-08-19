@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Quản lý tài khoản</span>
            <h1>Chỉnh sửa hồ sơ</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; grid-template-columns:250px 1fr; gap:40px;">
            <aside style="display:grid; gap:12px;">
                <h3>Quản lý tài khoản</h3>
                <nav style="display:grid; gap:0; border:1px solid rgba(31,28,26,0.1); border-radius:10px; overflow:hidden;">
                    <a href="{{ route('account.index') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a; background:{{ Route::currentRouteName() === 'account.index' ? 'rgba(31,28,26,0.05)' : 'transparent' }};">📋 Thông tin</a>
                    <a href="{{ route('account.profile.edit') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a; background:rgba(31,28,26,0.05); font-weight:600;">✏️ Chỉnh sửa hồ sơ</a>
                    <a href="{{ route('account.password.edit') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a;">🔒 Đổi mật khẩu</a>
                    <a href="{{ route('address.index') }}" style="padding:12px 16px; text-decoration:none; color:#1f1c1a;">📍 Địa chỉ</a>
                </nav>
            </aside>

            <div>
                <div class="filter-box">
                    <h2>Cập nhật thông tin cá nhân</h2>

                    <form method="POST" action="{{ route('account.profile.update') }}" style="margin-top:24px; display:grid; gap:20px;">
                        @csrf
                        @method('PUT')

                        <div>
                            <label for="name" style="display:block; margin-bottom:8px; font-weight:600;">Họ tên *</label>
                            <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            @error('name')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="email" style="display:block; margin-bottom:8px; font-weight:600;">Email *</label>
                            <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            @error('email')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="phone" style="display:block; margin-bottom:8px; font-weight:600;">Số điện thoại</label>
                            <input type="tel" name="phone" id="phone" value="{{ old('phone', $user->phone) }}" style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            @error('phone')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="gender" style="display:block; margin-bottom:8px; font-weight:600;">Giới tính</label>
                            <select name="gender" id="gender" style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;">
                                <option value="">-- Chọn --</option>
                                <option value="male" {{ old('gender', $user->gender) === 'male' ? 'selected' : '' }}>Nam</option>
                                <option value="female" {{ old('gender', $user->gender) === 'female' ? 'selected' : '' }}>Nữ</option>
                                <option value="other" {{ old('gender', $user->gender) === 'other' ? 'selected' : '' }}>Khác</option>
                            </select>
                            @error('gender')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="birth_date" style="display:block; margin-bottom:8px; font-weight:600;">Ngày sinh</label>
                            <input type="date" name="birth_date" id="birth_date" value="{{ old('birth_date', $user->birth_date?->format('Y-m-d')) }}" style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            @error('birth_date')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" name="receive_newsletter" id="receive_newsletter" value="1" {{ old('receive_newsletter', $user->receive_newsletter) ? 'checked' : '' }} style="width:18px; height:18px;">
                            <label for="receive_newsletter" style="margin:0; cursor:pointer;">Nhận thông tin khuyến mãi qua email</label>
                        </div>

                        <div style="display:flex; gap:12px; margin-top:24px;">
                            <button type="submit" class="btn btn-primary">Cập nhật</button>
                            <a href="{{ route('account.index') }}" class="btn btn-secondary">Hủy</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection
