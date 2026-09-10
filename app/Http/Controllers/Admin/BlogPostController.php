<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Rules\SafeContentReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BlogPostController extends Controller
{
    public function index(Request $request): View
    {
        $posts = BlogPost::with('category')
            ->when($request->filled('q'), fn ($query) => $query->where('title', 'like', '%'.trim($request->string('q')->toString()).'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('is_published', $request->input('status') === 'published'))
            ->when($request->filled('category_id'), fn ($query) => $query->where('blog_category_id', $request->integer('category_id')))
            ->latest()->paginate(15)->withQueryString();

        return view('admin.blog-posts.index', ['pageTitle' => 'Bài viết', 'posts' => $posts, 'categories' => $this->categories()]);
    }

    public function create(): View
    {
        return view('admin.blog-posts.create', ['pageTitle' => 'Thêm bài viết', 'post' => null, 'categories' => $this->categories()]);
    }

    public function store(Request $request): RedirectResponse
    {
        BlogPost::create($this->validated($request));
        return redirect()->route('admin.blog-posts.index')->with('success', 'Bài viết đã được tạo.');
    }

    public function edit(BlogPost $blogPost): View
    {
        return view('admin.blog-posts.edit', ['pageTitle' => 'Sửa bài viết', 'post' => $blogPost, 'categories' => $this->categories()]);
    }

    public function update(Request $request, BlogPost $blogPost): RedirectResponse
    {
        $blogPost->update($this->validated($request, $blogPost));
        return redirect()->route('admin.blog-posts.index')->with('success', 'Bài viết đã được cập nhật.');
    }

    private function validated(Request $request, ?BlogPost $post = null): array
    {
        $data = $request->validate([
            'blog_category_id' => 'nullable|exists:blog_categories,id',
            'title' => 'required|string|max:255',
            'slug' => ['required', 'alpha_dash:ascii', 'max:255', Rule::unique('blog_posts')->ignore($post?->id)],
            'excerpt' => 'nullable|string|max:2000',
            'content' => 'required|string',
            'featured_image' => ['nullable', 'string', 'max:2048', new SafeContentReference],
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
        ]);
        $data['is_published'] = (bool) ($data['is_published'] ?? false);
        return $data;
    }

    private function categories()
    {
        return BlogCategory::orderBy('name')->get();
    }
}
