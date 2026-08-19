@extends('layouts.storefront')

@section('content')
    @php
        $homeBanner = $banners->first();
    @endphp

    <section class="hero-section">
        <div class="container hero-grid">
            <div class="hero-copy">
                <span class="eyebrow">Bộ sưu tập mới</span>
                <h1>Trang sức bạc tinh xảo cho những khoảnh khắc quý giá.</h1>
                <p>Mỗi thiết kế được chọn lọc với sự tỉ mỉ, mang lại vẻ đẹp thanh lịch, sang trọng và đầy cảm hứng cho phong cách của bạn.</p>
                <div class="hero-actions">
                    <a href="{{ url('/san-pham') }}" class="btn btn-primary">Khám phá ngay</a>
                    <a href="#story" class="btn btn-secondary">Tìm hiểu thêm</a>
                </div>
                <ul class="hero-stats">
                    <li><strong>18K+</strong><span>Khách hàng tin tưởng</span></li>
                    <li><strong>4.9/5</strong><span>Đánh giá</span></li>
                    <li><strong>48h</strong><span>Giao hàng</span></li>
                </ul>
            </div>

            <div class="hero-visual">
                <div class="hero-card hero-card-main">
                    @if ($homeBanner?->link)
                        <a href="{{ $homeBanner->link }}">
                    @endif
                    <img src="{{ $homeBanner?->image ?? 'https://images.unsplash.com/photo-1617038220319-276d3cfab638?auto=format&fit=crop&w=900&q=80' }}" alt="{{ $homeBanner?->title ?? 'Jewelry collection' }}">
                    @if ($homeBanner?->link)
                        </a>
                    @endif
                    <div class="floating-note note-top">
                        <span>Silver 925</span>
                        <strong>New Arrival</strong>
                    </div>
                    <div class="floating-note note-bottom">
                        <span>Đa dạng mẫu</span>
                        <strong>Chất lượng cao</strong>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="category-strip">
        <div class="container category-grid">
            @foreach ($categories as $category)
                <a href="{{ url('/san-pham') }}?category={{ urlencode($category->slug ?? $category->name) }}" class="category-card">
                    <span class="category-icon">✦</span>
                    <strong>{{ $category->name }}</strong>
                    <small>Khám phá ngay</small>
                </a>
            @endforeach
        </div>
    </section>

    <section class="section-block">
        <div class="container section-heading">
            <div>
                <span class="eyebrow">Sản phẩm nổi bật</span>
                <h2>Giá trị bạc tinh tế</h2>
            </div>
            <a href="{{ url('/san-pham') }}" class="text-link">Xem tất cả</a>
        </div>

        <div class="container product-grid">
            @foreach ($featuredProducts as $product)
                @php
                    $image = $product->featured_image ?? $product->image ?? 'https://images.unsplash.com/photo-1601821765780-3bf8f25f4d74?auto=format&fit=crop&w=900&q=80';
                    $salePrice = (float) ($product->sale_price ?? 0);
                    $price = (float) ($product->price ?? 0);
                    $displayPrice = $salePrice > 0 ? $salePrice : $price;
                    $comparePrice = $salePrice > 0 ? $price : null;
                @endphp

                <article class="product-card">
                    <div class="product-media">
                        <img src="{{ $image }}" alt="{{ $product->name }}">
                        @if ($salePrice > 0)
                            <span class="badge badge-sale">Giảm giá</span>
                        @elseif ($product->featured ?? false)
                            <span class="badge badge-featured">Hot</span>
                        @endif
                        @if (auth()->check())
                            @php
                                $inWishlist = auth()->user()->wishlist()->where('product_id', $product->id)->exists();
                            @endphp
                            @if ($inWishlist)
                                <form method="POST" action="{{ route('wishlist.remove', $product->id) }}" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="wishlist-btn active" aria-label="Remove from wishlist" title="Xóa khỏi yêu thích">♥</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('wishlist.add') }}" style="display:inline;">
                                    @csrf
                                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                                    <button type="submit" class="wishlist-btn" aria-label="Add to wishlist" title="Thêm vào yêu thích">♡</button>
                                </form>
                            @endif
                        @else
                            <a href="{{ route('login') }}" class="wishlist-btn" aria-label="Add to wishlist" title="Đăng nhập để thêm vào yêu thích">♡</a>
                        @endif
                    </div>
                    <div class="product-info">
                        <span class="product-category">{{ $product->category->name ?? 'Trang sức bạc' }}</span>
                        <h3><a href="{{ route('product.show', $product->slug) }}">{{ $product->name }}</a></h3>
                        <div class="rating-row">
                            <span>★★★★★</span>
                            <small>{{ number_format($product->average_rating ?? 4.9, 1) }}</small>
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
    </section>

    <section class="promo-banner" id="collection">
        <div class="container promo-inner">
            <div>
                <span class="eyebrow">Bộ sưu tập mùa mới</span>
                <h2>Collection “Moonlight Silver”</h2>
                <p>Những thiết kế bạc mỏng nhẹ, sáng bóng và mang hơi thở của ánh trăng, phù hợp cho phong cách tối giản nhưng vô cùng sang trọng.</p>
                <a href="{{ url('/san-pham') }}" class="btn btn-primary">Xem bộ sưu tập</a>
            </div>
            <div class="promo-visual">
                <img src="https://images.unsplash.com/photo-1535632787350-4e68ef0ac584?auto=format&fit=crop&w=900&q=80" alt="Moonlight Silver collection">
            </div>
        </div>
    </section>

    <section class="section-block story-block" id="story">
        <div class="container story-grid">
            <div class="story-visual">
                <img src="https://images.unsplash.com/photo-1611652022419-a9419f74343d?auto=format&fit=crop&w=900&q=80" alt="Brand story">
            </div>
            <div class="story-copy">
                <span class="eyebrow">Câu chuyện thương hiệu</span>
                <h2>Được chế tác với sự tỉ mỉ và lòng tin.</h2>
                <p>Silver Atelier bắt đầu từ niềm đam mê với đá quý và hình thức trang sức bạc, khởi phát với triết lý: mỗi món đồ không chỉ là phụ kiện, mà là biểu tượng của ký ức, tình cảm và phong cách riêng.</p>
                <div class="story-points">
                    <div>
                        <strong>100%</strong>
                        <span>nguyên liệu bạc 925</span>
                    </div>
                    <div>
                        <strong>15+</strong>
                        <span>năm kinh nghiệm</span>
                    </div>
                    <div>
                        <strong>1:1</strong>
                        <span>dịch vụ tư vấn</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section-block journal-block" id="journal">
        <div class="container section-heading">
            <div>
                <span class="eyebrow">Tin tức & xu hướng</span>
                <h2>Chia sẻ từ studio</h2>
            </div>
        </div>

        <div class="container journal-grid">
            @forelse ($journalPosts as $post)
                <article class="journal-card">
                    @if ($post->featured_image)
                        <img src="{{ $post->featured_image }}" alt="{{ $post->title }}">
                    @endif
                    <div>
                        <span>{{ $post->category?->name ?? 'Tin tức' }}</span>
                        <h3>{{ $post->title }}</h3>
                        <a href="{{ route('blog.show', $post->slug) }}">Đọc thêm</a>
                    </div>
                </article>
            @empty
                <div class="filter-box" style="grid-column:1 / -1;">
                    <p style="margin:0;">Chưa có bài viết mới.</p>
                </div>
            @endforelse
        </div>
    </section>
@endsection
