<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Models\Setting;
use App\Models\Showroom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorefrontContentController extends Controller
{
    public function blogIndex(Request $request): View
    {
        $category = $request->input('category');
        $posts = BlogPost::with('category')
            ->where('is_published', true)
            ->when($category, fn ($query) => $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('slug', $category)))
            ->latest()
            ->paginate(9)
            ->appends($request->query());

        return view('storefront.blog.index', [
            'pageTitle' => 'Tin tức | Silver Atelier',
            'posts' => $posts,
            'categories' => BlogCategory::orderBy('name')->get(),
            'selectedCategory' => $category,
        ]);
    }

    public function blogShow(string $slug): View
    {
        $post = BlogPost::with('category')
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        $relatedPosts = BlogPost::with('category')
            ->where('is_published', true)
            ->where('id', '!=', $post->id)
            ->when($post->blog_category_id, fn ($query) => $query->where('blog_category_id', $post->blog_category_id))
            ->latest()
            ->limit(3)
            ->get();

        return view('storefront.blog.show', [
            'pageTitle' => $post->title . ' | Silver Atelier',
            'post' => $post,
            'relatedPosts' => $relatedPosts,
        ]);
    }

    public function contact(): View
    {
        return view('storefront.contact', [
            'pageTitle' => 'Liên hệ | Silver Atelier',
            'settings' => Setting::whereIn('key', ['app_email', 'app_phone'])->pluck('value', 'key'),
        ]);
    }

    public function submitContact(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:30',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        ContactMessage::create($validated);

        return redirect()->route('contact')->with('success', 'Tin nhắn của bạn đã được gửi.');
    }

    public function subscribe(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:255']);

        $subscriber = NewsletterSubscriber::firstOrCreate(
            ['email' => strtolower($validated['email'])],
            ['is_active' => true]
        );

        if (! $subscriber->is_active) {
            $subscriber->update(['is_active' => true]);
        }

        return back()->with('newsletter_success', 'Bạn đã đăng ký nhận thông tin thành công.');
    }

    public function showrooms(): View
    {
        return view('storefront.showrooms', [
            'pageTitle' => 'Showroom | Silver Atelier',
            'showrooms' => Showroom::where('is_active', true)->orderBy('city')->orderBy('name')->get(),
        ]);
    }

    public function policy(string $policy): View
    {
        abort_unless(in_array($policy, ['returns', 'shipping', 'warranty'], true), 404);

        return view('storefront.policy', [
            'pageTitle' => match ($policy) {
                'returns' => 'Chính sách đổi trả | Silver Atelier',
                'shipping' => 'Chính sách vận chuyển | Silver Atelier',
                'warranty' => 'Chính sách bảo hành | Silver Atelier',
            },
            'policy' => $policy,
        ]);
    }
}