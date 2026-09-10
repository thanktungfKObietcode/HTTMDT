<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BlogCategoryController extends Controller
{
    public function index(): View
    {
        return view('admin.blog-categories.index', [
            'pageTitle' => 'Chuyên mục bài viết',
            'categories' => BlogCategory::withCount('posts')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        BlogCategory::create($this->validated($request));

        return back()->with('success', 'Chuyên mục đã được tạo.');
    }

    public function update(Request $request, BlogCategory $blogCategory): RedirectResponse
    {
        $blogCategory->update($this->validated($request, $blogCategory));

        return back()->with('success', 'Chuyên mục đã được cập nhật.');
    }

    public function destroy(BlogCategory $blogCategory): RedirectResponse
    {
        $blogCategory->update(['is_active' => false]);

        return back()->with('success', 'Chuyên mục đã được ngừng hiển thị.');
    }

    private function validated(Request $request, ?BlogCategory $category = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'alpha_dash:ascii', 'max:255', Rule::unique('blog_categories')->ignore($category?->id)],
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = (bool) ($data['is_active'] ?? $category?->is_active ?? true);

        return $data;
    }
}
