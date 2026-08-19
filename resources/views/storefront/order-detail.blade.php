@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Đơn hàng</span>
            <h1>Đơn {{ $order->order_number }}</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; gap:20px;">
            <div class="filter-box" style="display:grid; gap:14px;">
                <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                    <h3 style="margin:0;">Chi tiết đơn hàng</h3>
                    <span style="padding:6px 10px; border-radius:99px; background:#f2f2f2; font-weight:600;">{{ strtoupper($order->status) }}</span>
                </div>
                <p style="margin:0; color:#666;">Ngày đặt: {{ $order->created_at->format('d/m/Y H:i') }}</p>
                <p style="margin:0; color:#666;">Phương thức thanh toán: {{ strtoupper($order->payment_method ?? 'cod') }}</p>
                <p style="margin:0; color:#666;">Trạng thái thanh toán: {{ strtoupper($order->payment_status) }}</p>
            </div>

            <div class="filter-box" style="display:grid; gap:8px;">
                <h3 style="margin:0;">Thông tin giao hàng</h3>
                <p style="margin:0;"><strong>{{ $order->customer_name }}</strong> - {{ $order->customer_phone }}</p>
                @if($order->customer_email)
                    <p style="margin:0;">{{ $order->customer_email }}</p>
                @endif
                <p style="margin:0;">{{ $order->shipping_address }}</p>
                <p style="margin:0; color:#666;">Đơn vị vận chuyển: {{ $order->shippingMethod?->name ?? 'Không xác định' }}</p>
            </div>

            <div class="filter-box" style="display:grid; gap:10px;">
                <h3 style="margin:0;">Thanh toán</h3>
                <p style="margin:0; color:#666;">Phương thức: <strong>{{ strtoupper($order->payment_method ?? 'cod') }}</strong></p>
                <p style="margin:0; color:#666;">Trạng thái: <strong>{{ strtoupper($order->payment_status) }}</strong></p>

                @php
                    $latestTx = $order->paymentTransactions->first();
                @endphp
                @if($latestTx)
                    <p style="margin:0; color:#666;">Mã giao dịch: <strong>{{ $latestTx->transaction_id }}</strong></p>
                    <p style="margin:0; color:#666;">Gateway: <strong>{{ strtoupper($latestTx->gateway) }}</strong></p>
                    <p style="margin:0; color:#666;">Số tiền: <strong>{{ number_format((float) $latestTx->amount, 0, ',', '.') }}đ</strong></p>
                @endif

                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:4px;">
                    <a href="{{ route('payment.show', $order) }}" class="btn btn-secondary">Xem payment</a>

                    @if(in_array($order->status, ['pending', 'confirmed'], true))
                        <form method="POST" action="{{ route('order.cancel', $order) }}">
                            @csrf
                            <button type="submit" class="btn btn-link" style="color:#d84315;">Hủy đơn</button>
                        </form>
                    @endif

                    @if($order->status === 'shipped')
                        <form method="POST" action="{{ route('order.delivered', $order) }}">
                            @csrf
                            <button type="submit" class="btn btn-secondary">Xác nhận đã nhận hàng</button>
                        </form>
                    @endif
                </div>

                @if(in_array($order->payment_status, ['paid', 'refunded'], true))
                    <form method="POST" action="{{ route('payment.refund', $order) }}" style="display:grid; gap:8px; margin-top:8px;">
                        @csrf
                        <label style="font-weight:600;">Yêu cầu hoàn tiền</label>
                        <input type="number" min="1" step="0.01" name="amount" placeholder="Số tiền hoàn" style="padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                        <input type="text" name="reason" placeholder="Lý do" style="padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                        <button type="submit" class="btn btn-secondary" style="width:max-content;">Gửi yêu cầu hoàn tiền</button>
                        @error('refund')<small style="color:#c62828;">{{ $message }}</small>@enderror
                    </form>
                @endif
            </div>

            <div class="filter-box">
                <h3 style="margin-top:0;">Sản phẩm</h3>
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left; padding:8px;">Sản phẩm</th>
                            <th style="text-align:center; padding:8px;">SL</th>
                            <th style="text-align:right; padding:8px;">Đơn giá</th>
                            <th style="text-align:right; padding:8px;">Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($order->items as $item)
                            <tr>
                                <td style="padding:10px 8px; border-top:1px solid rgba(31,28,26,0.08);">
                                    <strong>{{ $item->product_name }}</strong>
                                    <small style="display:block; color:#666;">SKU: {{ $item->sku }}</small>
                                </td>
                                <td style="text-align:center; padding:10px 8px; border-top:1px solid rgba(31,28,26,0.08);">{{ $item->quantity }}</td>
                                <td style="text-align:right; padding:10px 8px; border-top:1px solid rgba(31,28,26,0.08);">{{ number_format((float) $item->unit_price, 0, ',', '.') }}đ</td>
                                <td style="text-align:right; padding:10px 8px; border-top:1px solid rgba(31,28,26,0.08);">{{ number_format((float) $item->total_price, 0, ',', '.') }}đ</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div style="display:grid; gap:6px; margin-top:12px;">
                    <div style="display:flex; justify-content:space-between;"><span>Tạm tính</span><strong>{{ number_format((float) $order->subtotal, 0, ',', '.') }}đ</strong></div>
                    <div style="display:flex; justify-content:space-between;"><span>Phí vận chuyển</span><span>{{ number_format((float) $order->shipping_fee, 0, ',', '.') }}đ</span></div>
                    <div style="display:flex; justify-content:space-between;"><span>Giảm giá</span><span>-{{ number_format((float) $order->discount_amount, 0, ',', '.') }}đ</span></div>
                    <div style="display:flex; justify-content:space-between; border-top:1px solid rgba(31,28,26,0.1); padding-top:8px;"><span>Tổng thanh toán</span><strong>{{ number_format((float) $order->total_amount, 0, ',', '.') }}đ</strong></div>
                </div>
            </div>

            @if($order->statusHistory->isNotEmpty())
                <div class="filter-box">
                    <h3 style="margin-top:0;">Lịch sử trạng thái</h3>
                    <ul style="margin:0; padding-left:18px; display:grid; gap:6px;">
                        @foreach($order->statusHistory as $history)
                            <li>
                                <strong>{{ strtoupper($history->status) }}</strong>
                                <small style="color:#666;">- {{ $history->created_at->format('d/m/Y H:i') }}</small>
                                @if($history->note)
                                    <div style="color:#666;">{{ $history->note }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <a class="btn btn-secondary" href="{{ route('account.orders') }}">Về danh sách đơn hàng</a>
            </div>
        </div>
    </section>
@endsection
