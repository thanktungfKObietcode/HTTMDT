@csrf
<div style="display:grid; gap:16px;">
    <div><label for="name">Tên danh mục *</label><input id="name" name="name" value="{{ old('name', $category?->name) }}" required style="width:100%; padding:10px;">@error('name')<small class="admin-error">{{ $message }}</small>@enderror</div>
    <div><label for="slug">Slug *</label><input id="slug" name="slug" value="{{ old('slug', $category?->slug) }}" required style="width:100%; padding:10px;">@error('slug')<small class="admin-error">{{ $message }}</small>@enderror</div>
    <div><label for="parent_id">Danh mục cha</label><select id="parent_id" name="parent_id" style="width:100%; padding:10px;"><option value="">-- Không chọn --</option>@foreach($parents as $parent)<option value="{{ $parent->id }}" {{ (string) old('parent_id', $category?->parent_id) === (string) $parent->id ? 'selected' : '' }}>{{ $parent->name }}</option>@endforeach</select>@error('parent_id')<small class="admin-error">{{ $message }}</small>@enderror</div>
    <div><label for="image">Ảnh</label><input id="image" name="image" value="{{ old('image', $category?->image) }}" style="width:100%; padding:10px;"></div>
    <div><label for="description">Mô tả</label><textarea id="description" name="description" rows="5" style="width:100%; padding:10px;">{{ old('description', $category?->description) }}</textarea></div>
    <label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" {{ old('is_active', $category?->is_active ?? true) ? 'checked' : '' }}> Đang hoạt động</label>
</div>