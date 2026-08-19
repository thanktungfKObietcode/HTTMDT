@extends('layouts.admin')

@section('content')
<div class="filter-box" style="max-width:800px;"><span class="eyebrow">{{ $product->name }}</span><h1>Chỉnh sửa biến thể</h1><form method="POST" action="{{ route('admin.products.variants.update', [$product, $variant]) }}">@method('PUT')@include('admin.products.variants._form')<div style="margin-top:20px; display:flex; gap:12px;"><button class="btn btn-primary" type="submit">Cập nhật</button><a class="btn btn-secondary" href="{{ route('admin.products.variants.index', $product) }}">Hủy</a></div></form></div>
@endsection