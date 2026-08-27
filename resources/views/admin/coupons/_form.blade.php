{{-- Shared form partial for coupon create/edit --}}
<div style="display:grid; gap:16px; max-width:640px;">
    <div>
        <label for="code">Mã giảm giá <span style="color:#b91c1c;">*</span></label>
        <input id="code" name="code" type="text" required maxlength="100"
               value="{{ old('code', $coupon->code ?? '') }}"
               style="width:100%; padding:10px; text-transform:uppercase;"
               placeholder="VD: SUMMER20">
        @error('code')<span class="admin-error">{{ $message }}</span>@enderror
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <div>
            <label for="type">Loại giảm giá <span style="color:#b91c1c;">*</span></label>
            <select id="type" name="type" required style="width:100%; padding:10px;">
                <option value="percent" {{ old('type', $coupon->type ?? 'percent') === 'percent' ? 'selected' : '' }}>Phần trăm (%)</option>
                <option value="fixed" {{ old('type', $coupon->type ?? '') === 'fixed' ? 'selected' : '' }}>Số tiền cố định (đ)</option>
            </select>
            @error('type')<span class="admin-error">{{ $message }}</span>@enderror
        </div>
        <div>
            <label for="value">Giá trị <span style="color:#b91c1c;">*</span></label>
            <input id="value" name="value" type="number" min="0" step="0.01" required
                   value="{{ old('value', $coupon->value ?? '') }}"
                   style="width:100%; padding:10px;"
                   placeholder="VD: 10 (%) hoặc 50000 (đ)">
            @error('value')<span class="admin-error">{{ $message }}</span>@enderror
        </div>
    </div>

    <div>
        <label for="minimum_order_amount">Giá trị đơn hàng tối thiểu (đ)</label>
        <input id="minimum_order_amount" name="minimum_order_amount" type="number" min="0" step="0.01"
               value="{{ old('minimum_order_amount', $coupon->minimum_order_amount ?? '') }}"
               style="width:100%; padding:10px;"
               placeholder="Để trống nếu không giới hạn">
        @error('minimum_order_amount')<span class="admin-error">{{ $message }}</span>@enderror
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <div>
            <label for="starts_at">Bắt đầu</label>
            <input id="starts_at" name="starts_at" type="datetime-local"
                   value="{{ old('starts_at', isset($coupon->starts_at) ? $coupon->starts_at->format('Y-m-d\TH:i') : '') }}"
                   style="width:100%; padding:10px;">
            @error('starts_at')<span class="admin-error">{{ $message }}</span>@enderror
        </div>
        <div>
            <label for="ends_at">Kết thúc</label>
            <input id="ends_at" name="ends_at" type="datetime-local"
                   value="{{ old('ends_at', isset($coupon->ends_at) ? $coupon->ends_at->format('Y-m-d\TH:i') : '') }}"
                   style="width:100%; padding:10px;">
            @error('ends_at')<span class="admin-error">{{ $message }}</span>@enderror
        </div>
    </div>

    <div>
        <label for="usage_limit">Giới hạn lượt dùng</label>
        <input id="usage_limit" name="usage_limit" type="number" min="1" step="1"
               value="{{ old('usage_limit', $coupon->usage_limit ?? '') }}"
               style="width:100%; padding:10px;"
               placeholder="Để trống nếu không giới hạn">
        @error('usage_limit')<span class="admin-error">{{ $message }}</span>@enderror
    </div>

    <div style="display:flex; align-items:center; gap:10px;">
        <input id="is_active" name="is_active" type="checkbox" value="1"
               {{ old('is_active', $coupon->is_active ?? true) ? 'checked' : '' }}>
        <label for="is_active" style="margin:0;">Kích hoạt ngay</label>
        @error('is_active')<span class="admin-error">{{ $message }}</span>@enderror
    </div>
</div>