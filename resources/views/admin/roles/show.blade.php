@extends('layouts.admin')

@section('content')
<div style="display:grid; gap:20px;">
    <div style="display:flex; justify-content:space-between; gap:16px; align-items:center; flex-wrap:wrap;">
        <div>
            <span class="eyebrow">Vai trò</span>
            <h1 style="margin:0; font-family:monospace;">{{ $role->name }}</h1>
        </div>
        <a class="btn btn-secondary" href="{{ route('admin.roles.index') }}">Về danh sách</a>
    </div>

    {{-- Role info --}}
    <div class="filter-box" style="display:grid; gap:12px; max-width:480px;">
        <div style="display:grid; grid-template-columns:160px 1fr; gap:8px; font-size:.95rem;">
            <span style="color:#6b7280;">ID</span><span>{{ $role->id }}</span>
            <span style="color:#6b7280;">Tên vai trò</span><span style="font-weight:700; font-family:monospace;">{{ $role->name }}</span>
            <span style="color:#6b7280;">Guard</span><span>{{ $role->guard_name }}</span>
            <span style="color:#6b7280;">Số người dùng</span><span>{{ $role->users->count() }}</span>
            <span style="color:#6b7280;">Số quyền</span><span>{{ $role->permissions->count() }}</span>
            <span style="color:#6b7280;">Tạo lúc</span><span>{{ $role->created_at?->format('d/m/Y H:i') }}</span>
        </div>
    </div>

    {{-- Permissions --}}
    <div>
        <h2 style="font-size:1rem; font-weight:700; margin-bottom:12px;">Quyền (Permissions) — {{ $role->permissions->count() }}</h2>
        @if($role->permissions->isEmpty())
            <p style="color:#9ca3af;">Không có quyền nào được gán cho vai trò này.</p>
        @else
            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                @foreach($role->permissions as $perm)
                    <span style="display:inline-block; padding:4px 10px; border-radius:12px; background:#e0e7ff; color:#3730a3; font-size:.85rem; font-family:monospace;">{{ $perm->name }}</span>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Users --}}
    <div>
        <h2 style="font-size:1rem; font-weight:700; margin-bottom:12px;">Người dùng có vai trò này — {{ $role->users->count() }}</h2>
        @if($role->users->isEmpty())
            <p style="color:#9ca3af;">Không có người dùng nào.</p>
        @else
            <div class="filter-box" style="overflow-x:auto;">
                <table style="width:100%; min-width:500px; border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left; padding:10px;">Tên</th>
                            <th style="text-align:left; padding:10px;">Email</th>
                            <th style="text-align:center; padding:10px;">Trạng thái</th>
                            <th style="padding:10px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($role->users as $user)
                            <tr>
                                <td style="padding:10px; border-top:1px solid #e5e7eb;">{{ $user->name }}</td>
                                <td style="padding:10px; border-top:1px solid #e5e7eb;">{{ $user->email }}</td>
                                <td style="padding:10px; border-top:1px solid #e5e7eb; text-align:center;">
                                    @if($user->is_active)
                                        <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#d1fae5; color:#065f46; font-size:.8rem;">Hoạt động</span>
                                    @else
                                        <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#fee2e2; color:#991b1b; font-size:.8rem;">Vô hiệu hóa</span>
                                    @endif
                                </td>
                                <td style="padding:10px; border-top:1px solid #e5e7eb;">
                                    <a class="btn btn-secondary" href="{{ route('admin.users.show', $user) }}">Xem</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($role->users->count() >= 50)
                <p style="margin-top:8px; font-size:.85rem; color:#9ca3af;">Hiển thị tối đa 50 người dùng gần nhất.</p>
            @endif
        @endif
    </div>
</div>
@endsection
