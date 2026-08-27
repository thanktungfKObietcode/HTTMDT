{{-- Shared form partial for shipping create/edit --}}
<div style="display:grid; gap:16px; max-width:640px;">
    <div>
        <label for="name">Tên phương thức <span style="color:#b91c1c;">*</span></label>
        <input id="name" name="name" type="text" required maxlength="255"
               value="{{ old('name', $method->name ?? '') }}"
               style="width:100%; padding:10px;"
               placeholder="VD: Giao hàng tiêu chuẩn">
        @error('name')<span class="admin-error">{{ $message }}</span>@enderror
    </div>

    <div>
        <label for="code">Mã (Code) <span style="color:#b91c1c;">*</span></label>
        <input id="code" name="code" type="text" required maxlength="100"
               value="{{ old('code', $method->code ?? '') }}"
               style="width:100%; padding:10px; text-transform:uppercase;"
               placeholder="VD: STANDARD">
        @error('code')<span class="admin-error">{{ $message }}</span>@enderror
    </div>

    <div>
        <label for="description">Mô tả</label>
        <textarea id="description" name="description" rows="3" style="width:100%; padding:10px;">{{ old('description', $method->description ?? '') }}</textarea>
        @error('description')<span class="admin-error">{{ $message }}</span>@enderror
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <div>
            <label for="base_fee">Phí cơ bản (đ) <span style="color:#b91c1c;">*</span></label>
            <input id="base_fee" name="base_fee" type="number" min="0" step="0.01" required
                   value="{{ old('base_fee', isset($method) ? (float)$method->base_fee : 0) }}"
                   style="width:100%; padding:10px;">
            @error('base_fee')<span class="admin-error">{{ $message }}</span>@enderror
        </div>
        <div>
            <label for="fee_per_km">Phí mỗi KM (đ) <span style="color:#b91c1c;">*</span></label>
            <input id="fee_per_km" name="fee_per_km" type="number" min="0" step="0.01" required
                   value="{{ old('fee_per_km', isset($method) ? (float)$method->fee_per_km : 0) }}"
                   style="width:100%; padding:10px;">
            @error('fee_per_km')<span class="admin-error">{{ $message }}</span>@enderror
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <div>
            <label for="estimated_days_min">Thời gian giao tối thiểu (ngày)</label>
            <input id="estimated_days_min" name="estimated_days_min" type="number" min="0" step="1"
                   value="{{ old('estimated_days_min', $method->estimated_days_min ?? '') }}"
                   style="width:100%; padding:10px;" placeholder="VD: 2">
            @error('estimated_days_min')<span class="admin-error">{{ $message }}</span>@enderror
        </div>
        <div>
            <label for="estimated_days_max">Thời gian giao tối đa (ngày)</label>
            <input id="estimated_days_max" name="estimated_days_max" type="number" min="0" step="1"
                   value="{{ old('estimated_days_max', $method->estimated_days_max ?? '') }}"
                   style="width:100%; padding:10px;" placeholder="VD: 4">
            @error('estimated_days_max')<span class="admin-error">{{ $message }}</span>@enderror
        </div>
    </div>

    <div style="display:flex; align-items:center; gap:10px;">
        <input id="is_active" name="is_active" type="checkbox" value="1"
               {{ old('is_active', $method->is_active ?? true) ? 'checked' : '' }}>
        <label for="is_active" style="margin:0;">Kích hoạt phương thức</label>
        @error('is_active')<span class="admin-error">{{ $message }}</span>@enderror
    </div>
</div>
