@extends('layouts.admin')

@section('content')
<div style="display:grid; gap:20px;">
    <div style="display:flex; justify-content:space-between; gap:16px; align-items:center; flex-wrap:wrap;">
        <div><span class="eyebrow">Vận chuyển</span><h1 style="margin:0;">Sửa: {{ $method->name }}</h1></div>
        <a class="btn btn-secondary" href="{{ route('admin.shipping.index') }}">Về danh sách</a>
    </div>

    <div class="filter-box">
        @if(session('success'))<div class="admin-success" style="margin-bottom:16px;">{{ session('success') }}</div>@endif
        @if($errors->any())
            <div class="admin-error" style="padding:12px 14px; background:#fff1f0; border-radius:8px; margin-bottom:16px;">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.shipping.update', $method) }}">
            @csrf @method('PUT')
            @include('admin.shipping._form')
            <div style="margin-top:20px; display:flex; gap:12px;">
                <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
                <a class="btn btn-secondary" href="{{ route('admin.shipping.index') }}">Hủy</a>
            </div>
        </form>
    </div>
</div>
@endsection
