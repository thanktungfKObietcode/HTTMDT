@extends('layouts.storefront')

@section('content')
    @php
        $content = [
            'returns' => ['Chính sách đổi trả', 'Sản phẩm được hỗ trợ đổi trả khi còn nguyên trạng, đầy đủ phụ kiện và đáp ứng điều kiện kiểm tra của Silver Atelier. Vui lòng liên hệ trong thời gian sớm nhất sau khi nhận hàng.'],
            'shipping' => ['Chính sách vận chuyển', 'Đơn hàng được xử lý theo phương thức vận chuyển bạn chọn tại bước thanh toán. Thời gian dự kiến và phí vận chuyển được hiển thị từ dữ liệu phương thức giao hàng hiện có.'],
            'warranty' => ['Chính sách bảo hành', 'Sản phẩm được hỗ trợ bảo hành theo chính sách áp dụng cho từng đơn hàng. Vui lòng giữ thông tin đơn hàng và liên hệ với chúng tôi để được hướng dẫn.'],
        ][$policy];
    @endphp
    <section class="page-hero small-hero"><div class="container"><span class="eyebrow">Hỗ trợ</span><h1>{{ $content[0] }}</h1></div></section>
    <section class="section-block"><div class="container"><article class="filter-box" style="max-width:820px; margin:0 auto;"><p style="margin:0;">{{ $content[1] }}</p><a class="btn btn-secondary" style="margin-top:20px;" href="{{ route('contact') }}">Liên hệ hỗ trợ</a></article></div></section>
@endsection