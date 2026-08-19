@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Quản lý tài khoản</span>
            <h1>Địa chỉ giao hàng</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; grid-template-columns:250px 1fr; gap:40px;">
            <aside style="display:grid; gap:12px;">
                <h3>Quản lý tài khoản</h3>
                <nav style="display:grid; gap:0; border:1px solid rgba(31,28,26,0.1); border-radius:10px; overflow:hidden;">
                    <a href="{{ route('account.index') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a;">📋 Thông tin</a>
                    <a href="{{ route('account.profile.edit') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a;">✏️ Chỉnh sửa hồ sơ</a>
                    <a href="{{ route('account.password.edit') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a;">🔒 Đổi mật khẩu</a>
                    <a href="{{ route('address.index') }}" style="padding:12px 16px; text-decoration:none; color:#1f1c1a; background:rgba(31,28,26,0.05); font-weight:600;">📍 Địa chỉ</a>
                </nav>
            </aside>

            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
                    <h2>Địa chỉ giao hàng</h2>
                    <a href="{{ route('address.create') }}" class="btn btn-primary">+ Thêm địa chỉ</a>
                </div>

                @if($addresses->isEmpty())
                    <div class="filter-box">
                        <h3>Chưa có địa chỉ nào.</h3>
                        <p>Thêm địa chỉ giao hàng để thanh toán nhanh hơn.</p>
                        <a href="{{ route('address.create') }}" class="btn btn-primary" style="display:inline-block; margin-top:16px;">Thêm địa chỉ</a>
                    </div>
                @else
                    <div style="display:grid; gap:16px;">
                        @foreach($addresses as $address)
                            <div class="filter-box">
                                <div style="display:flex; justify-content:space-between; align-items:start;">
                                    <div style="flex:1;">
                                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
                                            <h3 style="margin:0;">{{ $address->full_name }}</h3>
                                            @if($address->is_default)
                                                <span style="display:inline-block; padding:4px 10px; background:#4caf50; color:white; border-radius:20px; font-size:11px; font-weight:600;">Mặc định</span>
                                            @endif
                                        </div>
                                        <p style="margin:8px 0; color:#666;">
                                            {{ $address->address_line }}<br>
                                            {{ $address->ward }}, {{ $address->district }}, {{ $address->province }}
                                        </p>
                                        <p style="margin:8px 0; color:#666;">📞 {{ $address->phone }}</p>
                                    </div>

                                    <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                                        @if(!$address->is_default)
                                            <form method="POST" action="{{ route('address.update', $address->id) }}" style="display:inline;">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="full_name" value="{{ $address->full_name }}">
                                                <input type="hidden" name="phone" value="{{ $address->phone }}">
                                                <input type="hidden" name="province" value="{{ $address->province }}">
                                                <input type="hidden" name="district" value="{{ $address->district }}">
                                                <input type="hidden" name="ward" value="{{ $address->ward }}">
                                                <input type="hidden" name="address_line" value="{{ $address->address_line }}">
                                                <input type="hidden" name="is_default" value="1">
                                                <button type="submit" class="btn btn-secondary" style="font-size:12px; padding:6px 12px;">Đặt mặc định</button>
                                            </form>
                                        @endif
                                        <a href="{{ route('address.edit', $address->id) }}" class="btn btn-secondary" style="font-size:12px; padding:6px 12px;">Sửa</a>
                                        <form method="POST" action="{{ route('address.delete', $address->id) }}" style="display:inline;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa địa chỉ này?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-link" style="font-size:12px; padding:6px 12px; color:#d32f2f;">Xóa</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
