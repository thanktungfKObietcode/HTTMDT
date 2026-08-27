@extends('layouts.admin')

@section('content')
<div style="display:grid; gap:20px;">
    <div style="display:flex; justify-content:space-between; gap:16px; align-items:center; flex-wrap:wrap;">
        <div><span class="eyebrow">Mã giảm giá</span><h1 style="margin:0;">Tạo mã giảm giá</h1></div>
        <a class="btn btn-secondary" href="{{ route('admin.coupons.index') }}">Về danh sách</a>
    </div>

    <div class="filter-box">
        @if($errors->any())
            <div class="admin-error" style="padding:12px 14px; background:#fff1f0; border-radius:8px; margin-bottom:16px;">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.coupons.store') }}">
            @csrf
            @include('admin.coupons._form')
            <div style="margin-top:20px; display:flex; gap:12px;">
                <button class="btn btn-primary" type="submit">Tạo mã giảm giá</button>
                <a class="btn btn-secondary" href="{{ route('admin.coupons.index') }}">Hủy</a>
            </div>
        </form>
    </div>
</div>
@endsection