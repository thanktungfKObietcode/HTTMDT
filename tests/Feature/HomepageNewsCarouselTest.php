<?php

namespace Tests\Feature;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageNewsCarouselTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_news_uses_only_visible_scheduled_posts_in_published_order_and_limits_nine(): void
    {
        $category = BlogCategory::create([
            'name' => 'Tin tức',
            'slug' => 'tin-tuc',
            'is_active' => true,
        ]);
        $inactiveCategory = BlogCategory::create([
            'name' => 'Ẩn',
            'slug' => 'an',
            'is_active' => false,
        ]);

        $posts = collect(range(1, 10))->map(function (int $number) use ($category): BlogPost {
            return $this->createPost(
                title: 'News '.$number,
                slug: 'news-'.$number,
                category: $category,
                publishedAt: now()->subMinutes($number),
                image: $number === 1 ? 'blog/news-1.jpg' : null,
            );
        });

        $this->createPost('Draft news', 'draft-news', $category, now()->subMinute(), published: false);
        $this->createPost('Future news', 'future-news', $category, now()->addDay());
        $this->createPost('Inactive category news', 'inactive-category-news', $inactiveCategory, now()->subMinute());

        $content = $this->get(route('home'))->assertOk()->getContent();
        $newsMarkup = $this->newsMarkup($content);

        $this->assertStringContainsString('home-news', $newsMarkup);
        $this->assertStringContainsString(route('blog.show', 'news-1'), $newsMarkup);
        $this->assertStringContainsString('/storage/blog/news-1.jpg', $newsMarkup);
        $this->assertStringContainsString('home-news__placeholder', $newsMarkup);
        $this->assertStringNotContainsString('Draft news', $newsMarkup);
        $this->assertStringNotContainsString('Future news', $newsMarkup);
        $this->assertStringNotContainsString('Inactive category news', $newsMarkup);
        $this->assertStringNotContainsString('News 10', $newsMarkup);

        preg_match_all('/data-news-post-id="(\d+)"/', $newsMarkup, $matches);
        $this->assertCount(9, $matches[1]);
        $this->assertSame(
            $posts->take(9)->pluck('id')->map(fn (int $id) => (string) $id)->all(),
            $matches[1],
        );

        $previousPosition = -1;
        foreach (range(1, 9) as $number) {
            $position = strpos($newsMarkup, 'News '.$number);
            $this->assertNotFalse($position);
            $this->assertGreaterThan($previousPosition, $position);
            $previousPosition = $position;
        }

        $this->assertStringContainsString('data-news-previous', $newsMarkup);
        $this->assertStringContainsString('data-news-next', $newsMarkup);
    }

    public function test_homepage_omits_news_when_no_post_is_publicly_visible(): void
    {
        $category = BlogCategory::create([
            'name' => 'Tin tức',
            'slug' => 'tin-tuc',
            'is_active' => true,
        ]);

        $this->createPost('Draft only', 'draft-only', $category, now()->subMinute(), published: false);
        $this->createPost('Future only', 'future-only', $category, now()->addDay());

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('home-news', false);
    }

    public function test_homepage_news_keeps_controls_hidden_for_a_single_desktop_page(): void
    {
        $category = BlogCategory::create([
            'name' => 'Tin tức',
            'slug' => 'tin-tuc',
            'is_active' => true,
        ]);

        foreach (range(1, 3) as $number) {
            $this->createPost('Single page '.$number, 'single-page-'.$number, $category, now()->subMinutes($number));
        }

        $newsMarkup = $this->newsMarkup($this->get(route('home'))->assertOk()->getContent());

        preg_match_all('/data-news-post-id="(\d+)"/', $newsMarkup, $matches);
        $this->assertCount(3, $matches[1]);
        $this->assertMatchesRegularExpression('/data-news-previous[^>]*hidden/', $newsMarkup);
        $this->assertMatchesRegularExpression('/data-news-next[^>]*hidden/', $newsMarkup);
    }

    public function test_homepage_does_not_render_legacy_trailing_blocks_after_news(): void
    {
        $category = BlogCategory::create([
            'name' => 'Tin tức',
            'slug' => 'tin-tuc-final-flow',
            'is_active' => true,
        ]);
        $this->createPost('News final flow', 'news-final-flow', $category, now()->subMinute());

        $content = $this->get(route('home'))->assertOk()->getContent();
        $footerPosition = strpos($content, '<footer class="site-footer"');
        $newsPosition = strpos($content, '<section class="home-news"');

        $this->assertNotFalse($newsPosition);
        $this->assertNotFalse($footerPosition);
        $this->assertLessThan($footerPosition, $newsPosition);
        $this->assertStringNotContainsString('class="home-categories"', $content);
        $this->assertStringNotContainsString('class="home-story"', $content);
        $this->assertStringNotContainsString('class="home-campaign"', $content);
        $this->assertStringNotContainsString('class="home-journal"', $content);
        $this->assertStringNotContainsString('Thiết kế nổi bật', $content);
        $this->assertStringNotContainsString('Những điều đáng lưu tâm', $content);
    }

    private function createPost(
        string $title,
        string $slug,
        BlogCategory $category,
        \DateTimeInterface $publishedAt,
        bool $published = true,
        ?string $image = null,
    ): BlogPost {
        return BlogPost::create([
            'blog_category_id' => $category->id,
            'title' => $title,
            'slug' => $slug,
            'excerpt' => 'Nội dung tóm tắt.',
            'content' => 'Nội dung bài viết.',
            'featured_image' => $image,
            'is_published' => $published,
            'published_at' => $publishedAt,
        ]);
    }

    private function newsMarkup(string $content): string
    {
        preg_match('/<section class="home-news".*?<\/section>/s', $content, $matches);

        return $matches[0] ?? '';
    }
}
