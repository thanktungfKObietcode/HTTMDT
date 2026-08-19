@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Danh sách yêu thích</span>
            <h1>Yêu thích</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; gap:24px;">
            @if (!$isAuthenticated)
                <div class="filter-box">
                    <h3>Vui lòng đăng nhập để xem danh sách yêu thích.</h3>
                    <p>Tạo tài khoản hoặc đăng nhập để lưu sản phẩm yêu thích của bạn.</p>
                    <div style="display:flex; gap:12px; margin-top:16px; flex-wrap:wrap;">
                        <a href="{{ route('login') }}" class="btn btn-primary">Đăng nhập</a>
                        <a href="{{ route('register') }}" class="btn btn-secondary">Tạo tài khoản</a>
                    </div>
                </div>
            @elseif ($items->isEmpty())
                <div class="filter-box">
                    <h3>Danh sách yêu thích của bạn trống.</h3>
                    <p>Hãy khám phá sản phẩm và thêm chúng vào danh sách yêu thích của bạn.</p>
                    <a href="{{ route('products.index') }}" class="btn btn-primary" style="display:inline-block; margin-top:16px;">Khám phá sản phẩm</a>
                </div>
            @else
                <div class="product-grid">
                    @foreach ($items as $item)
                        @php
                            $product = $item->product;
                            $image = $product->featured_image ?? 'https://images.unsplash.com/photo-1617038220319-276d3cfab638?auto=format&fit=crop&w=900&q=80';
                            $salePrice = (float) ($product->sale_price ?? 0);
                            $price = (float) ($product->price ?? 0);
                            $displayPrice = $salePrice > 0 ? $salePrice : $price;
                            $comparePrice = $salePrice > 0 ? $price : null;
                        @endphp

                        <article class="product-card">
                            <div class="product-media">
                                <img src="{{ $image }}" alt="{{ $product->name }}">
                                @if ($salePrice > 0)
                                    <span class="badge badge-sale">Sale</span>
                                @endif
                                <form method="POST" action="{{ route('wishlist.remove', $product->id) }}" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="wishlist-btn active" aria-label="Remove from wishlist" title="Xóa khỏi yêu thích">♥</button>
                                </form>
                            </div>
                            <div class="product-info">
                                <span class="product-category">{{ $product->category->name ?? 'Trang sức bạc' }}</span>
                                <h3><a href="{{ route('product.show', $product->slug) }}">{{ $product->name }}</a></h3>
                                <div class="rating-row">
                                    <span>★★★★★</span>
                                    <small>{{ number_format($product->average_rating ?? 0, 1) }}</small>
                                </div>
                                <div class="price-row">
                                    <strong>{{ number_format($displayPrice, 0, ',', '.') }}đ</strong>
                                    @if ($comparePrice)
                                        <span>{{ number_format($comparePrice, 0, ',', '.') }}đ</span>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endsection
