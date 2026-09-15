<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Rules\SafeContentReference;
use App\Support\VimeoVideo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BannerController extends Controller
{
    public function index(): View
    {
        return view('admin.banners.index', ['pageTitle' => 'Banner', 'banners' => Banner::orderBy('position')->orderBy('sort_order')->get()]);
    }

    public function create(): View
    {
        return view('admin.banners.create', ['pageTitle' => 'Thêm banner', 'banner' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        Banner::create($this->validated($request));
        return redirect()->route('admin.banners.index')->with('success', 'Banner đã được tạo.');
    }

    public function edit(Banner $banner): View
    {
        return view('admin.banners.edit', ['pageTitle' => 'Sửa banner', 'banner' => $banner]);
    }

    public function update(Request $request, Banner $banner): RedirectResponse
    {
        $banner->update($this->validated($request));
        return redirect()->route('admin.banners.index')->with('success', 'Banner đã được cập nhật.');
    }

    public function destroy(Banner $banner): RedirectResponse
    {
        $banner->update(['is_active' => false]);
        return back()->with('success', 'Banner đã được ngừng hiển thị.');
    }

    private function validated(Request $request): array
    {
        if (($uploadError = $this->uploadFailureMessage($request->file('image_upload'))) !== null) {
            throw ValidationException::withMessages([
                'image_upload' => $uploadError,
            ]);
        }

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'image' => ['nullable', 'string', 'max:2048', new SafeContentReference],
            'image_upload' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'link' => ['nullable', 'string', 'max:2048', new SafeContentReference],
            'position' => 'required|in:home,home_story,home_engagement_video,home_high_jewelry_video,home_wedding_campaign,home_wedding_editorial,home_new_arrivals_campaign,home_editorial_gallery,home_high_jewelry_image,home_high_jewelry_campaign,home_high_jewelry_editorial,home_cz_editorial,home_colored_gemstone_editorial,home_pearl_editorial,catalog,blog',
            'sort_order' => 'nullable|integer|min:0|max:4294967295',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|required_with:ends_at|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ]);
        $data['title'] = trim((string) ($data['title'] ?? '')) ?: null;
        $data['image'] = trim((string) ($data['image'] ?? '')) ?: null;

        if (in_array($data['position'], ['home_story', 'home_engagement_video', 'home_high_jewelry_video'], true)) {
            if ($request->hasFile('image_upload')) {
                throw ValidationException::withMessages([
                    'image_upload' => 'Video campaign chỉ nhận Vimeo video URL, không nhận tệp tải lên.',
                ]);
            }

            $videoId = VimeoVideo::extractId($data['image'] ?? null);

            if ($videoId === null) {
                throw ValidationException::withMessages([
                    'image' => 'Vui lòng nhập Vimeo video URL HTTPS hợp lệ.',
                ]);
            }

            $data['image'] = VimeoVideo::canonicalUrl($videoId);
        } elseif (in_array($data['position'], ['home_wedding_editorial', 'home_new_arrivals_campaign', 'home_editorial_gallery', 'home_high_jewelry_image', 'home_high_jewelry_campaign', 'home_high_jewelry_editorial', 'home_cz_editorial', 'home_colored_gemstone_editorial', 'home_pearl_editorial'], true)
            && $this->isVideoReference($data['image'])) {
            $placement = match ($data['position']) {
                'home_wedding_editorial' => 'Wedding Editorial',
                'home_editorial_gallery' => 'Editorial Gallery',
                'home_high_jewelry_image' => 'High Jewelry Image',
                'home_high_jewelry_campaign' => 'High Jewelry Campaign',
                'home_high_jewelry_editorial' => 'High Jewelry Editorial',
                'home_cz_editorial' => 'CZ Editorial',
                'home_colored_gemstone_editorial' => 'Colored Gemstone Editorial',
                'home_pearl_editorial' => 'Pearl Editorial',
                default => 'New Arrivals Campaign',
            };

            throw ValidationException::withMessages([
                'image' => $placement.' chỉ nhận hình ảnh, không nhận Vimeo hoặc video URL.',
            ]);
        } elseif ($request->hasFile('image_upload')) {
            $data['image'] = $request->file('image_upload')->store('banners', 'public');
        } elseif ($data['image'] === null) {
            throw ValidationException::withMessages([
                'image_upload' => 'Vui lòng tải ảnh hoặc nhập đường dẫn hình ảnh hợp lệ.',
            ]);
        }
        unset($data['image_upload']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        return $data;
    }

    private function uploadFailureMessage(?UploadedFile $file): ?string
    {
        if ($file === null || $file->isValid()) {
            return null;
        }

        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE => 'Tệp vượt giới hạn tải lên của PHP. Hãy chọn tệp nhỏ hơn hoặc kiểm tra cấu hình máy chủ.',
            UPLOAD_ERR_FORM_SIZE => 'Tệp vượt giới hạn tải lên của biểu mẫu.',
            UPLOAD_ERR_PARTIAL => 'Tệp chỉ được tải lên một phần. Vui lòng thử lại.',
            UPLOAD_ERR_NO_TMP_DIR => 'Máy chủ thiếu thư mục tạm để tải tệp lên.',
            UPLOAD_ERR_CANT_WRITE => 'Máy chủ không thể ghi tệp đã tải lên. Vui lòng thử lại.',
            UPLOAD_ERR_EXTENSION => 'Một tiện ích mở rộng PHP đã chặn tệp tải lên.',
            default => 'Không thể tải tệp ảnh lên. Vui lòng thử lại.',
        };
    }

    private function isVideoReference(?string $reference): bool
    {
        $reference = trim((string) $reference);
        $host = strtolower((string) parse_url($reference, PHP_URL_HOST));

        return VimeoVideo::extractId($reference) !== null
            || in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com', 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'], true)
            || preg_match('/\.(mp4|webm|ogg)(?:\?.*)?$/i', $reference) === 1;
    }
}
