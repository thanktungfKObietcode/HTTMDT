@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero"><div class="container"><span class="eyebrow">Cửa hàng</span><h1>Showroom</h1></div></section>
    <section class="section-block"><div class="container" style="display:grid; gap:18px;">
        @forelse ($showrooms as $showroom)
            <article class="filter-box"><h2>{{ $showroom->name }}</h2><p>{{ $showroom->city }}<br>{{ $showroom->address }}</p>@if($showroom->phone)<p>Điện thoại: {{ $showroom->phone }}</p>@endif @if($showroom->email)<p>Email: {{ $showroom->email }}</p>@endif @if($showroom->business_hours)<p>Giờ mở cửa: {{ collect($showroom->business_hours)->map(fn ($value, $key) => $key . ': ' . $value)->join(' • ') }}</p>@endif @if($showroom->map_url)<a class="btn btn-secondary" href="{{ $showroom->map_url }}" target="_blank" rel="noopener">Xem bản đồ</a>@endif</article>
        @empty
            <div class="filter-box"><h3>Chưa có showroom.</h3><p style="margin:0;">Thông tin cửa hàng sẽ được cập nhật tại đây.</p></div>
        @endforelse
    </div></section>
@endsection