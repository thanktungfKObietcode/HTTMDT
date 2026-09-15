<div style="display:grid;gap:14px">
    <div><label>Tiêu đề</label><input name="title" value="{{ old('title', $post?->title) }}" required style="width:100%"></div>
    <div><label>Slug</label><input name="slug" value="{{ old('slug', $post?->slug) }}" required style="width:100%"></div>
    <div><label>Chuyên mục</label><select name="blog_category_id"><option value="">Không chọn</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('blog_category_id', $post?->blog_category_id) === (string) $category->id)>{{ $category->name }}</option>@endforeach</select> <a href="{{ route('admin.blog-categories.index') }}">Quản lý chuyên mục</a></div>
    <div><label>Tóm tắt</label><textarea name="excerpt" rows="3" style="width:100%">{{ old('excerpt', $post?->excerpt) }}</textarea></div>
    <div><label>Nội dung</label><textarea name="content" rows="12" required style="width:100%">{{ old('content', $post?->content) }}</textarea></div>
    <div><label>Ảnh nổi bật (đường dẫn cũ)</label><input name="featured_image" value="{{ old('featured_image', $post?->featured_image) }}" style="width:100%"></div>
    <div><label>Tải ảnh nổi bật</label><input type="file" name="featured_image_upload" accept="image/jpeg,image/png,image/webp" style="width:100%"><small>JPEG, PNG hoặc WebP, tối đa 5 MB.</small></div>
    <div><label>Ngày xuất bản</label><input type="datetime-local" name="published_at" value="{{ old('published_at', $post?->published_at?->format('Y-m-d\\TH:i')) }}"></div>
    <label><input type="hidden" name="is_published" value="0"><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $post?->is_published ?? false))> Công khai</label>
</div>
