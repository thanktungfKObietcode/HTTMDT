@extends('layouts.admin')
@section('content')<div class="filter-box" style="max-width:850px"><h1>Thêm banner</h1><form method="POST" action="{{ route('admin.banners.store') }}">@include('admin.banners._form')<button class="btn btn-primary" style="margin-top:16px">Lưu</button></form></div>@endsection
