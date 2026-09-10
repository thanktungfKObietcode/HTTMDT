<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Rules\SafeContentReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BannerController extends Controller
{
    public function index(): View
    {
        return view('admin.banners.index', ['pageTitle' => 'Banner', 'banners' => Banner::orderBy('position')->orderBy('sort_order')->get()]);
    }

    public function create(): View
    {
        return view('admin.banners.create', ['pageTitle' => 'Thêm banner', 'banner' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        Banner::create($this->validated($request));
        return redirect()->route('admin.banners.index')->with('success', 'Banner đã được tạo.');
    }

    public function edit(Banner $banner): View
    {
        return view('admin.banners.edit', ['pageTitle' => 'Sửa banner', 'banner' => $banner]);
    }

    public function update(Request $request, Banner $banner): RedirectResponse
    {
        $banner->update($this->validated($request));
        return redirect()->route('admin.banners.index')->with('success', 'Banner đã được cập nhật.');
    }

    public function destroy(Banner $banner): RedirectResponse
    {
        $banner->update(['is_active' => false]);
        return back()->with('success', 'Banner đã được ngừng hiển thị.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'image' => ['required', 'string', 'max:2048', new SafeContentReference],
            'link' => ['nullable', 'string', 'max:2048', new SafeContentReference],
            'position' => 'required|in:home,catalog,blog',
            'sort_order' => 'nullable|integer|min:0|max:4294967295',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|required_with:ends_at|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ]);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        return $data;
    }
}
