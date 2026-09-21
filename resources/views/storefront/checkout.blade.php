@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Thanh toán</span>
            <h1>Xác nhận đơn hàng</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; grid-template-columns:minmax(0, 1.3fr) minmax(320px, 1fr); gap:24px; align-items:start;">
            <form method="POST" action="{{ route('checkout.store') }}" class="filter-box" style="display:grid; gap:20px;">
                @csrf
                <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">

                @if($errors->has('checkout'))
                    <div style="padding:10px 12px; border-radius:8px; background:#ffe8e8; color:#8a1f1f;">
                        {{ $errors->first('checkout') }}
                    </div>
                @endif

                <div>
                    <h3 style="margin-top:0;">Thông tin khách hàng</h3>
                    <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));">
                        <div>
                            <label for="customer_name" style="display:block; margin-bottom:6px; font-weight:600;">Họ tên</label>
                            <input id="customer_name" name="customer_name" value="{{ old('customer_name', $user?->name) }}" required style="width:100%; padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                            @error('customer_name')<small style="color:#c62828;">{{ $message }}</small>@enderror
                        </div>
                        <div>
                            <label for="customer_phone" style="display:block; margin-bottom:6px; font-weight:600;">Số điện thoại</label>
                            <input id="customer_phone" name="customer_phone" value="{{ old('customer_phone', $user?->phone) }}" required style="width:100%; padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                            @error('customer_phone')<small style="color:#c62828;">{{ $message }}</small>@enderror
                        </div>
                        <div style="grid-column:1 / -1;">
                            <label for="customer_email" style="display:block; margin-bottom:6px; font-weight:600;">Email</label>
                            <input id="customer_email" name="customer_email" type="email" value="{{ old('customer_email', $user?->email) }}" style="width:100%; padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                            @error('customer_email')<small style="color:#c62828;">{{ $message }}</small>@enderror
                        </div>
                    </div>
                </div>

                @if($savedAddresses->isNotEmpty())
                    <div>
                        <h3>Địa chỉ đã lưu</h3>
                        <div style="display:grid; gap:8px; margin-bottom:12px;">
                            @foreach($savedAddresses as $address)
                                <label style="display:flex; gap:8px; align-items:flex-start; border:1px solid rgba(31,28,26,0.1); border-radius:8px; padding:10px;">
                                    <input type="radio" name="saved_address" value="{{ $address->id }}" data-address='@json($address)' {{ old('saved_address', $defaultAddress?->id) == $address->id ? 'checked' : '' }}>
                                    <span>
                                        <strong>{{ $address->full_name }}</strong> - {{ $address->phone }}<br>
                                        {{ $address->address_line }}, {{ $address->ward }}, {{ $address->district }}, {{ $address->province }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div>
                    <h3>Địa chỉ giao hàng</h3>
                    <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));">
                        <div>
                            <label for="province" style="display:block; margin-bottom:6px; font-weight:600;">Tỉnh/Thành</label>
                            <input id="province" name="province" value="{{ old('province', $defaultAddress?->province) }}" required style="width:100%; padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                            @error('province')<small style="color:#c62828;">{{ $message }}</small>@enderror
                        </div>
                        <div>
                            <label for="district" style="display:block; margin-bottom:6px; font-weight:600;">Quận/Huyện</label>
                            <input id="district" name="district" value="{{ old('district', $defaultAddress?->district) }}" required style="width:100%; padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                            @error('district')<small style="color:#c62828;">{{ $message }}</small>@enderror
                        </div>
                        <div>
                            <label for="ward" style="display:block; margin-bottom:6px; font-weight:600;">Phường/Xã</label>
                            <input id="ward" name="ward" value="{{ old('ward', $defaultAddress?->ward) }}" required style="width:100%; padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                            @error('ward')<small style="color:#c62828;">{{ $message }}</small>@enderror
                        </div>
                        <div style="grid-column:1 / -1;">
                            <label for="address_line" style="display:block; margin-bottom:6px; font-weight:600;">Địa chỉ chi tiết</label>
                            <textarea id="address_line" name="address_line" required style="width:100%; min-height:84px; padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px; font-family:inherit;">{{ old('address_line', $defaultAddress?->address_line) }}</textarea>
                            @error('address_line')<small style="color:#c62828;">{{ $message }}</small>@enderror
                        </div>
                    </div>
                </div>

                <div>
                    <h3>Vận chuyển và thanh toán</h3>
                    <div style="display:grid; gap:12px;">
                        @foreach($shippingMethods as $method)
                            <label style="display:flex; gap:10px; align-items:flex-start; border:1px solid rgba(31,28,26,0.1); border-radius:8px; padding:10px;">
                                <input type="radio" name="shipping_method_id" value="{{ $method->id }}" data-fee="{{ $method->base_fee }}" {{ old('shipping_method_id', $loop->first ? $method->id : null) == $method->id ? 'checked' : '' }}>
                                <span>
                                    <strong>{{ $method->name }}</strong> - {{ number_format((float) $method->base_fee, 0, ',', '.') }}đ<br>
                                    <small>{{ $method->description }}</small>
                                    @if($method->estimated_days_min && $method->estimated_days_max)
                                        <small style="display:block;">Dự kiến {{ $method->estimated_days_min }}-{{ $method->estimated_days_max }} ngày</small>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                        @error('shipping_method_id')<small style="color:#c62828;">{{ $message }}</small>@enderror
                    </div>

                    <div style="margin-top:12px; display:grid; gap:8px;">
                        <label for="coupon_code" style="font-weight:600;">Mã giảm giá</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <input id="coupon_code" name="coupon_code" value="{{ $couponCode }}" placeholder="Nhập mã nếu có" style="flex:1; min-width:180px; padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                            <button type="button" id="apply-coupon" class="btn btn-secondary" data-action="{{ route('checkout.index') }}">Áp dụng</button>
                        </div>
                        @if ($couponError)
                            <small style="color:#c62828;">{{ $couponError }}</small>
                        @elseif ($couponCode !== '')
                            <small style="color:#2e7d32;">Mã giảm giá hợp lệ.</small>
                        @endif
                        <input type="hidden" name="coupon_code" value="{{ $couponCode }}">
                    </div>

                    <div style="margin-top:12px; display:grid; gap:8px;">
                        <label for="payment_method" style="font-weight:600;">Phương thức thanh toán</label>
                        <select id="payment_method" name="payment_method" required style="width:100%; padding:10px 12px; border:1px solid rgba(31,28,26,0.12); border-radius:8px;">
                            <option value="cod" {{ old('payment_method', 'cod') === 'cod' ? 'selected' : '' }}>Thanh toán khi nhận hàng (COD)</option>
                            @if(in_array('vnpay', $paymentMethods, true))
                                <option value="vnpay" @selected(old('payment_method') === 'vnpay')>VNPay (Sandbox — thanh toán thử nghiệm)</option>
                            @endif
                            @if(in_array('momo', $paymentMethods, true))
                                <option value="momo" @selected(old('payment_method') === 'momo')>MoMo (Sandbox — thanh toán thử nghiệm)</option>
                            @endif
                        </select>
                        @error('payment_method')<small style="color:#c62828;">{{ $message }}</small>@enderror
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;">Đặt hàng</button>
            </form>

            <aside class="filter-box" data-subtotal="{{ $subtotal }}" data-discount="{{ $couponDiscount }}" style="display:grid; gap:16px; position:sticky; top:20px;">
                <h3 style="margin:0;">Tóm tắt đơn hàng</h3>
                <div style="display:grid; gap:12px;">
                    @foreach($cartEntries as $entry)
                        @php
                            $item = $entry['item'];
                            $product = $entry['product'];
                            $variant = $entry['variant'];
                        @endphp
                        <div style="display:grid; grid-template-columns:54px 1fr auto; gap:10px; align-items:start; border-bottom:1px solid rgba(31,28,26,0.08); padding-bottom:10px;">
                            <img src="{{ \App\Support\MediaUrl::resolve($product->featured_image) }}" alt="{{ $product->name }}" style="width:54px; height:54px; object-fit:cover; border-radius:10px;">
                            <div>
                                <strong style="font-size:14px;">{{ $product->name }}</strong>
                                <small style="display:block; color:#666;">SKU: {{ $variant?->sku ?: $product->sku }}</small>
                                @if($variant)
                                    <small style="display:block; color:#666;">{{ trim(($variant->size ? 'Size ' . $variant->size : '') . ($variant->color ? ' / ' . $variant->color : '')) }}</small>
                                @endif
                                <small style="display:block; color:#666;">SL: {{ $item->quantity }}</small>
                            </div>
                            <strong>{{ $entry['line_total_display'] }}</strong>
                        </div>
                    @endforeach
                </div>

                <div style="display:grid; gap:6px;">
                    <div style="display:flex; justify-content:space-between;"><span>Tạm tính</span><strong>{{ number_format($subtotal, 0, ',', '.') }}đ</strong></div>
                    <div style="display:flex; justify-content:space-between;"><span>Phí vận chuyển</span><strong id="checkout-shipping-fee">{{ number_format((float) ($selectedShippingMethod?->base_fee ?? 0), 0, ',', '.') }}đ</strong></div>
                    <div style="display:flex; justify-content:space-between;"><span>Giảm giá</span><strong id="checkout-discount">-{{ number_format((float) $couponDiscount, 0, ',', '.') }}đ</strong></div>
                    <div style="display:flex; justify-content:space-between; border-top:1px solid rgba(31,28,26,0.1); padding-top:8px;"><span>Tổng thanh toán</span><strong id="checkout-total">{{ number_format((float) ($subtotal + ($selectedShippingMethod?->base_fee ?? 0) - $couponDiscount), 0, ',', '.') }}đ</strong></div>
                </div>
            </aside>
        </div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const radios = document.querySelectorAll('input[name="saved_address"]');
            const map = {
                province: document.getElementById('province'),
                district: document.getElementById('district'),
                ward: document.getElementById('ward'),
                address_line: document.getElementById('address_line'),
                customer_name: document.getElementById('customer_name'),
                customer_phone: document.getElementById('customer_phone'),
            };

            radios.forEach((radio) => {
                radio.addEventListener('change', () => {
                    const data = JSON.parse(radio.dataset.address || '{}');
                    if (map.customer_name && data.full_name) map.customer_name.value = data.full_name;
                    if (map.customer_phone && data.phone) map.customer_phone.value = data.phone;
                    if (map.province && data.province) map.province.value = data.province;
                    if (map.district && data.district) map.district.value = data.district;
                    if (map.ward && data.ward) map.ward.value = data.ward;
                    if (map.address_line && data.address_line) map.address_line.value = data.address_line;
                });
            });

            const summary = document.querySelector('[data-subtotal]');
            const shippingFee = document.getElementById('checkout-shipping-fee');
            const discount = document.getElementById('checkout-discount');
            const total = document.getElementById('checkout-total');
            const shippingRadios = document.querySelectorAll('input[name="shipping_method_id"]');
            const applyCouponButton = document.getElementById('apply-coupon');

            if (summary && shippingFee && discount && total) {
                const subtotal = Number(summary.dataset.subtotal || 0);
                const couponDiscount = Number(summary.dataset.discount || 0);
                const formatMoney = (value) => `${Math.max(0, value).toLocaleString('vi-VN')}đ`;

                const updateSummary = () => {
                    const selected = document.querySelector('input[name="shipping_method_id"]:checked');
                    const fee = Number(selected?.dataset.fee || 0);
                    shippingFee.textContent = formatMoney(fee);
                    discount.textContent = `-${formatMoney(couponDiscount)}`;
                    total.textContent = formatMoney(subtotal + fee - couponDiscount);
                };

                shippingRadios.forEach((radio) => radio.addEventListener('change', updateSummary));
                updateSummary();
            }

            if (applyCouponButton) {
                applyCouponButton.addEventListener('click', () => {
                    const couponInput = document.getElementById('coupon_code');
                    const couponCode = couponInput?.value.trim() || '';
                    const action = applyCouponButton.dataset.action;
                    const url = new URL(action, window.location.origin);

                    if (couponCode) {
                        url.searchParams.set('coupon_code', couponCode);
                    }

                    window.location.assign(url.toString());
                });
            }
        });
    </script>
@endsection
