@extends('layouts.storefront')

@section('content')
    <section class="page-hero small-hero">
        <div class="container">
            <span class="eyebrow">Giỏ hàng của bạn</span>
            <h1>Giỏ hàng</h1>
        </div>
    </section>

    <section class="section-block">
        <div class="container" style="display:grid; gap:24px;">
            @if ($items->isEmpty())
                <div class="filter-box">
                    <h3>Giỏ hàng đang trống.</h3>
                    <p>Hãy thêm sản phẩm để bắt đầu mua sắm.</p>
                    <a href="{{ route('products.index') }}" class="btn btn-primary">Tiếp tục mua sắm</a>
                </div>
            @else
                <div class="filter-box">
                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="text-align:left; padding:12px 10px;">Sản phẩm</th>
                                <th style="text-align:right; padding:12px 10px;">Đơn giá</th>
                                <th style="text-align:center; padding:12px 10px;">Số lượng</th>
                                <th style="text-align:right; padding:12px 10px;">Thành tiền</th>
                                <th style="padding:12px 10px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $item)
                                @php
                                    $product = $item->product;
                                    $unitPrice = (float) ($item->unit_price ?: ($product->sale_price ?: $product->price ?: 0));
                                    $lineTotal = $unitPrice * (int) $item->quantity;
                                @endphp
                                <tr>
                                    <td style="padding:12px 10px; border-top:1px solid rgba(31,28,26,0.08);">
                                        <div style="display:flex; gap:12px; align-items:center;">
                                            <img src="{{ $product->featured_image ?? 'https://images.unsplash.com/photo-1617038220319-276d3cfab638?auto=format&fit=crop&w=300&q=80' }}" alt="{{ $product->name }}" style="width:72px; height:72px; object-fit:cover; border-radius:12px;">
                                            <div>
                                                <strong>{{ $product->name }}</strong><br>
                                                <small>{{ $product->material->name ?? 'Bạc 925' }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="text-align:right; padding:12px 10px; border-top:1px solid rgba(31,28,26,0.08);">{{ number_format($unitPrice, 0, ',', '.') }}đ</td>
                                    <td style="text-align:center; padding:12px 10px; border-top:1px solid rgba(31,28,26,0.08);">
                                        <form method="POST" action="{{ route('cart.update', $item->id) }}" style="display:inline-flex; align-items:center; gap:8px;">
                                            @csrf
                                            @method('PUT')
                                            <input type="number" name="quantity" value="{{ $item->quantity }}" min="1" style="width:64px; padding:8px 10px; border:1px solid rgba(31,28,26,0.1); border-radius:8px;">
                                            <button type="submit" class="btn btn-secondary">Cập nhật</button>
                                        </form>
                                    </td>
                                    <td style="text-align:right; padding:12px 10px; border-top:1px solid rgba(31,28,26,0.08);">{{ number_format($lineTotal, 0, ',', '.') }}đ</td>
                                    <td style="padding:12px 10px; border-top:1px solid rgba(31,28,26,0.08); text-align:right;">
                                        <form method="POST" action="{{ route('cart.remove', $item->id) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-link" style="padding:0;">Xóa</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="filter-box" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:20px;">
                    <div>
                        <p style="margin:0; font-weight:600;">Tổng cộng</p>
                        <h3 style="margin:6px 0 0;">{{ number_format($subtotal, 0, ',', '.') }}đ</h3>
                    </div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary">Tiếp tục mua sắm</a>
                        <a href="{{ route('checkout.index') }}" class="btn btn-primary">Thanh toán</a>
                    </div>
                </div>
            @endif
        </div>
    </section>
@endsection
