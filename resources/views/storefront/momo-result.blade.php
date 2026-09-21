@extends('layouts.storefront')

@section('content')
    <section class="section-block">
        <div class="container filter-box">
            <h1>Kết quả thanh toán MoMo</h1>
            @switch($state)
                @case('paid')
                    <p>Hệ thống đã xác nhận thanh toán thành công.</p>
                    @break
                @case('pending')
                    <p>Hệ thống đang chờ xác nhận thanh toán. Vui lòng kiểm tra lại trạng thái đơn hàng.</p>
                    @break
                @case('closed')
                    <p>Đơn hàng đã kết thúc. Nếu tài khoản đã bị trừ tiền, vui lòng liên hệ cửa hàng.</p>
                    @break
                @default
                    <p>Chưa thể xác nhận kết quả. Vui lòng kiểm tra trạng thái đơn hàng trong tài khoản.</p>
            @endswitch
            @if($order)
                <p>Đơn hàng: {{ $order->order_number }}</p>
                <a class="btn btn-primary" href="{{ route('order.show', $order) }}">Xem trạng thái đơn hàng</a>
            @else
                <a class="btn btn-secondary" href="{{ route('account.orders') }}">Đơn hàng của tôi</a>
            @endif
        </div>
    </section>
@endsection
