@extends('layouts.storefront')

@section('content')
    @php
        $detailImage = $product->featured_image ?? 'https://images.unsplash.com/photo-1601821765780-3bf8f25f4d74?auto=format&fit=crop&w=900&q=80';
        $salePrice = (float) ($product->sale_price ?? 0);
        $price = (float) ($product->price ?? 0);
        $displayPrice = $salePrice > 0 ? $salePrice : $price;
        $comparePrice = $salePrice > 0 ? $price : null;
    @endphp

    <section class="section-block product-detail">
        <div class="container product-detail-grid">
            @if ($errors->any() || session('error'))
                <div style="grid-column:1 / -1; padding:12px 14px; border-radius:8px; background:#ffe8e8; color:#8a1f1f;">
                    @if (session('error'))
                        <p style="margin:0;">{{ session('error') }}</p>
                    @endif
                    @if ($errors->any())
                        <ul style="margin:0; padding-left:18px;">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="gallery-panel">
                <div class="main-gallery">
                    <img src="{{ $detailImage }}" alt="{{ $product->name }}">
                </div>
                <div class="thumb-row">
                    @forelse ($images as $image)
                        <img src="{{ $image->image_path }}" alt="{{ $product->name }} thumbnail">
                    @empty
                        <img src="{{ $detailImage }}" alt="{{ $product->name }} thumbnail">
                        <img src="https://images.unsplash.com/photo-1617038220319-276d3cfab638?auto=format&fit=crop&w=600&q=80" alt="thumbnail 2">
                        <img src="https://images.unsplash.com/photo-1573408301185-9146fe634ad0?auto=format&fit=crop&w=600&q=80" alt="thumbnail 3">
                    @endforelse
                </div>
            </div>

            <div class="detail-panel">
                <span class="eyebrow">{{ $product->category->name ?? 'Trang sức bạc' }}</span>
                <h1>{{ $product->name }}</h1>
                <div class="rating-row detail-rating">
                    <span>★★★★★</span>
                    <small>{{ number_format($averageRating ?? ($product->average_rating ?? 0), 1) }} • {{ $product->reviews()->count() }} đánh giá</small>
                </div>

                <div class="price-row detail-price">
                    <strong>{{ number_format($displayPrice, 0, ',', '.') }}đ</strong>
                    @if ($comparePrice)
                        <span>{{ number_format($comparePrice, 0, ',', '.') }}đ</span>
                    @endif
                </div>

                <p class="product-summary">{{ $product->short_description ?? 'Những thiết kế bạc thanh lịch, bền đẹp và phù hợp cho mọi phong cách.' }}</p>

                <div class="variant-block">
                    <span class="label">Chất liệu</span>
                    <div class="option-pills">
                        <button type="button" class="active">{{ $product->material->name ?? 'Bạc 925' }}</button>
                    </div>
                </div>

                @if ($variants->isNotEmpty())
                    <div class="variant-block">
                        <span class="label">Phiên bản</span>
                        <div class="option-pills">
                            @foreach ($variants as $variant)
                                <label class="option-pill">
                                    <input type="radio" name="product_variant_id" value="{{ $variant->id }}" form="add-to-cart-form" {{ $loop->first ? 'checked' : '' }} required>
                                    <span>{{ $variant->size ?? $variant->color ?? $variant->metal_type ?? 'Phiên bản ' . $loop->iteration }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="purchase-actions">
                    <form id="add-to-cart-form" method="POST" action="{{ route('cart.add') }}" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                        <label for="quantity" class="sr-only">Số lượng</label>
                        <input id="quantity" type="number" name="quantity" value="1" min="1" max="{{ $variants->isNotEmpty() ? $variants->max('stock') : $product->stock }}" required style="width:72px; padding:12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                        <button type="submit" class="btn btn-primary large">Thêm vào giỏ hàng</button>
                    </form>
                    @if (auth()->check())
                        @php
                            $inWishlist = auth()->user()->wishlist()->where('product_id', $product->id)->exists();
                        @endphp
                        @if ($inWishlist)
                            <form method="POST" action="{{ route('wishlist.remove', $product->id) }}" style="display:inline;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-secondary large">♥ Đã thêm vào yêu thích</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('wishlist.add') }}" style="display:inline;">
                                @csrf
                                <input type="hidden" name="product_id" value="{{ $product->id }}">
                                <button type="submit" class="btn btn-secondary large">♡ Yêu thích</button>
                            </form>
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="btn btn-secondary large">♡ Yêu thích</a>
                    @endif
                </div>

                <ul class="detail-meta">
                    <li>Miễn phí vận chuyển đơn từ 1.000.000đ</li>
                    <li>Bảo hành 12 tháng</li>
                    <li>Giao hàng trong 48 giờ</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="section-block extra-section">
        <div class="container details-tabs">
            <div class="tab-header">
                <span class="active">Mô tả</span>
                <span>Thông số</span>
                <span>Đánh giá</span>
            </div>

            <div class="tab-content">
                <p>{{ $product->description ?? 'Sản phẩm được chế tác từ bạc 925, với bề mặt mài bóng tinh xảo...' }}</p>

                @if ($specifications->isNotEmpty())
                    <ul>
                        @foreach ($specifications as $specification)
                            <li>{{ $specification->label }}: {{ $specification->value }}</li>
                        @endforeach
                    </ul>
                @else
                    <ul>
                        <li>Nguyên liệu: {{ $product->material->name ?? 'Bạc 925' }}</li>
                        <li>Phù hợp: Mỗi ngày, tiệc tối, quà tặng ý nghĩa</li>
                        <li>Thiết kế: Tối giản, thanh lịch, dễ phối đồ</li>
                    </ul>
                @endif
            </div>
        </div>
    </section>

    <section class="section-block">
        <div class="container section-heading">
            <div>
                <span class="eyebrow">Đánh giá khách hàng</span>
                <h2>Khám phá thêm</h2>
            </div>
        </div>

        <div class="container" style="display:grid; gap:18px;">
            @forelse ($reviews as $review)
                <article class="filter-box" style="padding:18px 20px;">
                    <div class="rating-row" style="margin-bottom:8px;">
                        <span>★★★★★</span>
                        <small>{{ $review->rating }}/5</small>
                    </div>
                    <p style="margin:0 0 6px; font-weight:600;">{{ $review->user->name ?? 'Khách hàng' }}</p>
                    <p style="margin:0; color:#6c625d;">{{ $review->comment }}</p>
                </article>
            @empty
                <div class="filter-box">
                    <p>Chưa có đánh giá nào cho sản phẩm này.</p>
                </div>
            @endforelse
        </div>
    </section>

    <section class="section-block">
        <div class="container section-heading">
            <div>
                <span class="eyebrow">Sản phẩm tương tự</span>
                <h2>Khám phá thêm</h2>
            </div>
        </div>

        <div class="container product-grid">
            @foreach ($relatedProducts as $relatedProduct)
                @php
                    $relatedImage = $relatedProduct->featured_image ?? 'https://images.unsplash.com/photo-1601821765780-3bf8f25f4d74?auto=format&fit=crop&w=900&q=80';
                    $relatedSalePrice = (float) ($relatedProduct->sale_price ?? 0);
                    $relatedPrice = (float) ($relatedProduct->price ?? 0);
                    $relatedDisplayPrice = $relatedSalePrice > 0 ? $relatedSalePrice : $relatedPrice;
                @endphp
                <article class="product-card">
                    <div class="product-media">
                        <img src="{{ $relatedImage }}" alt="{{ $relatedProduct->name }}">
                        @if (auth()->check())
                            @php
                                $relatedInWishlist = auth()->user()->wishlist()->where('product_id', $relatedProduct->id)->exists();
                            @endphp
                            @if ($relatedInWishlist)
                                <form method="POST" action="{{ route('wishlist.remove', $relatedProduct->id) }}" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="wishlist-btn active" aria-label="Remove from wishlist" title="Xóa khỏi yêu thích">♥</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('wishlist.add') }}" style="display:inline;">
                                    @csrf
                                    <input type="hidden" name="product_id" value="{{ $relatedProduct->id }}">
                                    <button type="submit" class="wishlist-btn" aria-label="Add to wishlist" title="Thêm vào yêu thích">♡</button>
                                </form>
                            @endif
                        @else
                            <a href="{{ route('login') }}" class="wishlist-btn" aria-label="Add to wishlist" title="Đăng nhập để thêm vào yêu thích">♡</a>
                        @endif
                    </div>
                    <div class="product-info">
                        <span class="product-category">{{ $relatedProduct->category->name ?? 'Trang sức bạc' }}</span>
                        <h3><a href="{{ route('product.show', $relatedProduct->slug) }}">{{ $relatedProduct->name }}</a></h3>
                        <div class="price-row">
                            <strong>{{ number_format($relatedDisplayPrice, 0, ',', '.') }}đ</strong>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endsection
