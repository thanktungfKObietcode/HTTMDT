<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Rules\SafeContentReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CollectionController extends Controller
{
    public function index(Request $request): View
    {
        $collections = Collection::withCount('products')
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%' . trim($request->string('q')->toString()) . '%'))
            ->orderBy('name')
            ->paginate(15)
            ->appends($request->query());

        return view('admin.collections.index', ['pageTitle' => 'Quản lý bộ sưu tập', 'collections' => $collections]);
    }

    public function create(): View
    {
        return view('admin.collections.create', ['pageTitle' => 'Thêm bộ sưu tập', 'collection' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        Collection::create($this->validated($request));

        return redirect()->route('admin.collections.index')->with('success', 'Bộ sưu tập đã được tạo.');
    }

    public function edit(Collection $collection): View
    {
        return view('admin.collections.edit', ['pageTitle' => 'Chỉnh sửa bộ sưu tập', 'collection' => $collection]);
    }

    public function update(Request $request, Collection $collection): RedirectResponse
    {
        $collection->update($this->validated($request, $collection));

        return redirect()->route('admin.collections.index')->with('success', 'Bộ sưu tập đã được cập nhật.');
    }

    public function destroy(Collection $collection): RedirectResponse
    {
        if ($collection->products()->exists()) {
            $collection->update(['is_active' => false]);

            return back()->with('success', 'Bộ sưu tập đã được ngừng sử dụng để bảo toàn sản phẩm liên quan.');
        }

        $collection->delete();

        return back()->with('success', 'Bộ sưu tập đã được xóa.');
    }

    private function validated(Request $request, ?Collection $collection = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('collections', 'slug')->ignore($collection?->id)],
            'description' => 'nullable|string',
            'image' => ['nullable', 'string', 'max:2048', new SafeContentReference],
            'is_active' => 'nullable|boolean',
        ]);
    }
}
