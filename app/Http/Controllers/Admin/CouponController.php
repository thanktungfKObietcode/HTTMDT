<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index(Request $request): View
    {
        $coupons = Coupon::withCount('usages')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%' . trim($request->string('q')->toString()) . '%';
                $query->where('code', 'like', $term);
            })
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->latest()
            ->paginate(15)
            ->appends($request->query());

        return view('admin.coupons.index', [
            'pageTitle' => 'Quản lý mã giảm giá',
            'coupons'   => $coupons,
        ]);
    }

    public function create(): View
    {
        return view('admin.coupons.create', [
            'pageTitle' => 'Tạo mã giảm giá',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateCoupon($request);

        Coupon::create($validated);

        return redirect()->route('admin.coupons.index')->with('success', 'Mã giảm giá đã được tạo.');
    }

    public function edit(Coupon $coupon): View
    {
        return view('admin.coupons.edit', [
            'pageTitle' => 'Chỉnh sửa mã giảm giá',
            'coupon'    => $coupon,
        ]);
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $validated = $this->validateCoupon($request, $coupon);

        $coupon->update($validated);

        return redirect()->route('admin.coupons.index')->with('success', 'Mã giảm giá đã được cập nhật.');
    }

    public function activate(Coupon $coupon): RedirectResponse
    {
        $coupon->update(['is_active' => true]);

        return back()->with('success', 'Mã giảm giá đã được kích hoạt.');
    }

    public function deactivate(Coupon $coupon): RedirectResponse
    {
        $coupon->update(['is_active' => false]);

        return back()->with('success', 'Mã giảm giá đã được vô hiệu hóa.');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $usageCount = CouponUsage::where('coupon_id', $coupon->id)->count();

        if ($usageCount > 0) {
            return back()->withErrors([
                'delete' => 'Không thể xóa mã giảm giá đã được sử dụng trong đơn hàng. Vui lòng vô hiệu hóa thay thế.',
            ]);
        }

        $coupon->delete();

        return redirect()->route('admin.coupons.index')->with('success', 'Mã giảm giá đã được xóa.');
    }

    private function validateCoupon(Request $request, ?Coupon $coupon = null): array
    {
        $request->merge([
            'is_active' => $request->has('is_active'),
        ]);

        return $request->validate([
            'code'                 => [
                'required',
                'string',
                'max:100',
                Rule::unique('coupons', 'code')->ignore($coupon?->id),
            ],
            'type'                 => ['required', Rule::in(['percent', 'fixed'])],
            'value'                => ['required', 'numeric', 'min:0'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'starts_at'            => ['nullable', 'date'],
            'ends_at'              => ['nullable', 'date', 'after_or_equal:starts_at'],
            'usage_limit'          => ['nullable', 'integer', 'min:1'],
            'is_active'            => ['boolean'],
        ]);
    }
}