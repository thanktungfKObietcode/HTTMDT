@extends('layouts.admin')

@section('content')
<div style="display:grid; gap:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div><span class="eyebrow">Quản trị</span><h1 style="margin:0;">Vai trò (Roles)</h1></div>
    </div>

    <form method="GET" action="{{ route('admin.roles.index') }}" class="filter-box" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end;">
        <div style="flex:1; min-width:220px;">
            <label for="q">Tìm vai trò</label>
            <input id="q" name="q" value="{{ request('q') }}" placeholder="Nhập tên..." style="width:100%; padding:10px;">
        </div>
        <button class="btn btn-secondary" type="submit">Lọc</button>
        @if(request()->filled('q'))
            <a class="btn btn-secondary" href="{{ route('admin.roles.index') }}">Xóa lọc</a>
        @endif
    </form>

    <div class="filter-box" style="overflow-x:auto;">
        <table style="width:100%; min-width:600px; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:10px;">Tên vai trò</th>
                    <th style="text-align:left; padding:10px;">Guard</th>
                    <th style="text-align:center; padding:10px;">Số người dùng</th>
                    <th style="text-align:center; padding:10px;">Số quyền</th>
                    <th style="text-align:left; padding:10px;">Tạo lúc</th>
                    <th style="padding:10px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($roles as $role)
                    <tr>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; font-weight:700; font-family:monospace;">{{ $role->name }}</td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; color:#6b7280;">{{ $role->guard_name }}</td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; text-align:center;">{{ $role->users_count }}</td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; text-align:center;">{{ $role->permissions_count }}</td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; font-size:.85rem;">{{ $role->created_at?->format('d/m/Y') }}</td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb;">
                            <a class="btn btn-secondary" href="{{ route('admin.roles.show', $role) }}">Xem chi tiết</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:18px; text-align:center; color:#9ca3af;">Không có vai trò nào.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $roles->links() }}

    <div class="filter-box" style="background:#fffbeb; border:1px solid #fef3c7;">
        <p style="margin:0; font-size:.9rem; color:#92400e;">
            <strong>Lưu ý:</strong> Hệ thống phân quyền sử dụng mô hình Role-based. Vai trò <code>admin</code> được kiểm tra trực tiếp bởi AdminMiddleware.
            Chỉ quản trị viên có vai trò <code>admin</code> và <code>is_active = true</code> mới có quyền truy cập khu vực quản trị.
        </p>
    </div>
</div>
@endsection
