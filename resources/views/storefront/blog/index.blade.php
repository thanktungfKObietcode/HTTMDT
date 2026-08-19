@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Tin tức & xu hướng</span>
            <h1>Chia sẻ từ studio</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; gap:24px;">
            @if ($categories->isNotEmpty())
                <nav class="filter-box" aria-label="Danh mục bài viết" style="display:flex; gap:12px; flex-wrap:wrap;">
                    <a class="btn btn-secondary" href="{{ route('blog.index') }}">Tất cả</a>
                    @foreach ($categories as $category)
                        <a class="btn btn-secondary" href="{{ route('blog.index', ['category' => $category->slug]) }}">{{ $category->name }}</a>
                    @endforeach
                </nav>
            @endif

            <div class="journal-grid">
                @forelse ($posts as $post)
                    <article class="journal-card">
                        @if ($post->featured_image)
                            <img src="{{ $post->featured_image }}" alt="{{ $post->title }}">
                        @endif
                        <div>
                            <span>{{ $post->category?->name ?? 'Tin tức' }}</span>
                            <h3>{{ $post->title }}</h3>
                            @if ($post->excerpt)<p>{{ $post->excerpt }}</p>@endif
                            <a href="{{ route('blog.show', $post->slug) }}">Đọc thêm</a>
                        </div>
                    </article>
                @empty
                    <div class="filter-box" style="grid-column:1 / -1;">
                        <h3>Chưa có bài viết.</h3>
                        <p style="margin:0;">Nội dung mới sẽ được cập nhật tại đây.</p>
                    </div>
                @endforelse
            </div>

            @if ($posts->hasPages())
                {{ $posts->links() }}
            @endif
        </div>
    </section>
@endsection