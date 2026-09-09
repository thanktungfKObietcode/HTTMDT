@extends('layouts.storefront')

@section('content')
    <section class="section-block">
        <div class="container filter-box">
            <h1>Kết quả thanh toán VNPay</h1>
            @switch($state)
                @case('paid')
                    <p>Hệ thống đã xác nhận thanh toán thành công.</p>
                    @break
                @case('pending')
                    <p>VNPay đã trả kết quả về trình duyệt, hệ thống đang chờ xác nhận thanh toán. Vui lòng kiểm tra lại trạng thái đơn hàng.</p>
                    @break
                @case('failed')
                    <p>Lần thanh toán này chưa được xác nhận thành công. Vui lòng kiểm tra trạng thái đơn trước khi thanh toán lại.</p>
                    @break
                @case('closed')
                    <p>Đơn hàng đã kết thúc. Nếu tài khoản đã bị trừ tiền, vui lòng liên hệ shop để kiểm tra giao dịch.</p>
                    @break
                @case('unknown')
                    <p>Không tìm thấy giao dịch thanh toán. Vui lòng kiểm tra đơn hàng trong tài khoản của bạn.</p>
                    @break
                @default
                    <p>Không thể xác minh kết quả thanh toán. Vui lòng kiểm tra trạng thái đơn hàng trong tài khoản.</p>
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
