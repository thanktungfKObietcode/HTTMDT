<?php

namespace App\Http\Controllers;

use App\Models\UserAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AddressController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $addresses = $user->addresses()->where('is_active', true)->get();

        return view('storefront.account.addresses', [
            'pageTitle' => 'Địa chỉ | Silver Atelier',
            'addresses' => $addresses,
        ]);
    }

    public function create(): View
    {
        return view('storefront.account.address-form', [
            'pageTitle' => 'Thêm địa chỉ | Silver Atelier',
            'address' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'province' => 'required|string|max:255',
            'district' => 'required|string|max:255',
            'ward' => 'required|string|max:255',
            'address_line' => 'required|string|max:500',
            'is_default' => 'nullable|boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            auth()->user()->addresses()->update(['is_default' => false]);
        }

        auth()->user()->addresses()->create($validated);

        return redirect()->route('address.index')->with('success', 'Địa chỉ đã được thêm.');
    }

    public function edit(UserAddress $address): View
    {
        $this->authorize('update', $address);

        return view('storefront.account.address-form', [
            'pageTitle' => 'Sửa địa chỉ | Silver Atelier',
            'address' => $address,
        ]);
    }

    public function update(Request $request, UserAddress $address): RedirectResponse
    {
        $this->authorize('update', $address);

        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'province' => 'required|string|max:255',
            'district' => 'required|string|max:255',
            'ward' => 'required|string|max:255',
            'address_line' => 'required|string|max:500',
            'is_default' => 'nullable|boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            auth()->user()->addresses()->update(['is_default' => false]);
        }

        $address->update($validated);

        return redirect()->route('address.index')->with('success', 'Địa chỉ đã được cập nhật.');
    }

    public function delete(UserAddress $address): RedirectResponse
    {
        $this->authorize('delete', $address);

        $address->update(['is_active' => false]);

        return redirect()->route('address.index')->with('success', 'Địa chỉ đã được xóa.');
    }
}
