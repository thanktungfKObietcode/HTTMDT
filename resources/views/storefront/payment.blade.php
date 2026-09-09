@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Thanh toán</span>
            <h1>Thanh toán đơn {{ $order->order_number }}</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; grid-template-columns:minmax(0, 1fr) minmax(300px, 380px); gap:24px; align-items:start;">
            <div class="filter-box" style="display:grid; gap:12px;">
                <h3 style="margin:0;">Thông tin thanh toán</h3>
                <p style="margin:0; color:#666;">Phương thức: <strong>{{ strtoupper($order->payment_method ?? 'cod') }}</strong></p>
                <p style="margin:0; color:#666;">Trạng thái hiện tại: <strong>{{ strtoupper($order->payment_status) }}</strong></p>
                <p style="margin:0; color:#666;">Mã giao dịch: <strong>{{ $transaction?->transaction_id ?? 'Chưa tạo' }}</strong></p>
                <p style="margin:0; color:#666;">Gateway: <strong>{{ strtoupper($transaction?->gateway ?? $order->payment_method) }}</strong></p>
                <p style="margin:0; color:#666;">Số tiền cần thanh toán: <strong>{{ number_format((float) $order->total_amount, 0, ',', '.') }}đ</strong></p>

                <div style="padding:12px; border-radius:8px; background:#f7f5f2; color:#4e463f;">
                    <strong>Ghi chú:</strong><br>
                    @if($order->payment_method === 'vnpay')
                        Thanh toán VNPay Sandbox dùng cho thử nghiệm. Trạng thái được cập nhật sau khi hệ thống nhận xác nhận thanh toán.
                    @else
                        Đơn COD được ghi nhận đã thanh toán khi giao hàng thành công.
                    @endif
                </div>

                @if($canPayVnPay)
                    <form method="POST" action="{{ route('vnpay.initiate', $order) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">Thanh toán lại bằng VNPay</button>
                    </form>
                @endif

                <a href="{{ route('order.show', $order) }}" class="btn btn-secondary" style="width:max-content;">Quay lại chi tiết đơn</a>
            </div>

            <aside class="filter-box" style="display:grid; gap:10px;">
                <h3 style="margin:0;">Tóm tắt đơn</h3>
                <div style="display:flex; justify-content:space-between;"><span>Tạm tính</span><strong>{{ number_format((float) $order->subtotal, 0, ',', '.') }}đ</strong></div>
                <div style="display:flex; justify-content:space-between;"><span>Vận chuyển</span><span>{{ number_format((float) $order->shipping_fee, 0, ',', '.') }}đ</span></div>
                <div style="display:flex; justify-content:space-between;"><span>Giảm giá</span><span>-{{ number_format((float) $order->discount_amount, 0, ',', '.') }}đ</span></div>
                <div style="display:flex; justify-content:space-between; border-top:1px solid rgba(31,28,26,0.12); padding-top:8px;"><span>Tổng thanh toán</span><strong>{{ number_format((float) $order->total_amount, 0, ',', '.') }}đ</strong></div>
            </aside>
        </div>
    </section>
@endsection
