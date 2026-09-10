@extends('layouts.admin')
@section('content')<div class="filter-box" style="max-width:850px"><h1>Thêm showroom</h1><form method="POST" action="{{ route('admin.showrooms.store') }}">@include('admin.showrooms._form')<button class="btn btn-primary" style="margin-top:16px">Lưu</button></form></div>@endsection
