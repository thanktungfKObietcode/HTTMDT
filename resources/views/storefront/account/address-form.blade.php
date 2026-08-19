@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Quản lý tài khoản</span>
            <h1>{{ $address ? 'Chỉnh sửa' : 'Thêm' }} địa chỉ</h1>
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
                <div class="filter-box">
                    <h2>{{ $address ? 'Chỉnh sửa' : 'Thêm' }} địa chỉ</h2>

                    <form method="POST" action="{{ $address ? route('address.update', $address->id) : route('address.store') }}" style="margin-top:24px; display:grid; gap:20px;">
                        @csrf
                        @if($address)
                            @method('PUT')
                        @endif

                        <div>
                            <label for="full_name" style="display:block; margin-bottom:8px; font-weight:600;">Họ tên *</label>
                            <input type="text" name="full_name" id="full_name" value="{{ old('full_name', $address?->full_name) }}" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            @error('full_name')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="phone" style="display:block; margin-bottom:8px; font-weight:600;">Số điện thoại *</label>
                            <input type="tel" name="phone" id="phone" value="{{ old('phone', $address?->phone) }}" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            @error('phone')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="province" style="display:block; margin-bottom:8px; font-weight:600;">Tỉnh/Thành phố *</label>
                            <input type="text" name="province" id="province" value="{{ old('province', $address?->province) }}" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            @error('province')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="district" style="display:block; margin-bottom:8px; font-weight:600;">Quận/Huyện *</label>
                            <input type="text" name="district" id="district" value="{{ old('district', $address?->district) }}" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            @error('district')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="ward" style="display:block; margin-bottom:8px; font-weight:600;">Phường/Xã *</label>
                            <input type="text" name="ward" id="ward" value="{{ old('ward', $address?->ward) }}" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px;" />
                            @error('ward')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="address_line" style="display:block; margin-bottom:8px; font-weight:600;">Địa chỉ cụ thể *</label>
                            <textarea name="address_line" id="address_line" required style="width:100%; padding:12px 14px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; font-size:14px; min-height:80px; font-family:inherit;">{{ old('address_line', $address?->address_line) }}</textarea>
                            @error('address_line')
                                <span style="color:#d32f2f; font-size:12px; margin-top:4px; display:block;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" name="is_default" id="is_default" value="1" {{ old('is_default', $address?->is_default) ? 'checked' : '' }} style="width:18px; height:18px;">
                            <label for="is_default" style="margin:0; cursor:pointer;">Đặt làm địa chỉ mặc định</label>
                        </div>

                        <div style="display:flex; gap:12px; margin-top:24px;">
                            <button type="submit" class="btn btn-primary">{{ $address ? 'Cập nhật' : 'Thêm' }} địa chỉ</button>
                            <a href="{{ route('address.index') }}" class="btn btn-secondary">Hủy</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection
