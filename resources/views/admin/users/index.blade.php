@extends('layouts.admin')

@section('content')
<div style="display:grid; gap:20px;">
    @if(session('success'))<div class="admin-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="admin-error" style="padding:12px 14px; background:#fff1f0; border-radius:8px;">{{ $errors->first() }}</div>@endif

    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div><span class="eyebrow">Quản trị</span><h1 style="margin:0;">Người dùng</h1></div>
    </div>

    <form method="GET" action="{{ route('admin.users.index') }}" class="filter-box" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end;">
        <div style="flex:1; min-width:220px;">
            <label for="q">Tìm kiếm</label>
            <input id="q" name="q" value="{{ request('q') }}" placeholder="Tên hoặc email..." style="width:100%; padding:10px;">
        </div>
        <div>
            <label for="role">Vai trò</label>
            <select id="role" name="role" style="padding:10px;">
                <option value="">Tất cả</option>
                @foreach($roles as $role)
                    <option value="{{ $role->name }}" {{ request('role') === $role->name ? 'selected' : '' }}>{{ ucfirst($role->name) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status">Trạng thái</label>
            <select id="status" name="status" style="padding:10px;">
                <option value="">Tất cả</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Đang hoạt động</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Đã vô hiệu hóa</option>
            </select>
        </div>
        <button class="btn btn-secondary" type="submit">Lọc</button>
        @if(request()->hasAny(['q','role','status']))
            <a class="btn btn-secondary" href="{{ route('admin.users.index') }}">Xóa lọc</a>
        @endif
    </form>

    <div class="filter-box" style="overflow-x:auto;">
        <table style="width:100%; min-width:760px; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:10px;">Tên</th>
                    <th style="text-align:left; padding:10px;">Email</th>
                    <th style="text-align:left; padding:10px;">Vai trò</th>
                    <th style="text-align:left; padding:10px;">Trạng thái</th>
                    <th style="text-align:left; padding:10px;">Ngày tạo</th>
                    <th style="padding:10px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; font-weight:600;">{{ $user->name }}</td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb;">{{ $user->email }}</td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb;">
                            @forelse($user->roles as $role)
                                <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#e0e7ff; color:#3730a3; font-size:.8rem;">{{ ucfirst($role->name) }}</span>
                            @empty
                                <span style="color:#9ca3af; font-size:.85rem;">—</span>
                            @endforelse
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb;">
                            @if($user->is_active)
                                <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#d1fae5; color:#065f46; font-size:.8rem;">Hoạt động</span>
                            @else
                                <span style="display:inline-block; padding:2px 8px; border-radius:12px; background:#fee2e2; color:#991b1b; font-size:.8rem;">Vô hiệu hóa</span>
                            @endif
                        </td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb;">{{ $user->created_at->format('d/m/Y') }}</td>
                        <td style="padding:10px; border-top:1px solid #e5e7eb; white-space:nowrap;">
                            <a class="btn btn-secondary" href="{{ route('admin.users.show', $user) }}">Chi tiết</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:18px; text-align:center;">Không có người dùng phù hợp.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}
</div>
@endsection
