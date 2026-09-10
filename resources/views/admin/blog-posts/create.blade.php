@extends('layouts.admin')
@section('content')<div class="filter-box" style="max-width:900px"><h1>Thêm bài viết</h1><form method="POST" action="{{ route('admin.blog-posts.store') }}">@include('admin.blog-posts._form')<button class="btn btn-primary" style="margin-top:16px">Lưu</button></form></div>@endsection
