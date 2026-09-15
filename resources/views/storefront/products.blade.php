@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Fine Jewelry</span>
            <h1>Sản phẩm</h1>
        </div>
    </section>

    <section class="section-block catalog-layout">
        <div class="container catalog-grid">
            <aside class="catalog-sidebar">
                <div class="filter-box">
                    <h3>Danh mục</h3>
                    <ul>
                        @foreach ($categories as $category)
                            <li>
                                <a href="{{ url('/san-pham') }}?category={{ urlencode($category->slug) }}{{ $search ? '&q=' . urlencode($search) : '' }}{{ $selectedMaterial ? '&material=' . urlencode($selectedMaterial) : '' }}{{ $selectedCollection ? '&collection=' . urlencode($selectedCollection) : '' }}">{{ $category->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="filter-box">
                    <h3>Chất liệu</h3>
                    <ul>
                        @foreach ($materials as $material)
                            <li>
                                <a href="{{ url('/san-pham') }}?material={{ urlencode($material->code ?? $material->slug ?? $material->id) }}{{ $search ? '&q=' . urlencode($search) : '' }}{{ $selectedCategory ? '&category=' . urlencode($selectedCategory) : '' }}{{ $selectedCollection ? '&collection=' . urlencode($selectedCollection) : '' }}">{{ $material->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="filter-box">
                    <h3>Bộ sưu tập</h3>
                    <ul>
                        @foreach ($collections as $collection)
                            <li>
                                <a href="{{ url('/san-pham') }}?collection={{ urlencode($collection->slug) }}{{ $search ? '&q=' . urlencode($search) : '' }}{{ $selectedCategory ? '&category=' . urlencode($selectedCategory) : '' }}{{ $selectedMaterial ? '&material=' . urlencode($selectedMaterial) : '' }}">{{ $collection->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </aside>

            <div class="catalog-content">
                <form class="toolbar" method="GET" action="{{ route('products.index') }}">
                    <p>Hiển thị {{ $products->total() }} sản phẩm</p>

                    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                        <input type="search" name="q" value="{{ old('q', $search) }}" placeholder="Tìm sản phẩm..." style="min-width:220px; padding:10px 12px; border:1px solid rgba(31,28,26,0.1); border-radius:10px; background:rgba(255,255,255,0.7);" />
                        <select name="sort" aria-label="Sort products" onchange="this.form.submit()">
                            <option value="newest" {{ $sort === 'newest' ? 'selected' : '' }}>Mới nhất</option>
                            <option value="price_asc" {{ $sort === 'price_asc' ? 'selected' : '' }}>Giá tăng dần</option>
                            <option value="price_desc" {{ $sort === 'price_desc' ? 'selected' : '' }}>Giá giảm dần</option>
                            <option value="name_asc" {{ $sort === 'name_asc' ? 'selected' : '' }}>Tên A-Z</option>
                        </select>
                    </div>
                </form>

                <div class="product-grid product-grid-compact">
                    @forelse ($products as $product)
                        @php
                            $imageRecord = $product->activeImages->first();
                            $image = \App\Support\MediaUrl::resolve($imageRecord?->image_path ?? $product->featured_image) ?? 'https://images.unsplash.com/photo-1617038220319-276d3cfab638?auto=format&fit=crop&w=900&q=80';
                            $salePrice = (float) ($product->sale_price ?? 0);
                            $price = (float) ($product->price ?? 0);
                            $displayPrice = $salePrice > 0 ? $salePrice : $price;
                            $comparePrice = $salePrice > 0 ? $price : null;
                        @endphp

                        <article class="product-card">
                            <div class="product-media">
                                <img src="{{ $image }}" alt="{{ $imageRecord?->alt_text ?: $product->name }}">
                                @if ($salePrice > 0)
                                    <span class="badge badge-sale">Sale</span>
                                @endif
                                @if($product->is_new_arrival)<span class="badge" style="top:42px">Mới</span>@elseif($product->is_bestseller)<span class="badge badge-featured" style="top:42px">Bán chạy</span>@endif
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
                                    <small>{{ number_format($product->average_rating ?? 0, 1) }}</small>
                                </div>
                                <div class="price-row">
                                    <strong>{{ number_format($displayPrice, 0, ',', '.') }}đ</strong>
                                    @if ($comparePrice)
                                        <span>{{ number_format($comparePrice, 0, ',', '.') }}đ</span>
                                    @endif
                                </div>
                                @php $availableStock = $product->variants->isNotEmpty() ? $product->variants->sum('stock') : $product->stock; @endphp
                                <small>{{ $availableStock > 0 ? 'Còn hàng' : 'Hết hàng' }}</small>
                            </div>
                        </article>
                    @empty
                        <div class="filter-box" style="grid-column:1 / -1;">
                            <h3>Không tìm thấy sản phẩm phù hợp.</h3>
                            <p>Hãy thử từ khóa khác hoặc bỏ bớt bộ lọc.</p>
                        </div>
                    @endforelse
                </div>

                @if ($products->hasPages())
                    <div style="margin-top:28px; display:flex; justify-content:center;">
                        {{ $products->links() }}
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
