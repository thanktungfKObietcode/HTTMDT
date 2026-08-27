@extends('layouts.admin')

@section('content')
<div style="display:grid; gap:20px;">
    @if(session('success'))<div class="admin-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="admin-error" style="padding:12px 14px; background:#fff1f0; border-radius:8px;">{{ $errors->first() }}</div>@endif

    <div style="display:flex; justify-content:space-between; gap:16px; align-items:center; flex-wrap:wrap;">
        <div><span class="eyebrow">Người dùng</span><h1 style="margin:0;">{{ $user->name }}</h1></div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            @if($user->is_active)
                <form method="POST" action="{{ route('admin.users.deactivate', $user) }}">
                    @csrf
                    <button class="btn btn-secondary" type="submit"
                        onclick="return confirm('Vô hiệu hóa người dùng này?')">Vô hiệu hóa</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.users.activate', $user) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit"
                        onclick="return confirm('Kích hoạt lại người dùng này?')">Kích hoạt</button>
                </form>
            @endif
            <a class="btn btn-secondary" href="{{ route('admin.users.index') }}">Về danh sách</a>
        </div>
    </div>

    <section style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px;">
        <div class="filter-box">
            <h3 style="margin-top:0;">Thông tin cơ bản</h3>
            <p style="margin:4px 0;"><strong>Email:</strong> {{ $user->email }}</p>
            <p style="margin:4px 0;"><strong>Số điện thoại:</strong> {{ $user->phone ?? '—' }}</p>
            <p style="margin:4px 0;"><strong>Giới tính:</strong> {{ $user->gender ?? '—' }}</p>
            <p style="margin:4px 0;"><strong>Ngày sinh:</strong> {{ $user->birth_date?->format('d/m/Y') ?? '—' }}</p>
            <p style="margin:4px 0;"><strong>Ngày tạo:</strong> {{ $user->created_at->format('d/m/Y H:i') }}</p>
            <p style="margin:4px 0;"><strong>Đăng nhập lần cuối:</strong> {{ $user->last_login_at?->format('d/m/Y H:i') ?? '—' }}</p>
        </div>
        <div class="filter-box">
            <h3 style="margin-top:0;">Vai trò & Trạng thái</h3>
            <p style="margin:4px 0;"><strong>Vai trò:</strong>
                @forelse($user->roles as $role)
                    <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#e0e7ff; color:#3730a3; font-size:.8rem;">{{ ucfirst($role->name) }}</span>
                @empty
                    <span style="color:#9ca3af;">—</span>
                @endforelse
            </p>
            <p style="margin:4px 0;"><strong>Trạng thái:</strong>
                @if($user->is_active)
                    <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#d1fae5; color:#065f46; font-size:.8rem;">Hoạt động</span>
                @else
                    <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#fee2e2; color:#991b1b; font-size:.8rem;">Vô hiệu hóa</span>
                @endif
            </p>
            <p style="margin:4px 0;"><strong>Nhận newsletter:</strong> {{ $user->receive_newsletter ? 'Có' : 'Không' }}</p>
        </div>
        <div class="filter-box">
            <h3 style="margin-top:0;">Thống kê</h3>
            <p style="margin:4px 0;"><strong>Đơn hàng:</strong> {{ $user->orders->count() }}</p>
            <p style="margin:4px 0;"><strong>Tổng chi tiêu:</strong> {{ number_format($orderTotal, 0, ',', '.') }}đ</p>
            <p style="margin:4px 0;"><strong>Đánh giá:</strong> {{ $user->reviews->count() }}</p>
            <p style="margin:4px 0;"><strong>Địa chỉ đang dùng:</strong> {{ $user->addresses->count() }}</p>
        </div>
    </section>

    @if($user->addresses->isNotEmpty())
        <section class="filter-box">
            <h2 style="margin-top:0;">Địa chỉ</h2>
            @foreach($user->addresses as $address)
                <div style="padding:10px 0; border-top:1px solid #e5e7eb;">
                    <strong>{{ $address->full_name }}</strong> · {{ $address->phone }}<br>
                    {{ $address->address_line }}, {{ $address->ward }}, {{ $address->district }}, {{ $address->province }}
                    @if($address->is_default) <span style="font-size:.8rem; color:#065f46;">(Mặc định)</span>@endif
                </div>
            @endforeach
        </section>
    @endif

    @if($user->orders->isNotEmpty())
        <section class="filter-box" style="overflow-x:auto;">
            <h2 style="margin-top:0;">Đơn hàng gần đây</h2>
            <table style="width:100%; min-width:600px; border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left; padding:10px;">Mã đơn</th>
                        <th style="text-align:right; padding:10px;">Tổng tiền</th>
                        <th style="text-align:left; padding:10px;">Thanh toán</th>
                        <th style="text-align:left; padding:10px;">Trạng thái</th>
                        <th style="text-align:left; padding:10px;">Ngày tạo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($user->orders->take(10) as $order)
                        <tr>
                            <td style="padding:10px; border-top:1px solid #e5e7eb; font-weight:600;">
                                <a href="{{ route('admin.orders.show', $order) }}">{{ $order->order_number }}</a>
                            </td>
                            <td style="padding:10px; border-top:1px solid #e5e7eb; text-align:right;">{{ number_format((float) $order->total_amount, 0, ',', '.') }}đ</td>
                            <td style="padding:10px; border-top:1px solid #e5e7eb;">{{ strtoupper($order->payment_status) }}</td>
                            <td style="padding:10px; border-top:1px solid #e5e7eb;">{{ strtoupper($order->status) }}</td>
                            <td style="padding:10px; border-top:1px solid #e5e7eb;">{{ $order->created_at->format('d/m/Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @if($user->reviews->isNotEmpty())
        <section class="filter-box">
            <h2 style="margin-top:0;">Đánh giá sản phẩm</h2>
            @foreach($user->reviews as $review)
                <div style="padding:10px 0; border-top:1px solid #e5e7eb;">
                    <strong>{{ $review->product?->name ?? 'Sản phẩm đã xóa' }}</strong>
                    · <span>{{ $review->rating }}/5</span>
                    <p style="margin:4px 0; color:#374151;">{{ $review->comment }}</p>
                    <small style="color:#6b7280;">{{ $review->created_at->format('d/m/Y H:i') }}</small>
                </div>
            @endforeach
        </section>
    @endif
</div>
@endsection
