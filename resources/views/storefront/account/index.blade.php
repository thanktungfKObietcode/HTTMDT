@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Quản lý tài khoản</span>
            <h1>Tài khoản của bạn</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; grid-template-columns:250px 1fr; gap:40px;">
            <aside style="display:grid; gap:12px;">
                <h3>Quản lý tài khoản</h3>
                <nav style="display:grid; gap:0; border:1px solid rgba(31,28,26,0.1); border-radius:10px; overflow:hidden;">
                    <a href="{{ route('account.index') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a; background:{{ Route::currentRouteName() === 'account.index' ? 'rgba(31,28,26,0.05)' : 'transparent' }}; font-weight:{{ Route::currentRouteName() === 'account.index' ? '600' : '400' }};">📋 Thông tin</a>
                    <a href="{{ route('account.profile.edit') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a; background:{{ Route::currentRouteName() === 'account.profile.edit' ? 'rgba(31,28,26,0.05)' : 'transparent' }}; font-weight:{{ Route::currentRouteName() === 'account.profile.edit' ? '600' : '400' }};">✏️ Chỉnh sửa hồ sơ</a>
                    <a href="{{ route('account.password.edit') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a; background:{{ Route::currentRouteName() === 'account.password.edit' ? 'rgba(31,28,26,0.05)' : 'transparent' }}; font-weight:{{ Route::currentRouteName() === 'account.password.edit' ? '600' : '400' }};">🔒 Đổi mật khẩu</a>
                    <a href="{{ route('address.index') }}" style="padding:12px 16px; text-decoration:none; color:#1f1c1a; background:{{ Route::currentRouteName() === 'address.index' ? 'rgba(31,28,26,0.05)' : 'transparent' }}; font-weight:{{ Route::currentRouteName() === 'address.index' ? '600' : '400' }};">📍 Địa chỉ</a>
                </nav>

                <form method="POST" action="{{ route('logout') }}" style="margin-top:20px;">
                    @csrf
                    <button type="submit" class="btn btn-secondary" style="width:100%;">🚪 Đăng xuất</button>
                </form>
            </aside>

            <div>
                <div class="filter-box">
                    <h2>Thông tin tài khoản</h2>

                    <div style="margin-top:24px; display:grid; gap:20px;">
                        <div>
                            <p style="margin:0 0 4px; font-weight:600; color:#666;">Họ tên</p>
                            <p style="margin:0; font-size:16px; font-weight:500;">{{ $user->name }}</p>
                        </div>

                        <div>
                            <p style="margin:0 0 4px; font-weight:600; color:#666;">Email</p>
                            <p style="margin:0; font-size:16px;">{{ $user->email }}</p>
                        </div>

                        @if($user->phone)
                            <div>
                                <p style="margin:0 0 4px; font-weight:600; color:#666;">Số điện thoại</p>
                                <p style="margin:0; font-size:16px;">{{ $user->phone }}</p>
                            </div>
                        @endif

                        @if($user->birth_date)
                            <div>
                                <p style="margin:0 0 4px; font-weight:600; color:#666;">Ngày sinh</p>
                                <p style="margin:0; font-size:16px;">{{ $user->birth_date->format('d/m/Y') }}</p>
                            </div>
                        @endif

                        @if($user->gender)
                            <div>
                                <p style="margin:0 0 4px; font-weight:600; color:#666;">Giới tính</p>
                                <p style="margin:0; font-size:16px;">
                                    @switch($user->gender)
                                        @case('male')
                                            Nam
                                            @break
                                        @case('female')
                                            Nữ
                                            @break
                                        @default
                                            Khác
                                    @endswitch
                                </p>
                            </div>
                        @endif

                        <div>
                            <p style="margin:0 0 4px; font-weight:600; color:#666;">Trạng thái tài khoản</p>
                            <span style="display:inline-block; padding:6px 12px; background:{{ $user->is_active ? '#4caf50' : '#f44336' }}; color:white; border-radius:20px; font-size:12px; font-weight:600;">
                                {{ $user->is_active ? '✓ Đang hoạt động' : '✕ Bị tạm khóa' }}
                            </span>
                        </div>

                        <div>
                            <p style="margin:0 0 4px; font-weight:600; color:#666;">Tham gia từ</p>
                            <p style="margin:0; font-size:16px;">{{ $user->created_at->format('d/m/Y') }}</p>
                        </div>
                    </div>

                    <a href="{{ route('account.profile.edit') }}" class="btn btn-primary" style="display:inline-block; margin-top:24px;">Chỉnh sửa thông tin</a>
                </div>
            </div>
        </div>
    </section>
@endsection
