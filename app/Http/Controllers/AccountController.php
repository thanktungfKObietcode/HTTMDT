<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        return view('storefront.account.index', [
            'pageTitle' => 'Tài khoản | Silver Atelier',
            'user' => $user,
        ]);
    }

    public function editProfile(): View
    {
        $user = auth()->user();

        return view('storefront.account.profile', [
            'pageTitle' => 'Chỉnh sửa thông tin | Silver Atelier',
            'user' => $user,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . auth()->id(),
            'phone' => 'nullable|string|max:20',
            'gender' => 'nullable|in:male,female,other',
            'birth_date' => 'nullable|date|before:today',
            'receive_newsletter' => 'nullable|boolean',
        ]);

        auth()->user()->update($validated);

        return redirect()->route('account.index')->with('success', 'Thông tin tài khoản đã được cập nhật.');
    }

    public function changePassword(): View
    {
        return view('storefront.account.password', [
            'pageTitle' => 'Đổi mật khẩu | Silver Atelier',
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        auth()->user()->update([
            'password' => bcrypt($validated['new_password']),
        ]);

        return redirect()->route('account.index')->with('success', 'Mật khẩu đã được thay đổi.');
    }
}
