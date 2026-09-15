@extends('layouts.admin')

@section('content')
<div class="filter-box" style="max-width:980px;"><h1>Thêm sản phẩm</h1><form method="POST" action="{{ route('admin.products.store') }}" enctype="multipart/form-data">@include('admin.products._form')<div style="margin-top:20px; display:flex; gap:12px;"><button class="btn btn-primary" type="submit">Lưu sản phẩm</button><a class="btn btn-secondary" href="{{ route('admin.products.index') }}">Hủy</a></div></form></div>
@endsection
