<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Showroom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShowroomController extends Controller
{
    public function index(): View
    {
        return view('admin.showrooms.index', ['pageTitle' => 'Showroom', 'showrooms' => Showroom::orderBy('city')->orderBy('name')->get()]);
    }
    public function create(): View { return view('admin.showrooms.create', ['pageTitle' => 'Thêm showroom', 'showroom' => null]); }
    public function edit(Showroom $showroom): View { return view('admin.showrooms.edit', ['pageTitle' => 'Sửa showroom', 'showroom' => $showroom]); }
    public function store(Request $request): RedirectResponse
    {
        Showroom::create($this->validated($request));
        return redirect()->route('admin.showrooms.index')->with('success', 'Showroom đã được tạo.');
    }
    public function update(Request $request, Showroom $showroom): RedirectResponse
    {
        $showroom->update($this->validated($request));
        return redirect()->route('admin.showrooms.index')->with('success', 'Showroom đã được cập nhật.');
    }
    public function destroy(Showroom $showroom): RedirectResponse
    {
        $showroom->update(['is_active' => false]);
        return back()->with('success', 'Showroom đã được ngừng hiển thị.');
    }
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'city' => 'required|string|max:255',
            'address' => 'required|string|max:500', 'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255', 'map_url' => 'nullable|url:http,https|max:2048',
            'business_hours_text' => 'nullable|string|max:1000', 'is_active' => 'nullable|boolean',
        ]);
        $hours = trim((string) ($data['business_hours_text'] ?? ''));
        unset($data['business_hours_text']);
        $data['business_hours'] = $hours === '' ? null : ['display' => $hours];
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        return $data;
    }
}
