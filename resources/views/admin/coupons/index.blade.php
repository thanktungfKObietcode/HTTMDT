@extends('layouts.admin')

@section('content')
<div style="display:grid; gap:20px;">
    @if(session('success'))<div class="admin-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="admin-error" style="padding:12px 14px; background:#fff1f0; border-radius:8px;">{{ $errors->first() }}</div>@endif

    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div><span class="eyebrow">Quản trị</span><h1 style="margin:0;">Mã giảm giá</h1></div>
        <a class="btn btn-primary" href="{{ route('admin.coupons.create') }}">+ Tạo mã mới</a>
    </div>

    <form method="GET" action="{{ route('admin.coupons.index') }}" class="filter-box" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end;">
        <div style="flex:1; min-width:220px;">
            <label for="q">Tìm mã</label>
            <input id="q" name="q" value="{{ request('q') }}" placeholder="Nhập code..." style="width:100%; padding:10px;">
        </div>
        <div>
            <label for="status">Trạng thái</label>
            <select id="status" name="status" style="padding:10px;">
                <option value="">Tất cả</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Đang hoạt động</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Vô hiệu hóa</option>
            </select>
        </div>
        <button class="btn btn-secondary" type="submit">Lọc</button>
        @if(request()->hasAny(['q','status']))
            <a class="btn btn-secondary" href="{{ route('admin.coupons.index') }}">Xóa lọc</a>
        @endif
    </form>

    <div class="filter-box" style="overflow-x:auto;">
        <table style="width:100%; min-width:800px; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:10px;">Mã</th>
                    <th style="text-align:left; padding:10px;">Loại</th>
                    <th style="text-align:right; padding:10px;">Giá trị</th>
                    <th style="text-align:right; padding:10px;">Tối thiểu đơn</th>
                    <th style="text-align:left; padding:10px;">Hiệu lực</th>
                    <th style="text-align:center; padding:10px;">Đã dùng</th>
                    <th style="text-align:left; padding:10px;">Trạng thái</th>
                    <th style="padding:10px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($coupons as $coupon)
                    <tr>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; font-weight:700; font-family:monospace; letter-spacing:.04em;">{{ $coupon->code }}</td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb;">
                            {{ $coupon->type === 'percent' ? 'Phần trăm' : 'Cố định' }}
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; text-align:right;">
                            {{ $coupon->type === 'percent' ? number_format((float)$coupon->value, 0).'%' : number_format((float)$coupon->value, 0, ',', '.').'đ' }}
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; text-align:right;">
                            {{ $coupon->minimum_order_amount ? number_format((float)$coupon->minimum_order_amount, 0, ',', '.').'đ' : '—' }}
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; font-size:.85rem;">
                            @if($coupon->starts_at || $coupon->ends_at)
                                {{ $coupon->starts_at?->format('d/m/Y') ?? '∞' }} → {{ $coupon->ends_at?->format('d/m/Y') ?? '∞' }}
                            @else
                                <span style="color:#9ca3af;">Không giới hạn</span>
                            @endif
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; text-align:center;">
                            {{ $coupon->usages_count }}{{ $coupon->usage_limit ? ' / '.$coupon->usage_limit : '' }}
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb;">
                            @if($coupon->is_active)
                                <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#d1fae5; color:#065f46; font-size:.8rem;">Hoạt động</span>
                            @else
                                <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#fee2e2; color:#991b1b; font-size:.8rem;">Vô hiệu hóa</span>
                            @endif
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; white-space:nowrap;">
                            <a class="btn btn-secondary" href="{{ route('admin.coupons.edit', $coupon) }}" style="margin-right:4px;">Sửa</a>
                            @if($coupon->is_active)
                                <form method="POST" action="{{ route('admin.coupons.deactivate', $coupon) }}" style="display:inline;">
                                    @csrf
                                    <button class="btn btn-secondary" type="submit">Vô hiệu hóa</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.coupons.activate', $coupon) }}" style="display:inline;">
                                    @csrf
                                    <button class="btn btn-primary" type="submit">Kích hoạt</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('admin.coupons.destroy', $coupon) }}" style="display:inline;" onsubmit="return confirm('Xóa mã {{ $coupon->code }}? Hành động không thể hoàn tác.')">
                                @csrf @method('DELETE')
                                <button class="btn btn-secondary" type="submit" style="margin-left:4px; color:#b91c1c;">Xóa</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="padding:18px; text-align:center;">Không có mã giảm giá phù hợp.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $coupons->links() }}
</div>
@endsection