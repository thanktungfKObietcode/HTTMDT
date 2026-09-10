@extends('layouts.admin')
@section('content')<div class="filter-box" style="max-width:850px"><h1>Sửa banner</h1><form method="POST" action="{{ route('admin.banners.update',$banner) }}">@method('PUT') @include('admin.banners._form')<button class="btn btn-primary" style="margin-top:16px">Cập nhật</button></form></div>@endsection
