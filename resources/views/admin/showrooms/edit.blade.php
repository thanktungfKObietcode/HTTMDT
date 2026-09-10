@extends('layouts.admin')
@section('content')<div class="filter-box" style="max-width:850px"><h1>Sửa showroom</h1><form method="POST" action="{{ route('admin.showrooms.update',$showroom) }}">@method('PUT') @include('admin.showrooms._form')<button class="btn btn-primary" style="margin-top:16px">Cập nhật</button></form></div>@endsection
