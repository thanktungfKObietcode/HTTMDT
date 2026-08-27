@extends('layouts.admin')

@section('content')
<div style="display:grid; gap:20px;">
    @if(session('success'))<div class="admin-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="admin-error" style="padding:12px 14px; background:#fff1f0; border-radius:8px;">{{ $errors->first() }}</div>@endif

    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div><span class="eyebrow">Quản trị</span><h1 style="margin:0;">Phương thức vận chuyển</h1></div>
        <a class="btn btn-primary" href="{{ route('admin.shipping.create') }}">+ Tạo phương thức mới</a>
    </div>

    <form method="GET" action="{{ route('admin.shipping.index') }}" class="filter-box" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end;">
        <div style="flex:1; min-width:220px;">
            <label for="q">Tìm kiếm</label>
            <input id="q" name="q" value="{{ request('q') }}" placeholder="Nhập tên hoặc mã..." style="width:100%; padding:10px;">
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
            <a class="btn btn-secondary" href="{{ route('admin.shipping.index') }}">Xóa lọc</a>
        @endif
    </form>

    <div class="filter-box" style="overflow-x:auto;">
        <table style="width:100%; min-width:800px; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:10px;">Mã</th>
                    <th style="text-align:left; padding:10px;">Tên</th>
                    <th style="text-align:right; padding:10px;">Phí cơ bản</th>
                    <th style="text-align:right; padding:10px;">Phí/KM</th>
                    <th style="text-align:center; padding:10px;">T.gian (ngày)</th>
                    <th style="text-align:center; padding:10px;">Đơn hàng</th>
                    <th style="text-align:left; padding:10px;">Trạng thái</th>
                    <th style="padding:10px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($methods as $method)
                    <tr>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; font-weight:700; font-family:monospace; letter-spacing:.04em;">{{ $method->code }}</td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb;">{{ $method->name }}</td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; text-align:right;">
                            {{ number_format((float)$method->base_fee, 0, ',', '.') }}đ
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; text-align:right;">
                            {{ number_format((float)$method->fee_per_km, 0, ',', '.') }}đ
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; text-align:center;">
                            @if($method->estimated_days_min || $method->estimated_days_max)
                                {{ $method->estimated_days_min ?? '?' }} - {{ $method->estimated_days_max ?? '?' }}
                            @else
                                <span style="color:#9ca3af;">—</span>
                            @endif
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; text-align:center;">
                            {{ $method->orders_count }}
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb;">
                            @if($method->is_active)
                                <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#d1fae5; color:#065f46; font-size:.8rem;">Hoạt động</span>
                            @else
                                <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#fee2e2; color:#991b1b; font-size:.8rem;">Vô hiệu hóa</span>
                            @endif
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; white-space:nowrap;">
                            <a class="btn btn-secondary" href="{{ route('admin.shipping.edit', $method) }}" style="margin-right:4px;">Sửa</a>
                            @if($method->is_active)
                                <form method="POST" action="{{ route('admin.shipping.deactivate', $method) }}" style="display:inline;">
                                    @csrf
                                    <button class="btn btn-secondary" type="submit">Vô hiệu hóa</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.shipping.activate', $method) }}" style="display:inline;">
                                    @csrf
                                    <button class="btn btn-primary" type="submit">Kích hoạt</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('admin.shipping.destroy', $method) }}" style="display:inline;" onsubmit="return confirm('Xóa phương thức {{ $method->code }}? Hành động không thể hoàn tác.')">
                                @csrf @method('DELETE')
                                <button class="btn btn-secondary" type="submit" style="margin-left:4px; color:#b91c1c;">Xóa</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="padding:18px; text-align:center;">Không có phương thức vận chuyển nào.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $methods->links() }}
</div>
@endsection
