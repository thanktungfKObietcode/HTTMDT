@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero"><div class="container"><span class="eyebrow">Hỗ trợ</span><h1>Liên hệ</h1></div></section>
    <section class="section-block">
        <div class="container" style="display:grid; grid-template-columns:minmax(0, 1fr) minmax(260px, .7fr); gap:24px; align-items:start;">
            <form method="POST" action="{{ route('contact.store') }}" class="filter-box" style="display:grid; gap:14px;">
                @csrf
                @if (session('success'))<div style="padding:12px; background:#eaf7ef; color:#23613a; border-radius:8px;">{{ session('success') }}</div>@endif
                <h2 style="margin:0;">Gửi tin nhắn</h2>
                <label for="contact-name">Họ tên</label><input id="contact-name" name="name" value="{{ old('name') }}" required>
                @error('name')<small style="color:#c62828;">{{ $message }}</small>@enderror
                <label for="contact-email">Email</label><input id="contact-email" type="email" name="email" value="{{ old('email') }}" required>
                @error('email')<small style="color:#c62828;">{{ $message }}</small>@enderror
                <label for="contact-phone">Số điện thoại</label><input id="contact-phone" name="phone" value="{{ old('phone') }}">
                <label for="contact-subject">Chủ đề</label><input id="contact-subject" name="subject" value="{{ old('subject') }}">
                <label for="contact-message">Nội dung</label><textarea id="contact-message" name="message" rows="6" required>{{ old('message') }}</textarea>
                @error('message')<small style="color:#c62828;">{{ $message }}</small>@enderror
                <button type="submit" class="btn btn-primary" style="width:max-content;">Gửi liên hệ</button>
            </form>
            <aside class="filter-box"><h3>Thông tin liên hệ</h3><p>Email: {{ $settings['app_email'] ?? 'Chưa cập nhật' }}</p><p>Điện thoại: {{ $settings['app_phone'] ?? 'Chưa cập nhật' }}</p><a class="btn btn-secondary" href="{{ route('showrooms') }}">Xem showroom</a></aside>
        </div>
    </section>
@endsection