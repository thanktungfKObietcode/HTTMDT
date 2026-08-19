<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaterialController extends Controller
{
    public function index(Request $request): View
    {
        $materials = Material::withCount('products')
            ->when($request->filled('q'), fn ($query) => $query->where(function ($builder) use ($request) {
                $term = '%' . trim($request->string('q')->toString()) . '%';
                $builder->where('name', 'like', $term)->orWhere('code', 'like', $term);
            }))
            ->orderBy('name')
            ->paginate(15)
            ->appends($request->query());

        return view('admin.materials.index', ['pageTitle' => 'Quản lý chất liệu', 'materials' => $materials]);
    }

    public function create(): View
    {
        return view('admin.materials.create', ['pageTitle' => 'Thêm chất liệu', 'material' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        Material::create($this->validated($request));

        return redirect()->route('admin.materials.index')->with('success', 'Chất liệu đã được tạo.');
    }

    public function edit(Material $material): View
    {
        return view('admin.materials.edit', ['pageTitle' => 'Chỉnh sửa chất liệu', 'material' => $material]);
    }

    public function update(Request $request, Material $material): RedirectResponse
    {
        $material->update($this->validated($request, $material));

        return redirect()->route('admin.materials.index')->with('success', 'Chất liệu đã được cập nhật.');
    }

    public function destroy(Material $material): RedirectResponse
    {
        if ($material->products()->exists()) {
            $material->update(['is_active' => false]);

            return back()->with('success', 'Chất liệu đã được ngừng sử dụng để bảo toàn sản phẩm liên quan.');
        }

        $material->delete();

        return back()->with('success', 'Chất liệu đã được xóa.');
    }

    private function validated(Request $request, ?Material $material = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('materials', 'name')->ignore($material?->id)],
            'code' => ['required', 'string', 'max:255', Rule::unique('materials', 'code')->ignore($material?->id)],
            'purity' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
    }
}