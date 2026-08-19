<?php

namespace Tests\Feature;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\NewsletterSubscriber;
use App\Models\Showroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_blog_posts_are_listed(): void
    {
        $post = $this->createPost();

        $response = $this->get(route('blog.index'));

        $response->assertOk();
        $response->assertSeeText($post->title);
    }

    public function test_blog_category_filter_returns_matching_posts(): void
    {
        $category = BlogCategory::create(['name' => 'Craft', 'slug' => 'craft']);
        $post = $this->createPost($category);
        $other = $this->createPost(BlogCategory::create(['name' => 'Style', 'slug' => 'style']));

        $response = $this->get(route('blog.index', ['category' => $category->slug]));

        $response->assertSeeText($post->title);
        $response->assertDontSeeText($other->title);
    }

    public function test_unpublished_blog_posts_are_not_listed(): void
    {
        $post = $this->createPost(null, false);

        $this->get(route('blog.index'))
            ->assertOk()
            ->assertDontSeeText($post->title);
    }

    public function test_blog_detail_and_invalid_slug(): void
    {
        $post = $this->createPost();

        $this->get(route('blog.show', $post->slug))
            ->assertOk()
            ->assertSeeText($post->content);

        $this->get(route('blog.show', 'missing-post'))->assertNotFound();
    }

    public function test_contact_submission_is_persisted(): void
    {
        $response = $this->post(route('contact.store'), [
            'name' => 'Nguyen Van A',
            'email' => 'contact@example.com',
            'phone' => '0900000000',
            'subject' => 'Tu van',
            'message' => 'Toi can duoc tu van.',
        ]);

        $response->assertRedirect(route('contact'));
        $this->assertDatabaseHas('contact_messages', [
            'email' => 'contact@example.com',
            'subject' => 'Tu van',
        ]);
    }

    public function test_contact_validation_rejects_invalid_submission(): void
    {
        $response = $this->from(route('contact'))->post(route('contact.store'), [
            'name' => '',
            'email' => 'invalid',
            'message' => '',
        ]);

        $response->assertRedirect(route('contact'));
        $response->assertSessionHasErrors(['name', 'email', 'message']);
        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_newsletter_subscription_is_persisted_and_duplicate_is_safe(): void
    {
        $payload = ['email' => 'reader@example.com'];

        $this->post(route('newsletter.subscribe'), $payload)->assertRedirect();
        $this->post(route('newsletter.subscribe'), $payload)->assertRedirect();

        $this->assertDatabaseCount('newsletter_subscribers', 1);
        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'reader@example.com', 'is_active' => true]);
    }

    public function test_newsletter_rejects_invalid_email(): void
    {
        $response = $this->from(route('home'))->post(route('newsletter.subscribe'), ['email' => 'invalid']);

        $response->assertRedirect(route('home'));
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_active_showrooms_are_displayed(): void
    {
        $showroom = Showroom::create([
            'name' => 'Showroom Hà Nội',
            'city' => 'Hà Nội',
            'address' => '18 Trần Hưng Đạo',
            'is_active' => true,
        ]);
        Showroom::create([
            'name' => 'Inactive showroom',
            'city' => 'Hồ Chí Minh',
            'address' => 'Hidden',
            'is_active' => false,
        ]);

        $this->get(route('showrooms'))
            ->assertOk()
            ->assertSeeText($showroom->name)
            ->assertDontSeeText('Inactive showroom');
    }

    public function test_support_policy_routes_render(): void
    {
        foreach (['returns', 'shipping', 'warranty'] as $policy) {
            $this->get(route('policy.show', $policy))->assertOk();
        }

        $this->get(route('policy.show', 'unknown'))->assertNotFound();
    }

    private function createPost(?BlogCategory $category = null, bool $published = true): BlogPost
    {
        return BlogPost::create([
            'blog_category_id' => $category?->id,
            'title' => 'Bài viết ' . uniqid(),
            'slug' => 'bai-viet-' . uniqid(),
            'excerpt' => 'Tóm tắt bài viết',
            'content' => 'Nội dung bài viết thật từ cơ sở dữ liệu.',
            'is_published' => $published,
        ]);
    }
}