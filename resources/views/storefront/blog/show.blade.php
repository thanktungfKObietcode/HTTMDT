@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">{{ $post->category?->name ?? 'Tin tức' }}</span>
            <h1>{{ $post->title }}</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; grid-template-columns:minmax(0, 1fr) 280px; gap:28px; align-items:start;">
            <article class="filter-box">
                @if ($post->featured_image)
                    <img src="{{ $post->featured_image }}" alt="{{ $post->title }}" style="width:100%; max-height:480px; object-fit:cover; border-radius:16px; margin-bottom:24px;">
                @endif
                @if ($post->excerpt)<p class="product-summary">{{ $post->excerpt }}</p>@endif
                <div style="white-space:pre-line;">{{ $post->content }}</div>
                <small style="display:block; margin-top:24px; color:#6c625d;">{{ $post->created_at->format('d/m/Y H:i') }}</small>
            </article>

            @if ($relatedPosts->isNotEmpty())
                <aside class="filter-box">
                    <h3>Bài viết liên quan</h3>
                    <ul>
                        @foreach ($relatedPosts as $relatedPost)
                            <li><a href="{{ route('blog.show', $relatedPost->slug) }}">{{ $relatedPost->title }}</a></li>
                        @endforeach
                    </ul>
                </aside>
            @endif
        </div>
    </section>
@endsection