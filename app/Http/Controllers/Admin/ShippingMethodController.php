<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class ShippingMethodController extends Controller
{
    public function index(Request $request): View
    {
        $methods = ShippingMethod::withCount('orders')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%' . trim($request->string('q')->toString()) . '%';
                $query->where('name', 'like', $term)->orWhere('code', 'like', $term);
            })
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->latest()
            ->paginate(15)
            ->appends($request->query());

        return view('admin.shipping.index', [
            'pageTitle' => 'Quản lý phương thức vận chuyển',
            'methods'   => $methods,
        ]);
    }

    public function create(): View
    {
        return view('admin.shipping.create', [
            'pageTitle' => 'Tạo phương thức vận chuyển',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateShippingMethod($request);

        ShippingMethod::create($validated);

        return redirect()->route('admin.shipping.index')->with('success', 'Phương thức vận chuyển đã được tạo.');
    }

    public function edit(ShippingMethod $shipping): View
    {
        return view('admin.shipping.edit', [
            'pageTitle' => 'Chỉnh sửa phương thức vận chuyển',
            'method'    => $shipping,
        ]);
    }

    public function update(Request $request, ShippingMethod $shipping): RedirectResponse
    {
        $validated = $this->validateShippingMethod($request, $shipping);

        $shipping->update($validated);

        return redirect()->route('admin.shipping.index')->with('success', 'Phương thức vận chuyển đã được cập nhật.');
    }

    public function activate(ShippingMethod $shipping): RedirectResponse
    {
        $shipping->update(['is_active' => true]);

        return back()->with('success', 'Phương thức vận chuyển đã được kích hoạt.');
    }

    public function deactivate(ShippingMethod $shipping): RedirectResponse
    {
        $shipping->update(['is_active' => false]);

        return back()->with('success', 'Phương thức vận chuyển đã được vô hiệu hóa.');
    }

    public function destroy(ShippingMethod $shipping): RedirectResponse
    {
        if ($shipping->orders()->exists()) {
            return back()->withErrors([
                'delete' => 'Không thể xóa phương thức vận chuyển đã được sử dụng trong đơn hàng. Vui lòng vô hiệu hóa thay thế.',
            ]);
        }

        $shipping->delete();

        return redirect()->route('admin.shipping.index')->with('success', 'Phương thức vận chuyển đã được xóa.');
    }

    private function validateShippingMethod(Request $request, ?ShippingMethod $method = null): array
    {
        $request->merge([
            'is_active' => $request->has('is_active'),
        ]);

        return $request->validate([
            'name'                 => ['required', 'string', 'max:255'],
            'code'                 => [
                'required',
                'string',
                'max:100',
                Rule::unique('shipping_methods', 'code')->ignore($method?->id),
            ],
            'description'          => ['nullable', 'string'],
            'base_fee'             => ['required', 'numeric', 'min:0'],
            'fee_per_km'           => ['required', 'numeric', 'min:0'],
            'estimated_days_min'   => ['nullable', 'integer', 'min:0'],
            'estimated_days_max'   => ['nullable', 'integer', 'min:0', 'gte:estimated_days_min'],
            'is_active'            => ['boolean'],
        ]);
    }
}