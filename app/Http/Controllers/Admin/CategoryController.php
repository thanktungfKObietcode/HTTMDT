<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Rules\SafeContentReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $categories = Category::withCount(['products', 'children'])
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%' . trim($request->string('q')->toString()) . '%'))
            ->orderBy('name')
            ->paginate(15)
            ->appends($request->query());

        return view('admin.categories.index', [
            'pageTitle' => 'Quản lý danh mục',
            'categories' => $categories,
        ]);
    }

    public function create(): View
    {
        return view('admin.categories.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        Category::create($this->validated($request));

        return redirect()->route('admin.categories.index')->with('success', 'Danh mục đã được tạo.');
    }

    public function edit(Category $category): View
    {
        return view('admin.categories.edit', array_merge($this->formData(), ['category' => $category]));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $category->update($this->validated($request, $category));

        return redirect()->route('admin.categories.index')->with('success', 'Danh mục đã được cập nhật.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->products()->exists() || $category->children()->exists()) {
            $category->update(['is_active' => false]);

            return back()->with('success', 'Danh mục đã được ngừng sử dụng để bảo toàn dữ liệu liên quan.');
        }

        $category->delete();

        return back()->with('success', 'Danh mục đã được xóa.');
    }

    private function formData(): array
    {
        return [
            'pageTitle' => 'Danh mục',
            'category' => null,
            'parents' => Category::orderBy('name')->get(),
        ];
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query->where('is_active', true)),
                Rule::notIn([$category?->id]),
            ],
            'description' => 'nullable|string',
            'image' => ['nullable', 'string', 'max:2048', new SafeContentReference],
            'is_active' => 'nullable|boolean',
        ]);
    }
}
