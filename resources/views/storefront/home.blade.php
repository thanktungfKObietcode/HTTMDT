@extends('layouts.storefront')

@section('content')
    @php
        $heroBanners = $banners->values();
        $heroPoster = $heroBanners->first(fn ($banner) => !preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', (string) $banner->image));
        $storyVideoId = \App\Support\VimeoVideo::extractId($storyCampaign?->image);
        $storyCampaignTitle = trim((string) $storyCampaign?->title);
        $storyTitle = filled($storyCampaignTitle) && $storyCampaignTitle !== '.'
            ? $storyCampaignTitle
            : 'A LOVE STORY THAT STAYS';
        $weddingCampaign = \App\Models\Banner::query()
            ->where('position', 'home_wedding_campaign')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('sort_order')
            ->first();
        $weddingCampaignImage = \App\Support\MediaUrl::resolve($weddingCampaign?->image);
        $engagementVideoId = \App\Support\VimeoVideo::extractId($engagementVideo?->image);
        $newArrivalsCampaignImage = \App\Support\MediaUrl::resolve($newArrivalsCampaign?->image);
        $newArrivalWishlistIds = auth()->check()
            ? auth()->user()->wishlist()->whereIn('product_id', $newArrivalProducts->pluck('id'))->pluck('product_id')->all()
            : [];
        $editorialGalleryItems = $editorialGallery
            ->reject(fn ($banner) => preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', (string) $banner->image) === 1)
            ->map(fn ($banner) => ['banner' => $banner, 'image' => \App\Support\MediaUrl::resolve($banner->image)])
            ->filter(fn (array $item) => $item['image'] !== null)
            ->values();
        $editorialGalleryTrack = $editorialGalleryItems->isNotEmpty()
            ? collect(range(0, max(5, $editorialGalleryItems->count()) - 1))
                ->map(fn (int $index) => $editorialGalleryItems->get($index % $editorialGalleryItems->count()))
            : collect();
        $highJewelryVideoId = \App\Support\VimeoVideo::extractId($highJewelryVideo?->image);
        $highJewelryCarouselImages = $highJewelryImages
            ->reject(fn ($banner) => preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', (string) $banner->image) === 1)
            ->map(fn ($banner) => ['banner' => $banner, 'image' => \App\Support\MediaUrl::resolve($banner->image)])
            ->filter(fn (array $item) => $item['image'] !== null)
            ->values();
        $highJewelryCampaignImage = $highJewelryCampaign
            && preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', (string) $highJewelryCampaign->image) !== 1
            ? \App\Support\MediaUrl::resolve($highJewelryCampaign->image)
            : null;
        $highJewelryEditorialImage = $highJewelryEditorial
            && \App\Support\VimeoVideo::extractId($highJewelryEditorial->image) === null
            && preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', (string) $highJewelryEditorial->image) !== 1
            ? \App\Support\MediaUrl::resolve($highJewelryEditorial->image)
            : null;
        $highJewelryProductEditorialReady = $highJewelryEditorialImage && $highJewelryProducts->isNotEmpty();
        $highJewelryWishlistIds = auth()->check()
            ? auth()->user()->wishlist()->whereIn('product_id', $highJewelryProducts->pluck('id'))->pluck('product_id')->all()
            : [];
        $czEditorialImage = $czEditorial
            && \App\Support\VimeoVideo::extractId($czEditorial->image) === null
            && preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', (string) $czEditorial->image) !== 1
            ? \App\Support\MediaUrl::resolve($czEditorial->image)
            : null;
        $czShowcaseReady = $czEditorialImage && $czProducts->isNotEmpty();
        $czWishlistIds = auth()->check()
            ? auth()->user()->wishlist()->whereIn('product_id', $czProducts->pluck('id'))->pluck('product_id')->all()
            : [];
        $coloredGemstoneEditorialImage = $coloredGemstoneEditorial
            && \App\Support\VimeoVideo::extractId($coloredGemstoneEditorial->image) === null
            && preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', (string) $coloredGemstoneEditorial->image) !== 1
            ? \App\Support\MediaUrl::resolve($coloredGemstoneEditorial->image)
            : null;
        $coloredGemstoneShowcaseReady = $coloredGemstoneEditorialImage && $coloredGemstoneProducts->isNotEmpty();
        $coloredGemstoneWishlistIds = auth()->check()
            ? auth()->user()->wishlist()->whereIn('product_id', $coloredGemstoneProducts->pluck('id'))->pluck('product_id')->all()
            : [];
        $pearlEditorialImage = $pearlEditorial
            && \App\Support\VimeoVideo::extractId($pearlEditorial->image) === null
            && preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', (string) $pearlEditorial->image) !== 1
            ? \App\Support\MediaUrl::resolve($pearlEditorial->image)
            : null;
        $pearlShowcaseReady = $pearlEditorialImage && $pearlProducts->isNotEmpty();
        $pearlWishlistIds = auth()->check()
            ? auth()->user()->wishlist()->whereIn('product_id', $pearlProducts->pluck('id'))->pluck('product_id')->all()
            : [];
        $weddingEditorialImage = $weddingEditorial
            && \App\Support\VimeoVideo::extractId($weddingEditorial->image) === null
            && preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', (string) $weddingEditorial->image) !== 1
            ? \App\Support\MediaUrl::resolve($weddingEditorial->image)
            : null;
        $weddingShowcaseReady = $weddingEditorialImage && $weddingCarouselProducts->isNotEmpty();
        $weddingWishlistIds = auth()->check()
            ? auth()->user()->wishlist()->whereIn('product_id', $weddingCarouselProducts->pluck('id'))->pluck('product_id')->all()
            : [];
        $weddingProductPages = $weddingCarouselProducts->chunk(4)->values();
        $testimonialItems = $testimonials->take(3)->values();
        $newsItems = $newsPosts->take(9)->values();
        $loveStoryProducts = $weddingCollection
            ? \App\Models\Product::query()
                ->with(['category', 'activeImages', 'variants'])
                ->where('is_active', true)
                ->whereHas('category', fn ($query) => $query->where('is_active', true))
                ->whereHas('collections', fn ($query) => $query->whereKey($weddingCollection->id))
                ->orderByDesc('featured')
                ->orderByDesc('is_bestseller')
                ->orderBy('sort_order')
                ->limit(8)
                ->get()
            : collect();
        if ($loveStoryProducts->count() < 8) {
            $fallbackCategoryIds = \App\Models\Category::query()
                ->where('is_active', true)
                ->whereIn('slug', ['nhan-kim-cuong', 'nhan-vang', 'bong-tai-kim-cuong', 'day-chuyen-kim-cuong'])
                ->pluck('id');
            $fallbackProducts = \App\Models\Product::query()
                ->with(['category', 'activeImages', 'variants'])
                ->where('is_active', true)
                ->whereHas('category', fn ($query) => $query->where('is_active', true))
                ->whereNotIn('id', $loveStoryProducts->pluck('id'))
                ->where(fn ($query) => $query
                    ->whereIn('category_id', $fallbackCategoryIds)
                    ->orWhere('featured', true)
                    ->orWhere('is_bestseller', true)
                    ->orWhere('is_new_arrival', true))
                ->orderByDesc('featured')
                ->orderByDesc('is_bestseller')
                ->orderByDesc('is_new_arrival')
                ->orderBy('sort_order')
                ->limit(8 - $loveStoryProducts->count())
                ->get();
            $loveStoryProducts = $loveStoryProducts->concat($fallbackProducts)->take(8)->values();
        }
        $loveStoryWishlistIds = auth()->check()
            ? auth()->user()->wishlist()->whereIn('product_id', $loveStoryProducts->pluck('id'))->pluck('product_id')->all()
            : [];
        $loveStoryMoreUrl = $weddingCollection
            ? route('products.index', ['collection' => $weddingCollection->slug])
            : route('products.index', ['category' => 'trang-suc-kim-cuong']);
    @endphp

    <section class="home-hero" data-hero-carousel aria-label="Điểm nhấn trang sức">
        <div class="home-hero__slides">
            @forelse ($heroBanners as $index => $banner)
                @php
                    $media = trim((string) $banner->image);
                    $isVideo = (bool) preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', $media);
                    $hasHeroCopy = filled(trim((string) $banner->title)) && trim((string) $banner->title) !== '.';
                @endphp
                <article class="home-hero__slide{{ $index === 0 ? ' is-active' : '' }}" data-hero-slide aria-hidden="{{ $index === 0 ? 'false' : 'true' }}">
                    @if ($isVideo)
                        <video class="home-hero__media" data-hero-video muted loop playsinline preload="metadata" poster="{{ \App\Support\MediaUrl::resolve($heroPoster?->image) ?: '' }}" aria-label="{{ $hasHeroCopy ? $banner->title : 'Bộ sưu tập trang sức' }}"><source src="{{ \App\Support\MediaUrl::resolve($media) }}"></video>
                    @else
                        <img class="home-hero__media" src="{{ \App\Support\MediaUrl::resolve($media) }}" alt="{{ $hasHeroCopy ? $banner->title : 'Bộ sưu tập trang sức' }}" fetchpriority="{{ $index === 0 ? 'high' : 'auto' }}" loading="{{ $index === 0 ? 'eager' : 'lazy' }}">
                    @endif
                    <div class="home-hero__veil"></div>
                    @if ($hasHeroCopy)
                        <div class="container home-hero__content">
                            <span class="eyebrow">Tinh hoa trang sức</span>
                            <h1>{{ $banner->title }}</h1>
                            <p>Những thiết kế được tuyển chọn cho những khoảnh khắc đáng nhớ.</p>
                            <a class="button button--light" href="{{ $banner->link && !preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', $banner->link) ? $banner->link : route('products.index') }}">Khám phá bộ sưu tập</a>
                        </div>
                    @else
                        <a class="home-hero__media-link" href="{{ $banner->link && !preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', $banner->link) ? $banner->link : route('products.index') }}" aria-label="Khám phá bộ sưu tập trang sức">Khám phá bộ sưu tập</a>
                    @endif
                </article>
            @empty
                <article class="home-hero__slide is-active" data-hero-slide aria-hidden="false">
                    <div class="home-hero__media home-hero__fallback"></div><div class="home-hero__veil"></div>
                    <div class="container home-hero__content">
                        <span class="eyebrow">Tinh hoa trang sức</span>
                        <h1>Những khoảnh khắc đáng nhớ.</h1>
                        <p>Những thiết kế được tuyển chọn cho phong cách mang dấu ấn riêng.</p>
                        <a class="button button--light" href="{{ route('products.index') }}">Khám phá bộ sưu tập</a>
                    </div>
                </article>
            @endforelse
        </div>
        @if ($heroBanners->count() > 1)
            <div class="container home-hero__controls">
                <button type="button" data-hero-previous aria-label="Slide trước">←</button>
                <div class="home-hero__dots" role="tablist" aria-label="Chọn slide">
                    @foreach ($heroBanners as $index => $banner)
                        <button class="{{ $index === 0 ? 'is-active' : '' }}" type="button" data-hero-dot="{{ $index }}" role="tab" aria-label="Slide {{ $index + 1 }}" aria-selected="{{ $index === 0 ? 'true' : 'false' }}"></button>
                    @endforeach
                </div>
                <button type="button" data-hero-next aria-label="Slide tiếp theo">→</button>
            </div>
        @endif
    </section>

    <section class="home-video-story" aria-label="Campaign Video Story">
        <div class="home-video-story__inner">
            <div class="home-video-story__media">
                @if ($storyVideoId)
                    <iframe src="{{ \App\Support\VimeoVideo::embedUrl($storyVideoId) }}" title="{{ $storyTitle }}" allow="fullscreen; picture-in-picture" allowfullscreen></iframe>
                @else
                    <div class="home-video-story__empty" role="status">VIDEO MEDIA REQUIRED</div>
                @endif
            </div>
        </div>
    </section>

    <section class="home-love-story-products" aria-labelledby="home-love-story-products-title">
        <div class="container">
            <header class="home-love-story-products__heading">
                <h2 id="home-love-story-products-title">A LOVE STORY THAT STAYS</h2>
                <p>Tình yêu không chỉ được ghi nhớ bằng lời hứa, mà còn được lưu giữ qua những thiết kế tinh tế dành cho khoảnh khắc trọn đời.</p>
                <span aria-hidden="true"></span>
            </header>

            @if ($loveStoryProducts->isNotEmpty())
                <div class="love-story-product-grid">
                    @foreach ($loveStoryProducts as $product)
                        @php
                            $imageRecord = $product->activeImages->first();
                            $image = \App\Support\MediaUrl::resolve($imageRecord?->image_path ?? $product->featured_image ?? $product->image);
                            $regularPrice = (float) $product->price;
                            $salePrice = (float) ($product->sale_price ?? 0);
                            $displayPrice = $salePrice > 0 ? $salePrice : $regularPrice;
                            $inWishlist = in_array($product->id, $loveStoryWishlistIds, true);
                        @endphp
                        <article class="love-story-product-card">
                            <div class="love-story-product-card__media">
                                <a href="{{ route('product.show', $product->slug) }}">
                                    @if ($image)
                                        <img src="{{ $image }}" alt="{{ $imageRecord?->alt_text ?: $product->name }}" loading="lazy">
                                    @else
                                        <span class="media-placeholder">Silver Atelier</span>
                                    @endif
                                </a>
                                @auth
                                    @if ($inWishlist)
                                        <form method="POST" action="{{ route('wishlist.remove', $product->id) }}" class="wishlist-control">@csrf @method('DELETE')<button type="submit" aria-label="Xóa {{ $product->name }} khỏi yêu thích">♥</button></form>
                                    @else
                                        <form method="POST" action="{{ route('wishlist.add') }}" class="wishlist-control">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><button type="submit" aria-label="Thêm {{ $product->name }} vào yêu thích">♡</button></form>
                                    @endif
                                @else
                                    <a href="{{ route('login') }}" class="wishlist-control" aria-label="Đăng nhập để thêm {{ $product->name }} vào yêu thích">♡</a>
                                @endauth
                            </div>
                            <div class="love-story-product-card__details">
                                <span>{{ $product->category?->name ?? 'Trang sức' }}</span>
                                <h3><a href="{{ route('product.show', $product->slug) }}">{{ $product->name }}</a></h3>
                                <div class="love-story-product-card__price">
                                    <strong>{{ number_format($displayPrice, 0, ',', '.') }}₫</strong>
                                    @if ($salePrice > 0)
                                        <span>{{ number_format($regularPrice, 0, ',', '.') }}₫</span>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <p class="home-love-story-products__empty">Các thiết kế dành cho ngày trọng đại đang được cập nhật.</p>
            @endif

            <div class="home-love-story-products__more">
                <a href="{{ $loveStoryMoreUrl }}">Xem thêm</a>
            </div>
        </div>
    </section>

    <section class="home-wedding-campaign" aria-label="Chiến dịch trang sức cưới">
        <div class="home-wedding-campaign__media">
            @if ($weddingCampaignImage)
                <img src="{{ $weddingCampaignImage }}" alt="{{ $weddingCampaign->title ?: 'Chiến dịch trang sức cưới' }}" loading="lazy">
            @else
                <div class="home-wedding-campaign__empty" role="status">CAMPAIGN MEDIA REQUIRED</div>
            @endif
        </div>
    </section>

    <section class="home-engagement-showcase" aria-labelledby="home-engagement-showcase-title">
        <header class="home-engagement-showcase__heading">
            <h2 id="home-engagement-showcase-title">NHẪN CẦU HÔN ĐƯỢC YÊU THÍCH</h2>
            <p>Một chiếc nhẫn cho khoảnh khắc bắt đầu của hành trình yêu thương.</p>
            <span aria-hidden="true"></span>
        </header>

        <div class="home-engagement-showcase__content">
            <div class="home-engagement-showcase__products">
                @forelse ($engagementProducts as $product)
                    @php
                        $imageRecord = $product->activeImages->first();
                        $image = \App\Support\MediaUrl::resolve($imageRecord?->image_path ?? $product->featured_image ?? $product->image);
                        $requiresVariantSelection = $product->variants->isNotEmpty();
                    @endphp
                    <article class="home-engagement-product-card" data-engagement-product-id="{{ $product->id }}">
                        <div class="home-engagement-product-card__media">
                            <a href="{{ route('product.show', $product->slug) }}">
                                @if ($image)
                                    <img src="{{ $image }}" alt="{{ $imageRecord?->alt_text ?: $product->name }}" loading="lazy">
                                @else
                                    <span class="media-placeholder">Silver Atelier</span>
                                @endif
                            </a>
                            @if ($requiresVariantSelection)
                                <a class="home-engagement-product-card__action" data-engagement-action="select-options" href="{{ route('product.show', $product->slug) }}">Chọn tùy chọn</a>
                            @else
                                <form method="POST" action="{{ route('cart.add') }}" class="home-engagement-product-card__action" data-engagement-action="add-cart">
                                    @csrf
                                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit">Thêm vào giỏ hàng</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="home-engagement-showcase__empty">Các thiết kế nhẫn cầu hôn đang được cập nhật.</p>
                @endforelse
            </div>

            <div class="home-engagement-showcase__video">
                @if ($engagementVideoId)
                    <iframe src="{{ \App\Support\VimeoVideo::embedUrl($engagementVideoId) }}" title="{{ $engagementVideo?->title ?: 'Engagement Video' }}" allow="fullscreen; picture-in-picture" allowfullscreen></iframe>
                @else
                    <div class="home-engagement-showcase__empty" role="status">ENGAGEMENT VIDEO MEDIA REQUIRED</div>
                @endif
            </div>
        </div>
    </section>

    @if ($newArrivalsCampaignImage)
        <section class="home-new-arrivals-campaign" aria-label="New Arrivals Campaign">
            <img src="{{ $newArrivalsCampaignImage }}" alt="{{ $newArrivalsCampaign->title ?: 'New Arrivals Campaign' }}" loading="lazy">
        </section>
    @endif

    <section class="home-new-arrivals" id="new-arrivals" aria-labelledby="home-new-arrivals-title">
        <header class="home-new-arrivals__heading">
            <h2 id="home-new-arrivals-title">NEW ARRIVALS</h2>
            <p>Khám phá những thiết kế mới của Silver Atelier.</p>
        </header>

        @if ($newArrivalProducts->isNotEmpty())
            <div class="home-new-arrivals__grid">
                @foreach ($newArrivalProducts as $product)
                    @php
                        $imageRecord = $product->activeImages->first();
                        $image = \App\Support\MediaUrl::resolve($imageRecord?->image_path ?? $product->featured_image ?? $product->image);
                        $regularPrice = (float) $product->price;
                        $salePrice = (float) ($product->sale_price ?? 0);
                        $displayPrice = $salePrice > 0 ? $salePrice : $regularPrice;
                        $inWishlist = in_array($product->id, $newArrivalWishlistIds, true);
                    @endphp
                    <article class="home-product-card" data-new-arrival-product-id="{{ $product->id }}">
                        <div class="home-product-card__media">
                            <a href="{{ route('product.show', $product->slug) }}">@if ($image)<img src="{{ $image }}" alt="{{ $imageRecord?->alt_text ?: $product->name }}" loading="lazy">@else<span class="media-placeholder">Silver Atelier</span>@endif</a>
                            @auth
                                @if ($inWishlist)
                                    <form method="POST" action="{{ route('wishlist.remove', $product->id) }}" class="wishlist-control">@csrf @method('DELETE')<button type="submit" aria-label="Xóa {{ $product->name }} khỏi yêu thích">♥</button></form>
                                @else
                                    <form method="POST" action="{{ route('wishlist.add') }}" class="wishlist-control">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><button type="submit" aria-label="Thêm {{ $product->name }} vào yêu thích">♡</button></form>
                                @endif
                            @else
                                <a href="{{ route('login') }}" class="wishlist-control" aria-label="Đăng nhập để thêm {{ $product->name }} vào yêu thích">♡</a>
                            @endauth
                        </div>
                        <div class="home-product-card__details">
                            <h3><a href="{{ route('product.show', $product->slug) }}">{{ $product->name }}</a></h3>
                            <div class="home-new-arrivals__price">
                                <strong>{{ number_format($displayPrice, 0, ',', '.') }}₫</strong>
                                @if ($salePrice > 0)<span>{{ number_format($regularPrice, 0, ',', '.') }}₫</span>@endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="home-new-arrivals__more"><a href="{{ route('products.index') }}">Xem thêm</a></div>
        @endif
    </section>

    @if ($editorialGalleryTrack->isNotEmpty())
        <section class="home-editorial-gallery{{ ($highJewelryVideoId || $highJewelryCarouselImages->isNotEmpty()) ? ' home-editorial-gallery--compact-after' : '' }}" aria-label="Editorial Gallery" data-editorial-gallery>
            <div class="home-editorial-gallery__viewport">
                <div class="home-editorial-gallery__track">
                    <div class="home-editorial-gallery__set">
                        @foreach ($editorialGalleryTrack as $item)
                            @php
                                $banner = $item['banner'];
                                $isOriginalItem = $loop->index < $editorialGalleryItems->count();
                                $alt = $banner->title ?: 'Silver Atelier editorial';
                            @endphp

                            @if ($isOriginalItem && filled($banner->link))
                                <a class="home-editorial-gallery__item" href="{{ $banner->link }}" aria-label="{{ $alt }}">
                                    <img src="{{ $item['image'] }}" alt="{{ $alt }}" loading="lazy">
                                </a>
                            @elseif ($isOriginalItem)
                                <span class="home-editorial-gallery__item">
                                    <img src="{{ $item['image'] }}" alt="{{ $alt }}" loading="lazy">
                                </span>
                            @else
                                <span class="home-editorial-gallery__item" aria-hidden="true">
                                    <img src="{{ $item['image'] }}" alt="" loading="lazy">
                                </span>
                            @endif
                        @endforeach
                    </div>

                    <div class="home-editorial-gallery__set" aria-hidden="true">
                        @foreach ($editorialGalleryTrack as $item)
                            <span class="home-editorial-gallery__item">
                                <img src="{{ $item['image'] }}" alt="" loading="lazy">
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if ($highJewelryVideoId || $highJewelryCarouselImages->isNotEmpty())
        <section class="home-high-jewelry{{ $highJewelryCampaignImage ? ' home-high-jewelry--compact-after' : '' }}" aria-label="High Jewelry" data-high-jewelry>
            <header class="home-high-jewelry__heading">
                <h2>TRANG SỨC CAO CẤP</h2>
                <p>Khẳng định dấu ấn riêng qua những thiết kế tinh xảo, tôn vinh vẻ đẹp của kim cương và nghệ thuật chế tác cao cấp.</p>
                <span aria-hidden="true"></span>
            </header>
            <div class="home-high-jewelry__media">
                <div class="home-high-jewelry__video">
                    @if ($highJewelryVideoId)
                        <iframe src="{{ \App\Support\VimeoVideo::embedUrl($highJewelryVideoId) }}" title="{{ $highJewelryVideo?->title ?: 'High Jewelry Video' }}" allow="fullscreen; picture-in-picture" allowfullscreen></iframe>
                    @else
                        <div class="home-high-jewelry__empty" role="status">HIGH JEWELRY VIDEO MEDIA REQUIRED</div>
                    @endif
                </div>

                <div class="home-high-jewelry__carousel" data-high-jewelry-carousel>
                    @if ($highJewelryCarouselImages->isNotEmpty())
                        <div class="home-high-jewelry__viewport">
                            <div class="home-high-jewelry__track" data-high-jewelry-track>
                                @foreach ($highJewelryCarouselImages as $item)
                                    @php
                                        $banner = $item['banner'];
                                        $alt = $banner->title ?: 'Silver Atelier High Jewelry';
                                    @endphp
                                    <article class="home-high-jewelry__slide" data-high-jewelry-slide aria-hidden="{{ $loop->first ? 'false' : 'true' }}" @if (! $loop->first) inert @endif>
                                        @if (filled($banner->link))
                                            <a href="{{ $banner->link }}" aria-label="{{ $alt }}"><img src="{{ $item['image'] }}" alt="{{ $alt }}" loading="lazy"></a>
                                        @else
                                            <img src="{{ $item['image'] }}" alt="{{ $alt }}" loading="lazy">
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </div>

                        @if ($highJewelryCarouselImages->count() > 1)
                            <button class="home-high-jewelry__control home-high-jewelry__control--previous" type="button" data-high-jewelry-previous aria-label="Ảnh High Jewelry trước">‹</button>
                            <button class="home-high-jewelry__control home-high-jewelry__control--next" type="button" data-high-jewelry-next aria-label="Ảnh High Jewelry tiếp theo">›</button>
                        @endif
                    @else
                        <div class="home-high-jewelry__empty" role="status">HIGH JEWELRY IMAGE MEDIA REQUIRED</div>
                    @endif
                </div>
            </div>
        </section>
    @endif

    @if ($highJewelryCampaignImage)
        <section class="home-high-jewelry-campaign{{ $highJewelryProductEditorialReady ? ' home-high-jewelry-campaign--compact-after' : '' }}" aria-label="High Jewelry Campaign">
            @if (filled($highJewelryCampaign->link))
                <a class="home-high-jewelry-campaign__media" href="{{ $highJewelryCampaign->link }}">
                    <img src="{{ $highJewelryCampaignImage }}" alt="{{ $highJewelryCampaign->title ?: 'High Jewelry Campaign' }}" loading="lazy">
                </a>
            @else
                <div class="home-high-jewelry-campaign__media">
                    <img src="{{ $highJewelryCampaignImage }}" alt="{{ $highJewelryCampaign->title ?: 'High Jewelry Campaign' }}" loading="lazy">
                </div>
            @endif
        </section>
    @endif

    @if ($highJewelryProductEditorialReady)
        <section class="home-high-jewelry-products{{ $czShowcaseReady ? ' home-high-jewelry-products--compact-after' : '' }}" aria-label="High Jewelry Products">
            <div class="home-high-jewelry-products__content">
                <div class="home-high-jewelry-products__grid">
                    @foreach ($highJewelryProducts as $product)
                        @php
                            $imageRecord = $product->activeImages->first();
                            $image = \App\Support\MediaUrl::resolve($imageRecord?->image_path ?? $product->featured_image ?? $product->image);
                            $requiresVariantSelection = $product->variants->isNotEmpty();
                            $inWishlist = in_array($product->id, $highJewelryWishlistIds, true);
                        @endphp
                        <article class="home-high-jewelry-products__card" data-high-jewelry-product-id="{{ $product->id }}">
                            <div class="home-high-jewelry-products__product-media">
                                <a href="{{ route('product.show', $product->slug) }}">
                                    @if ($image)
                                        <img src="{{ $image }}" alt="{{ $imageRecord?->alt_text ?: $product->name }}" loading="lazy">
                                    @else
                                        <span class="media-placeholder">Silver Atelier</span>
                                    @endif
                                </a>
                                @auth
                                    @if ($inWishlist)
                                        <form method="POST" action="{{ route('wishlist.remove', $product->id) }}" class="wishlist-control">@csrf @method('DELETE')<button type="submit" aria-label="Xóa {{ $product->name }} khỏi yêu thích">♥</button></form>
                                    @else
                                        <form method="POST" action="{{ route('wishlist.add') }}" class="wishlist-control">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><button type="submit" aria-label="Thêm {{ $product->name }} vào yêu thích">♡</button></form>
                                    @endif
                                @else
                                    <a href="{{ route('login') }}" class="wishlist-control" aria-label="Đăng nhập để thêm {{ $product->name }} vào yêu thích">♡</a>
                                @endauth

                                @if ($requiresVariantSelection)
                                    <a class="home-high-jewelry-products__action" data-high-jewelry-action="select-options" href="{{ route('product.show', $product->slug) }}">Chọn tùy chọn</a>
                                @else
                                    <form method="POST" action="{{ route('cart.add') }}" class="home-high-jewelry-products__action" data-high-jewelry-action="add-cart">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit">Thêm vào giỏ hàng</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                @if (filled($highJewelryEditorial->link))
                    <a class="home-high-jewelry-products__editorial" href="{{ $highJewelryEditorial->link }}">
                        <img src="{{ $highJewelryEditorialImage }}" alt="{{ $highJewelryEditorial->title ?: 'High Jewelry Editorial' }}" loading="lazy">
                    </a>
                @else
                    <div class="home-high-jewelry-products__editorial">
                        <img src="{{ $highJewelryEditorialImage }}" alt="{{ $highJewelryEditorial->title ?: 'High Jewelry Editorial' }}" loading="lazy">
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if ($czShowcaseReady)
        <section class="home-cz-showcase{{ $coloredGemstoneShowcaseReady ? ' home-cz-showcase--compact-after' : '' }}" aria-label="CZ Jewelry Products">
            <div class="home-cz-showcase__content">
                @if (filled($czEditorial->link))
                    <a class="home-cz-showcase__editorial" href="{{ $czEditorial->link }}">
                        <img src="{{ $czEditorialImage }}" alt="{{ $czEditorial->title ?: 'CZ Editorial' }}" loading="lazy">
                    </a>
                @else
                    <div class="home-cz-showcase__editorial">
                        <img src="{{ $czEditorialImage }}" alt="{{ $czEditorial->title ?: 'CZ Editorial' }}" loading="lazy">
                    </div>
                @endif

                <div class="home-cz-showcase__grid">
                    @foreach ($czProducts as $product)
                        @php
                            $imageRecord = $product->activeImages->first();
                            $image = \App\Support\MediaUrl::resolve($imageRecord?->image_path ?? $product->featured_image ?? $product->image);
                            $requiresVariantSelection = $product->variants->isNotEmpty();
                            $inWishlist = in_array($product->id, $czWishlistIds, true);
                        @endphp
                        <article class="home-cz-showcase__card" data-cz-product-id="{{ $product->id }}">
                            <div class="home-cz-showcase__product-media">
                                <a href="{{ route('product.show', $product->slug) }}">
                                    @if ($image)
                                        <img src="{{ $image }}" alt="{{ $imageRecord?->alt_text ?: $product->name }}" loading="lazy">
                                    @else
                                        <span class="media-placeholder">Silver Atelier</span>
                                    @endif
                                </a>
                                @auth
                                    @if ($inWishlist)
                                        <form method="POST" action="{{ route('wishlist.remove', $product->id) }}" class="wishlist-control">@csrf @method('DELETE')<button type="submit" aria-label="Xóa {{ $product->name }} khỏi yêu thích">♥</button></form>
                                    @else
                                        <form method="POST" action="{{ route('wishlist.add') }}" class="wishlist-control">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><button type="submit" aria-label="Thêm {{ $product->name }} vào yêu thích">♡</button></form>
                                    @endif
                                @else
                                    <a href="{{ route('login') }}" class="wishlist-control" aria-label="Đăng nhập để thêm {{ $product->name }} vào yêu thích">♡</a>
                                @endauth

                                @if ($requiresVariantSelection)
                                    <a class="home-cz-showcase__action" data-cz-action="select-options" href="{{ route('product.show', $product->slug) }}">Chọn tùy chọn</a>
                                @else
                                    <form method="POST" action="{{ route('cart.add') }}" class="home-cz-showcase__action" data-cz-action="add-cart">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit">Thêm vào giỏ hàng</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($coloredGemstoneShowcaseReady)
        <section class="home-colored-gemstone-showcase{{ $pearlShowcaseReady ? ' home-colored-gemstone-showcase--compact-after' : '' }}" aria-label="Colored Gemstone Jewelry Products">
            <div class="home-colored-gemstone-showcase__content">
                <div class="home-colored-gemstone-showcase__grid">
                    @foreach ($coloredGemstoneProducts as $product)
                        @php
                            $imageRecord = $product->activeImages->first();
                            $image = \App\Support\MediaUrl::resolve($imageRecord?->image_path ?? $product->featured_image ?? $product->image);
                            $requiresVariantSelection = $product->variants->isNotEmpty();
                            $inWishlist = in_array($product->id, $coloredGemstoneWishlistIds, true);
                        @endphp
                        <article class="home-colored-gemstone-showcase__card" data-colored-gemstone-product-id="{{ $product->id }}">
                            <div class="home-colored-gemstone-showcase__product-media">
                                <a href="{{ route('product.show', $product->slug) }}">
                                    @if ($image)
                                        <img src="{{ $image }}" alt="{{ $imageRecord?->alt_text ?: $product->name }}" loading="lazy">
                                    @else
                                        <span class="media-placeholder">Silver Atelier</span>
                                    @endif
                                </a>
                                @auth
                                    @if ($inWishlist)
                                        <form method="POST" action="{{ route('wishlist.remove', $product->id) }}" class="wishlist-control">@csrf @method('DELETE')<button type="submit" aria-label="Xóa {{ $product->name }} khỏi yêu thích">♥</button></form>
                                    @else
                                        <form method="POST" action="{{ route('wishlist.add') }}" class="wishlist-control">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><button type="submit" aria-label="Thêm {{ $product->name }} vào yêu thích">♡</button></form>
                                    @endif
                                @else
                                    <a href="{{ route('login') }}" class="wishlist-control" aria-label="Đăng nhập để thêm {{ $product->name }} vào yêu thích">♡</a>
                                @endauth

                                @if ($requiresVariantSelection)
                                    <a class="home-colored-gemstone-showcase__action" data-colored-gemstone-action="select-options" href="{{ route('product.show', $product->slug) }}">Chọn tùy chọn</a>
                                @else
                                    <form method="POST" action="{{ route('cart.add') }}" class="home-colored-gemstone-showcase__action" data-colored-gemstone-action="add-cart">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit">Thêm vào giỏ hàng</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                @if (filled($coloredGemstoneEditorial->link))
                    <a class="home-colored-gemstone-showcase__editorial" href="{{ $coloredGemstoneEditorial->link }}">
                        <img src="{{ $coloredGemstoneEditorialImage }}" alt="{{ $coloredGemstoneEditorial->title ?: 'Colored Gemstone Editorial' }}" loading="lazy">
                    </a>
                @else
                    <div class="home-colored-gemstone-showcase__editorial">
                        <img src="{{ $coloredGemstoneEditorialImage }}" alt="{{ $coloredGemstoneEditorial->title ?: 'Colored Gemstone Editorial' }}" loading="lazy">
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if ($pearlShowcaseReady)
        <section class="home-pearl-showcase{{ $weddingShowcaseReady ? ' home-pearl-showcase--compact-after' : '' }}" aria-label="Pearl Jewelry Products">
            <div class="home-pearl-showcase__content">
                @if (filled($pearlEditorial->link))
                    <a class="home-pearl-showcase__editorial" href="{{ $pearlEditorial->link }}">
                        <img src="{{ $pearlEditorialImage }}" alt="{{ $pearlEditorial->title ?: 'Pearl Editorial' }}" loading="lazy">
                    </a>
                @else
                    <div class="home-pearl-showcase__editorial">
                        <img src="{{ $pearlEditorialImage }}" alt="{{ $pearlEditorial->title ?: 'Pearl Editorial' }}" loading="lazy">
                    </div>
                @endif

                <div class="home-pearl-showcase__grid">
                    @foreach ($pearlProducts as $product)
                        @php
                            $imageRecord = $product->activeImages->first();
                            $image = \App\Support\MediaUrl::resolve($imageRecord?->image_path ?? $product->featured_image ?? $product->image);
                            $requiresVariantSelection = $product->variants->isNotEmpty();
                            $inWishlist = in_array($product->id, $pearlWishlistIds, true);
                        @endphp
                        <article class="home-pearl-showcase__card" data-pearl-product-id="{{ $product->id }}">
                            <div class="home-pearl-showcase__product-media">
                                <a href="{{ route('product.show', $product->slug) }}">
                                    @if ($image)
                                        <img src="{{ $image }}" alt="{{ $imageRecord?->alt_text ?: $product->name }}" loading="lazy">
                                    @else
                                        <span class="media-placeholder">Silver Atelier</span>
                                    @endif
                                </a>
                                @auth
                                    @if ($inWishlist)
                                        <form method="POST" action="{{ route('wishlist.remove', $product->id) }}" class="wishlist-control">@csrf @method('DELETE')<button type="submit" aria-label="Xóa {{ $product->name }} khỏi yêu thích">♥</button></form>
                                    @else
                                        <form method="POST" action="{{ route('wishlist.add') }}" class="wishlist-control">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><button type="submit" aria-label="Thêm {{ $product->name }} vào yêu thích">♡</button></form>
                                    @endif
                                @else
                                    <a href="{{ route('login') }}" class="wishlist-control" aria-label="Đăng nhập để thêm {{ $product->name }} vào yêu thích">♡</a>
                                @endauth

                                @if ($requiresVariantSelection)
                                    <a class="home-pearl-showcase__action" data-pearl-action="select-options" href="{{ route('product.show', $product->slug) }}">Chọn tùy chọn</a>
                                @else
                                    <form method="POST" action="{{ route('cart.add') }}" class="home-pearl-showcase__action" data-pearl-action="add-cart">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit">Thêm vào giỏ hàng</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($weddingShowcaseReady)
        <section class="home-wedding-showcase" aria-label="Trang sức cưới">
            <div class="home-wedding-showcase__content">
                <div class="home-wedding-showcase__carousel" data-wedding-carousel>
                    <div class="home-wedding-showcase__viewport">
                        <div class="home-wedding-showcase__track" data-wedding-track>
                            @foreach ($weddingProductPages as $pageIndex => $products)
                                <div class="home-wedding-showcase__page" data-wedding-page data-wedding-page-size="{{ $products->count() }}" aria-hidden="{{ $pageIndex === 0 ? 'false' : 'true' }}" @if ($pageIndex > 0) inert @endif>
                                    @foreach ($products as $product)
                                        @php
                                            $imageRecord = $product->activeImages->first();
                                            $image = \App\Support\MediaUrl::resolve($imageRecord?->image_path ?? $product->featured_image ?? $product->image);
                                            $requiresVariantSelection = $product->variants->isNotEmpty();
                                            $inWishlist = in_array($product->id, $weddingWishlistIds, true);
                                        @endphp
                                        <article class="home-wedding-showcase__card" data-wedding-product-id="{{ $product->id }}">
                                            <div class="home-wedding-showcase__product-media">
                                                <a href="{{ route('product.show', $product->slug) }}">
                                                    @if ($image)
                                                        <img src="{{ $image }}" alt="{{ $imageRecord?->alt_text ?: $product->name }}" loading="lazy">
                                                    @else
                                                        <span class="media-placeholder">Silver Atelier</span>
                                                    @endif
                                                </a>
                                                @auth
                                                    @if ($inWishlist)
                                                        <form method="POST" action="{{ route('wishlist.remove', $product->id) }}" class="wishlist-control">@csrf @method('DELETE')<button type="submit" aria-label="Xóa {{ $product->name }} khỏi yêu thích">♥</button></form>
                                                    @else
                                                        <form method="POST" action="{{ route('wishlist.add') }}" class="wishlist-control">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><button type="submit" aria-label="Thêm {{ $product->name }} vào yêu thích">♡</button></form>
                                                    @endif
                                                @else
                                                    <a href="{{ route('login') }}" class="wishlist-control" aria-label="Đăng nhập để thêm {{ $product->name }} vào yêu thích">♡</a>
                                                @endauth

                                                @if ($requiresVariantSelection)
                                                    <a class="home-wedding-showcase__action" data-wedding-action="select-options" href="{{ route('product.show', $product->slug) }}">Chọn tùy chọn</a>
                                                @else
                                                    <form method="POST" action="{{ route('cart.add') }}" class="home-wedding-showcase__action" data-wedding-action="add-cart">
                                                        @csrf
                                                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                                                        <input type="hidden" name="quantity" value="1">
                                                        <button type="submit">Thêm vào giỏ hàng</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </article>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if ($weddingProductPages->count() > 1)
                        <button type="button" class="home-wedding-showcase__control home-wedding-showcase__control--previous" data-wedding-previous aria-label="Nhóm sản phẩm trước">‹</button>
                        <button type="button" class="home-wedding-showcase__control home-wedding-showcase__control--next" data-wedding-next aria-label="Nhóm sản phẩm tiếp theo">›</button>
                    @endif
                </div>

                @if (filled($weddingEditorial->link))
                    <a class="home-wedding-showcase__editorial" href="{{ $weddingEditorial->link }}">
                        <img src="{{ $weddingEditorialImage }}" alt="{{ $weddingEditorial->title ?: 'Wedding Editorial' }}" loading="lazy">
                    </a>
                @else
                    <div class="home-wedding-showcase__editorial">
                        <img src="{{ $weddingEditorialImage }}" alt="{{ $weddingEditorial->title ?: 'Wedding Editorial' }}" loading="lazy">
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if ($testimonialItems->isNotEmpty())
        <section class="home-testimonials" aria-labelledby="testimonials-heading" data-testimonials>
            <div class="home-testimonials__inner">
                <header class="home-testimonials__heading">
                    <h2 id="testimonials-heading">Khách hàng</h2>
                    <p>Nói về Silver Atelier</p>
                </header>

                <div class="home-testimonials__selectors" role="group" aria-label="Chọn nhận xét khách hàng">
                    @foreach ($testimonialItems as $index => $review)
                        @php
                            $customerName = filled($review->user?->name) ? $review->user->name : 'Khách hàng';
                            $nameParts = preg_split('/\s+/u', trim($customerName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                            $firstInitial = \Illuminate\Support\Str::substr($nameParts[0] ?? '', 0, 1);
                            $lastInitial = \Illuminate\Support\Str::substr($nameParts[count($nameParts) - 1] ?? '', 0, 1);
                            $initials = \Illuminate\Support\Str::upper($firstInitial.$lastInitial);
                            $avatar = \App\Support\MediaUrl::resolve($review->user?->avatar);
                        @endphp
                        <button type="button" class="home-testimonials__selector{{ $index === 0 ? ' is-active' : '' }}" data-testimonial-selector data-testimonial-index="{{ $index }}" aria-label="Xem nhận xét của {{ $customerName }}" aria-pressed="{{ $index === 0 ? 'true' : 'false' }}">
                            @if ($avatar)
                                <img src="{{ $avatar }}" alt="" loading="lazy">
                            @else
                                <span aria-hidden="true">{{ $initials }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>

                <div class="home-testimonials__stage" aria-live="polite">
                    @foreach ($testimonialItems as $index => $review)
                        @php
                            $customerName = filled($review->user?->name) ? $review->user->name : 'Khách hàng';
                            $stars = str_repeat('★', (int) $review->rating).str_repeat('☆', 5 - (int) $review->rating);
                        @endphp
                        <article class="home-testimonials__item{{ $index === 0 ? ' is-active' : '' }}" data-testimonial-panel aria-hidden="{{ $index === 0 ? 'false' : 'true' }}" @if ($index > 0) inert @endif>
                            <blockquote>{{ $review->comment }}</blockquote>
                            <span class="home-testimonials__rating" aria-label="{{ $review->rating }} trên 5 sao">{{ $stars }}</span>
                            <p>{{ $customerName }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($newsItems->isNotEmpty())
        <section class="home-news" aria-labelledby="home-news-heading" data-news-carousel>
            <header class="home-news__heading">
                <h2 id="home-news-heading">TIN TỨC</h2>
                <p>Ưu đãi - Sự kiện</p>
            </header>

            <div class="home-news__carousel">
                <div class="home-news__viewport">
                    <div class="home-news__track{{ $newsItems->count() < 3 ? ' home-news__track--partial' : '' }}" data-news-track>
                        @foreach ($newsItems as $index => $post)
                            @php
                                $postImage = \App\Support\MediaUrl::resolve($post->featured_image);
                                $publishedDate = $post->published_at ?? $post->created_at;
                                $metadata = trim(($publishedDate?->format('d/m/Y') ?? '').' | '.($post->category?->name ?? 'Tin tức'));
                            @endphp
                            <article class="home-news__card" data-news-card data-news-post-id="{{ $post->id }}" aria-hidden="{{ $index < 3 ? 'false' : 'true' }}" @if ($index >= 3) inert @endif>
                                <a class="home-news__media" href="{{ route('blog.show', $post->slug) }}" aria-label="Đọc {{ $post->title }}">
                                    @if ($postImage)
                                        <img src="{{ $postImage }}" alt="{{ $post->title }}" loading="lazy">
                                    @else
                                        <span class="home-news__placeholder" aria-hidden="true">Silver Atelier</span>
                                    @endif
                                </a>
                                <div class="home-news__details">
                                    <p class="home-news__metadata">{{ $metadata }}</p>
                                    <h3><a href="{{ route('blog.show', $post->slug) }}">{{ $post->title }}</a></h3>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>

                <button type="button" class="home-news__control home-news__control--previous" data-news-previous aria-label="Bài viết trước" hidden>‹</button>
                <button type="button" class="home-news__control home-news__control--next" data-news-next aria-label="Bài viết tiếp theo" hidden>›</button>
            </div>

            <div class="home-news__more">
                <a href="{{ route('blog.index') }}">XEM THÊM</a>
            </div>
        </section>
    @endif

@endsection
