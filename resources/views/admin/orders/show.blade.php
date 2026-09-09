@extends('layouts.admin')

@section('content')
<div style="display:grid; gap:20px;">
    @if(session('success'))<div class="admin-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="admin-error" style="padding:12px 14px; background:#fff1f0; border-radius:8px;">{{ $errors->first() }}</div>@endif
    <div style="display:flex; justify-content:space-between; gap:16px; align-items:center; flex-wrap:wrap;"><div><span class="eyebrow">Đơn hàng</span><h1 style="margin:0;">{{ $order->order_number }}</h1></div><a class="btn btn-secondary" href="{{ route('admin.orders.index') }}">Về danh sách</a></div>
    <section style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px;"><div class="filter-box"><h3>Khách hàng</h3><p style="margin:0;">{{ $order->customer_name }}</p><p style="margin:4px 0 0;">{{ $order->customer_email ?? $order->user?->email ?? 'Không có email' }}</p><p style="margin:4px 0 0;">{{ $order->customer_phone }}</p></div><div class="filter-box"><h3>Giao hàng</h3><p style="margin:0;">{{ $order->shipping_address }}</p><p style="margin:4px 0 0;">{{ $order->shippingMethod?->name ?? 'Không xác định' }}</p></div><div class="filter-box"><h3>Trạng thái</h3><p style="margin:0;">Đơn: <strong>{{ strtoupper($order->status) }}</strong></p><p style="margin:4px 0 0;">Thanh toán: <strong>{{ strtoupper($order->payment_status) }}</strong></p><p style="margin:4px 0 0;">Tạo: {{ $order->created_at->format('d/m/Y H:i') }}</p><p style="margin:4px 0 0;">Cập nhật: {{ $order->updated_at->format('d/m/Y H:i') }}</p></div></section>
    @if(count($availableTransitions) > 0)<section class="filter-box"><h2 style="margin-top:0;">Cập nhật trạng thái</h2><form method="POST" action="{{ route('admin.orders.status', $order) }}" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end;">@csrf<div><label for="status">Trạng thái tiếp theo</label><select id="status" name="status" required style="padding:10px;">@foreach($availableTransitions as $status)<option value="{{ $status }}">{{ strtoupper($status) }}</option>@endforeach</select></div><div style="flex:1; min-width:220px;"><label for="note">Ghi chú</label><input id="note" name="note" value="{{ old('note') }}" style="width:100%; padding:10px;"></div><button class="btn btn-primary" type="submit">Cập nhật</button></form></section>@endif
    <section class="filter-box" style="overflow-x:auto;"><h2 style="margin-top:0;">Sản phẩm</h2><table style="width:100%; min-width:700px; border-collapse:collapse;"><thead><tr><th style="text-align:left; padding:10px;">Sản phẩm</th><th style="text-align:left; padding:10px;">Variant</th><th style="text-align:center; padding:10px;">SL</th><th style="text-align:right; padding:10px;">Đơn giá</th><th style="text-align:right; padding:10px;">Thành tiền</th></tr></thead><tbody>@foreach($order->items as $item)<tr><td style="padding:10px; border-top:1px solid #e5e7eb;">{{ $item->product_name }}<small style="display:block; color:#6b7280;">{{ $item->sku }}</small></td><td style="padding:10px; border-top:1px solid #e5e7eb;">{{ $item->productVariant?->sku ?? '-' }}</td><td style="padding:10px; border-top:1px solid #e5e7eb; text-align:center;">{{ $item->quantity }}</td><td style="padding:10px; border-top:1px solid #e5e7eb; text-align:right;">{{ number_format((float) $item->unit_price, 0, ',', '.') }}đ</td><td style="padding:10px; border-top:1px solid #e5e7eb; text-align:right;">{{ number_format((float) $item->total_price, 0, ',', '.') }}đ</td></tr>@endforeach</tbody></table><div style="display:grid; gap:6px; margin-top:16px; max-width:360px; margin-left:auto;"><div style="display:flex; justify-content:space-between;"><span>Tạm tính</span><strong>{{ number_format((float) $order->subtotal, 0, ',', '.') }}đ</strong></div><div style="display:flex; justify-content:space-between;"><span>Vận chuyển</span><span>{{ number_format((float) $order->shipping_fee, 0, ',', '.') }}đ</span></div><div style="display:flex; justify-content:space-between;"><span>Giảm giá</span><span>-{{ number_format((float) $order->discount_amount, 0, ',', '.') }}đ</span></div><div style="display:flex; justify-content:space-between; border-top:1px solid #e5e7eb; padding-top:8px;"><span>Tổng</span><strong>{{ number_format((float) $order->total_amount, 0, ',', '.') }}đ</strong></div></div></section>
    @if($order->paymentTransactions->isNotEmpty())
        <section class="filter-box">
            <h2 style="margin-top:0;">Giao dịch thanh toán</h2>
            @foreach($order->paymentTransactions as $transaction)
                <p>{{ $transaction->transaction_id }} · {{ strtoupper($transaction->payment_status) }} · {{ number_format((float) $transaction->amount, 0, ',', '.') }}đ</p>
                @if($transaction->gateway === 'vnpay')
                    <p>Mã VNPay: {{ $transaction->gateway_transaction_id ?? 'Chưa ghi nhận' }}</p>
                    @if($transaction->requiresReconciliation())<p role="status">Cần đối soát — không tự hủy hoặc hoàn tiền.</p>@endif
                    @if(isset($transaction->payload['vnpay_last_query_outcome']))
                        <p>Kết quả đối soát gần nhất: {{ $transaction->payload['vnpay_last_query_outcome'] }}</p>
                    @endif
                    @if(auth()->user()->hasRole('admin') && auth()->user()->hasPermission('orders.update'))
                        <form method="POST" action="{{ route('admin.orders.payments.reconcile', [$order, $transaction]) }}">
                            @csrf
                            <button class="btn btn-secondary" type="submit">Đối soát VNPay</button>
                        </form>
                    @endif
                @endif
            @endforeach
        </section>
    @endif
    @if($order->refunds->isNotEmpty())
        <section class="filter-box" style="display:grid; gap:14px;">
            <h2 style="margin:0;">Yêu cầu hoàn tiền</h2>
            @foreach($order->refunds as $refund)
                <div style="padding:12px; border:1px solid #e5e7eb; border-radius:8px; display:grid; gap:8px;">
                    <p style="margin:0;"><strong>{{ number_format((float) $refund->amount, 2, ',', '.') }}đ</strong> · {{ $refund->status_label }}</p>
                    @if($refund->reason)<p style="margin:0; color:#666;">Lý do: {{ $refund->reason }}</p>@endif
                    @if($refund->requestedBy)<small>Người yêu cầu: {{ $refund->requestedBy->name }}</small>@endif
                    @if($refund->admin_note)<small>Ghi chú quản trị: {{ $refund->admin_note }}</small>@endif

                    @if($refund->status === 'requested')
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <form method="POST" action="{{ route('admin.refunds.approve', $refund) }}" style="display:flex; gap:8px; flex-wrap:wrap;">
                                @csrf
                                <input name="admin_note" placeholder="Ghi chú duyệt" style="padding:8px;">
                                <button class="btn btn-primary" type="submit">Duyệt</button>
                            </form>
                            <form method="POST" action="{{ route('admin.refunds.reject', $refund) }}" style="display:flex; gap:8px; flex-wrap:wrap;">
                                @csrf
                                <input name="admin_note" placeholder="Lý do từ chối" required style="padding:8px;">
                                <button class="btn btn-secondary" type="submit">Từ chối</button>
                            </form>
                        </div>
                    @elseif($refund->status === 'approved')
                        <form method="POST" action="{{ route('admin.refunds.execute', $refund) }}" style="display:flex; gap:8px; flex-wrap:wrap;">
                            @csrf
                            <input name="admin_note" placeholder="Ghi chú thực thi" style="padding:8px;">
                            <button class="btn btn-primary" type="submit">Thực thi hoàn tiền</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </section>
    @endif
    @if($order->statusHistory->isNotEmpty())<section class="filter-box"><h2 style="margin-top:0;">Lịch sử trạng thái</h2><ul style="margin:0; padding-left:20px;">@foreach($order->statusHistory as $history)<li><strong>{{ strtoupper($history->status) }}</strong> · {{ $history->created_at->format('d/m/Y H:i') }} · {{ $history->note }} @if($history->changedBy)· {{ $history->changedBy->name }}@endif</li>@endforeach</ul></section>@endif
</div>
@endsection
