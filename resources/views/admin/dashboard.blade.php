@extends('layouts.admin')

@section('content')
    <div style="display:grid; gap:24px;">
        <div>
            <span class="eyebrow">Tổng quan</span>
            <h1 style="margin:0;">Dashboard</h1>
        </div>

        <section style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px;">
            <article class="filter-box"><small>Sản phẩm đang bán</small><h2 style="margin:8px 0 0;">{{ number_format($stats['products']) }}</h2></article>
            <article class="filter-box"><small>Người dùng</small><h2 style="margin:8px 0 0;">{{ number_format($stats['users']) }}</h2></article>
            <article class="filter-box"><small>Tổng đơn hàng</small><h2 style="margin:8px 0 0;">{{ number_format($stats['orders']) }}</h2></article>
            <article class="filter-box"><small>Doanh thu đã thanh toán</small><h2 style="margin:8px 0 0;">{{ number_format($stats['revenue'], 0, ',', '.') }}đ</h2></article>
            <article class="filter-box"><small>Đơn chờ xử lý</small><h2 style="margin:8px 0 0;">{{ number_format($stats['pending_orders']) }}</h2></article>
            <article class="filter-box"><small>Đang xử lý / giao</small><h2 style="margin:8px 0 0;">{{ number_format($stats['processing_orders']) }}</h2></article>
        </section>

        <section class="filter-box" style="overflow-x:auto;">
            <h2 style="margin-top:0;">Đơn hàng gần đây</h2>
            @if ($recentOrders->isEmpty())
                <p style="margin:0; color:#6b7280;">Chưa có đơn hàng.</p>
            @else
                <table style="width:100%; border-collapse:collapse; min-width:720px;">
                    <thead><tr><th style="text-align:left; padding:10px 8px;">Mã đơn</th><th style="text-align:left; padding:10px 8px;">Khách hàng</th><th style="text-align:right; padding:10px 8px;">Tổng tiền</th><th style="text-align:left; padding:10px 8px;">Thanh toán</th><th style="text-align:left; padding:10px 8px;">Trạng thái</th><th style="text-align:left; padding:10px 8px;">Ngày tạo</th></tr></thead>
                    <tbody>
                        @foreach ($recentOrders as $order)
                            <tr>
                                <td style="padding:10px 8px; border-top:1px solid #e5e7eb; font-weight:600;">{{ $order->order_number }}</td>
                                <td style="padding:10px 8px; border-top:1px solid #e5e7eb;">{{ $order->user?->name ?? $order->customer_name }}</td>
                                <td style="padding:10px 8px; border-top:1px solid #e5e7eb; text-align:right;">{{ number_format((float) $order->total_amount, 0, ',', '.') }}đ</td>
                                <td style="padding:10px 8px; border-top:1px solid #e5e7eb;">{{ strtoupper($order->payment_status) }}</td>
                                <td style="padding:10px 8px; border-top:1px solid #e5e7eb;">{{ strtoupper($order->status) }}</td>
                                <td style="padding:10px 8px; border-top:1px solid #e5e7eb;">{{ $order->created_at->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
@endsection