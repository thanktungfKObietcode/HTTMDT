@extends('layouts.storefront')

@section('content')
    @php
        $detailImageRecord = $images->first();
        $detailImage = \App\Support\MediaUrl::resolve($detailImageRecord?->image_path ?? $product->featured_image) ?? 'https://images.unsplash.com/photo-1601821765780-3bf8f25f4d74?auto=format&fit=crop&w=900&q=80';
        $salePrice = (float) ($product->sale_price ?? 0);
        $price = (float) ($product->price ?? 0);
        $selectedVariant = $variants->firstWhere('id', (int) old('product_variant_id'))
            ?? $variants->first(fn ($variant) => (int) $variant->stock > 0)
            ?? $variants->first();
        $selectedSalePrice = (float) ($selectedVariant?->sale_price ?? 0);
        $selectedBasePrice = (float) ($selectedVariant?->price ?? $price);
        $displayPrice = $selectedVariant
            ? ($selectedSalePrice > 0 ? $selectedSalePrice : $selectedBasePrice)
            : ($salePrice > 0 ? $salePrice : $price);
        $comparePrice = $selectedVariant
            ? ($selectedSalePrice > 0 ? $selectedBasePrice : null)
            : ($salePrice > 0 ? $price : null);
        $selectedStock = (int) ($selectedVariant?->stock ?? $product->stock);
    @endphp

    <section class="section-block product-detail">
        @if ($categoryBreadcrumbs !== [])
            <nav class="container breadcrumbs" aria-label="Điều hướng phân cấp">
                <a href="{{ route('home') }}">Trang chủ</a>
                @foreach ($categoryBreadcrumbs as $category)
                    <span aria-hidden="true">/</span>
                    <a href="{{ route('products.index', ['category' => $category->slug]) }}">{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif
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
                    <img id="product-main-image" src="{{ $detailImage }}" alt="{{ $detailImageRecord?->alt_text ?: $product->name }}">
                </div>
                <div class="thumb-row">
                    @forelse ($images as $image)
                        <button type="button" class="product-thumb" data-image="{{ \App\Support\MediaUrl::resolve($image->image_path) }}" data-alt="{{ $image->alt_text ?: $product->name }}" style="border:0;background:transparent;padding:0"><img src="{{ \App\Support\MediaUrl::resolve($image->image_path) }}" alt="{{ $image->alt_text ?: $product->name }}"></button>
                    @empty
                        <img src="{{ $detailImage }}" alt="{{ $product->name }} thumbnail">
                        <img src="https://images.unsplash.com/photo-1617038220319-276d3cfab638?auto=format&fit=crop&w=600&q=80" alt="thumbnail 2">
                        <img src="https://images.unsplash.com/photo-1573408301185-9146fe634ad0?auto=format&fit=crop&w=600&q=80" alt="thumbnail 3">
                    @endforelse
                </div>
            </div>

            <div class="detail-panel">
                <span class="eyebrow">{{ $categoryBreadcrumbs !== [] ? $categoryBreadcrumbs[count($categoryBreadcrumbs) - 1]->name : ($product->category->name ?? 'Trang sức') }}</span>
                <h1>{{ $product->name }}</h1>
                <div class="rating-row detail-rating">
                    <span>★★★★★</span>
                    <small>{{ number_format($averageRating ?? ($product->average_rating ?? 0), 1) }} • {{ $product->reviews()->count() }} đánh giá</small>
                </div>

                <div class="price-row detail-price">
                    <strong id="selected-product-price">{{ number_format($displayPrice, 0, ',', '.') }}đ</strong>
                    <span id="selected-product-compare-price" @if(! $comparePrice) hidden @endif>{{ $comparePrice ? number_format($comparePrice, 0, ',', '.').'đ' : '' }}</span>
                </div>

                <p class="product-summary">{{ $product->short_description ?? 'Những thiết kế bạc thanh lịch, bền đẹp và phù hợp cho mọi phong cách.' }}</p>

                @if($product->collections->isNotEmpty())<p><strong>Bộ sưu tập:</strong> {{ $product->collections->pluck('name')->join(', ') }}</p>@endif
                @if($product->is_new_arrival || $product->is_bestseller)<p>@if($product->is_new_arrival)<span class="badge">Hàng mới</span>@endif @if($product->is_bestseller)<span class="badge badge-featured">Bán chạy</span>@endif</p>@endif

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
                                    <input type="radio" name="product_variant_id" value="{{ $variant->id }}" form="add-to-cart-form"
                                        data-price="{{ $variant->price }}"
                                        data-sale-price="{{ $variant->sale_price ?? '' }}"
                                        data-stock="{{ $variant->stock }}"
                                        @checked($selectedVariant && $selectedVariant->id === $variant->id)
                                        @disabled((int) $variant->stock < 1)
                                        required>
                                    <span>{{ $variant->size ?? $variant->color ?? $variant->metal_type ?? 'Phiên bản ' . $loop->iteration }}{{ (int) $variant->stock < 1 ? ' — Hết hàng' : '' }}</span>
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
                        <input id="quantity" type="number" name="quantity" value="{{ old('quantity', 1) }}" min="1" max="{{ max(1, $selectedStock) }}" @disabled($selectedStock < 1) required style="width:72px; padding:12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                        <button id="add-to-cart-button" type="submit" class="btn btn-primary large" @disabled($selectedStock < 1)>{{ $selectedStock > 0 ? 'Thêm vào giỏ hàng' : 'Hết hàng' }}</button>
                    </form>
                    <small id="selected-variant-stock" style="display:block; color:#666;">{{ $selectedStock > 0 ? 'Còn '.$selectedStock.' sản phẩm' : 'Phiên bản đã hết hàng' }}</small>
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

                @if ($variants->isNotEmpty())
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const variants = document.querySelectorAll('input[name="product_variant_id"]');
                            const quantity = document.getElementById('quantity');
                            const addButton = document.getElementById('add-to-cart-button');
                            const price = document.getElementById('selected-product-price');
                            const comparePrice = document.getElementById('selected-product-compare-price');
                            const stockText = document.getElementById('selected-variant-stock');
                            const formatMoney = value => new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(value) + 'đ';

                            function applyVariant(variant) {
                                if (!variant) return;

                                const stock = Number(variant.dataset.stock || 0);
                                const basePrice = Number(variant.dataset.price || 0);
                                const salePrice = Number(variant.dataset.salePrice || 0);
                                price.textContent = formatMoney(salePrice > 0 ? salePrice : basePrice);
                                comparePrice.hidden = salePrice <= 0;
                                comparePrice.textContent = salePrice > 0 ? formatMoney(basePrice) : '';
                                quantity.max = String(Math.max(1, stock));
                                quantity.disabled = stock < 1;
                                addButton.disabled = stock < 1;
                                addButton.textContent = stock > 0 ? 'Thêm vào giỏ hàng' : 'Hết hàng';
                                stockText.textContent = stock > 0 ? `Còn ${stock} sản phẩm` : 'Phiên bản đã hết hàng';

                                if (stock > 0 && Number(quantity.value) > stock) {
                                    quantity.value = String(stock);
                                }
                            }

                            variants.forEach(variant => variant.addEventListener('change', () => applyVariant(variant)));
                            applyVariant(document.querySelector('input[name="product_variant_id"]:checked'));
                        });
                    </script>
                @endif

                <ul class="detail-meta">
                    <li>Miễn phí vận chuyển đơn từ 1.000.000đ</li>
                    <li>Bảo hành 12 tháng</li>
                    <li>Giao hàng trong 48 giờ</li>
                </ul>
            </div>
        </div>
    </section>

    <script>
        document.querySelectorAll('.product-thumb').forEach(function (button) {
            button.addEventListener('click', function () {
                const main = document.getElementById('product-main-image');
                main.src = button.dataset.image;
                main.alt = button.dataset.alt;
            });
        });
    </script>

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
                <h2>Đánh giá sản phẩm</h2>
            </div>
        </div>

        <div class="container" style="display:grid; gap:18px;">
            @if (session('success'))
                <div class="filter-box" style="padding:14px 18px; background:#eaf7ef; color:#23613a;">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->has('review'))
                <div class="filter-box" style="padding:14px 18px; background:#ffe8e8; color:#8a1f1f;">
                    {{ $errors->first('review') }}
                </div>
            @endif

            @if (! auth()->check())
                <div class="filter-box">
                    <p style="margin:0;">Đăng nhập để xem quyền đánh giá sản phẩm.</p>
                    <a href="{{ route('login') }}" class="btn btn-secondary" style="margin-top:12px;">Đăng nhập</a>
                </div>
            @elseif ($existingReview)
                <div class="filter-box">
                    <p style="margin:0;">Bạn đã đánh giá sản phẩm này.</p>
                </div>
            @elseif ($canReview)
                <form method="POST" action="{{ route('product.reviews.store', $product) }}" class="filter-box" style="display:grid; gap:12px;">
                    @csrf
                    <h3 style="margin:0;">Viết đánh giá của bạn</h3>
                    <label for="review-rating" style="font-weight:600;">Số sao</label>
                    <select id="review-rating" name="rating" required style="max-width:180px; padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                        <option value="">Chọn số sao</option>
                        @for ($rating = 5; $rating >= 1; $rating--)
                            <option value="{{ $rating }}" {{ (string) old('rating') === (string) $rating ? 'selected' : '' }}>{{ $rating }}/5</option>
                        @endfor
                    </select>
                    @error('rating')<small style="color:#c62828;">{{ $message }}</small>@enderror
                    <label for="review-comment" style="font-weight:600;">Nội dung</label>
                    <textarea id="review-comment" name="comment" maxlength="2000" rows="4" placeholder="Chia sẻ trải nghiệm của bạn..." style="width:100%; padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px; font-family:inherit;">{{ old('comment') }}</textarea>
                    @error('comment')<small style="color:#c62828;">{{ $message }}</small>@enderror
                    <button type="submit" class="btn btn-primary" style="width:max-content;">Gửi đánh giá</button>
                </form>
            @else
                <div class="filter-box">
                    <p style="margin:0;">Bạn cần nhận hàng thành công trước khi đánh giá sản phẩm.</p>
                </div>
            @endif

            @forelse ($reviews as $review)
                <article class="filter-box" style="padding:18px 20px;">
                    <div class="rating-row" style="margin-bottom:8px;">
                        <span>★★★★★</span>
                        <small>{{ $review->rating }}/5</small>
                    </div>
                    <p style="margin:0 0 6px; font-weight:600;">{{ $review->user->name ?? 'Khách hàng' }}</p>
                    <p style="margin:0; color:#6c625d;">{{ $review->comment }}</p>
                    <small style="display:block; margin-top:8px; color:#6c625d;">
                        {{ $review->created_at->format('d/m/Y H:i') }}
                        @if ($review->is_verified_purchase)
                            • Đã mua hàng
                        @endif
                    </small>
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
                    $relatedImage = \App\Support\MediaUrl::resolve($relatedProduct->featured_image) ?? 'https://images.unsplash.com/photo-1601821765780-3bf8f25f4d74?auto=format&fit=crop&w=900&q=80';
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
