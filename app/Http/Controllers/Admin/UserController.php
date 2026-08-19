<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::with('roles')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%' . trim($request->string('q')->toString()) . '%';
                $query->where(fn ($builder) => $builder->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn ($query) => $query->whereHas('roles', fn ($roleQuery) => $roleQuery->where('name', $request->input('role'))))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->latest()
            ->paginate(15)
            ->appends($request->query());

        return view('admin.users.index', [
            'pageTitle' => 'Quản lý người dùng',
            'users' => $users,
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function show(User $user): View
    {
        $user->load([
            'roles',
            'addresses' => fn ($query) => $query->where('is_active', true)->latest(),
            'orders' => fn ($query) => $query->latest(),
            'reviews.product',
        ]);

        return view('admin.users.show', [
            'pageTitle' => 'Chi tiết người dùng',
            'user' => $user,
            'orderTotal' => $user->orders->sum(fn ($order) => (float) $order->total_amount),
        ]);
    }

    public function deactivate(User $user): RedirectResponse
    {
        if ((int) $user->id === (int) auth()->id()) {
            return back()->withErrors(['status' => 'Bạn không thể tự vô hiệu hóa tài khoản đang đăng nhập.']);
        }

        $user->update(['is_active' => false]);

        return back()->with('success', 'Người dùng đã được vô hiệu hóa.');
    }
}