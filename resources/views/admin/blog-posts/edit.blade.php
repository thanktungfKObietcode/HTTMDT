@extends('layouts.admin')
@section('content')<div class="filter-box" style="max-width:900px"><h1>Sửa bài viết</h1><form method="POST" action="{{ route('admin.blog-posts.update',$post) }}" enctype="multipart/form-data">@method('PUT') @include('admin.blog-posts._form')<button class="btn btn-primary" style="margin-top:16px">Cập nhật</button></form></div>@endsection
