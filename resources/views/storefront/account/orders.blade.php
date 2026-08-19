@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Quản lý tài khoản</span>
            <h1>Đơn hàng của tôi</h1>
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
                    <a href="{{ route('address.index') }}" style="padding:12px 16px; border-bottom:1px solid rgba(31,28,26,0.1); text-decoration:none; color:#1f1c1a;">📍 Địa chỉ</a>
                    <a href="{{ route('account.orders') }}" style="padding:12px 16px; text-decoration:none; color:#1f1c1a; background:rgba(31,28,26,0.05); font-weight:600;">🧾 Đơn hàng</a>
                </nav>
            </aside>

            <div class="filter-box">
                <h2 style="margin-top:0;">Lịch sử đơn hàng</h2>

                @if($orders->isEmpty())
                    <p>Bạn chưa có đơn hàng nào.</p>
                    <a href="{{ route('products.index') }}" class="btn btn-primary">Mua sắm ngay</a>
                @else
                    <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                        <thead>
                            <tr>
                                <th style="text-align:left; padding:10px 8px;">Mã đơn</th>
                                <th style="text-align:left; padding:10px 8px;">Ngày đặt</th>
                                <th style="text-align:right; padding:10px 8px;">Tổng tiền</th>
                                <th style="text-align:left; padding:10px 8px;">Trạng thái</th>
                                <th style="text-align:right; padding:10px 8px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($orders as $order)
                                <tr>
                                    <td style="padding:10px 8px; border-top:1px solid rgba(31,28,26,0.08); font-weight:600;">{{ $order->order_number }}</td>
                                    <td style="padding:10px 8px; border-top:1px solid rgba(31,28,26,0.08);">{{ $order->created_at->format('d/m/Y H:i') }}</td>
                                    <td style="padding:10px 8px; border-top:1px solid rgba(31,28,26,0.08); text-align:right;">{{ number_format((float) $order->total_amount, 0, ',', '.') }}đ</td>
                                    <td style="padding:10px 8px; border-top:1px solid rgba(31,28,26,0.08);">{{ strtoupper($order->status) }}</td>
                                    <td style="padding:10px 8px; border-top:1px solid rgba(31,28,26,0.08); text-align:right;">
                                        <a href="{{ route('order.show', $order) }}" class="btn btn-secondary">Xem chi tiết</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </section>
@endsection
