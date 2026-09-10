<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NewsletterSubscriberController extends Controller
{
    public function index(Request $request): View
    {
        $subscribers = NewsletterSubscriber::query()
            ->when($request->filled('q'), fn ($query) => $query->where('email', 'like', '%'.trim($request->string('q')->toString()).'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->latest()->paginate(20)->withQueryString();
        return view('admin.newsletter-subscribers.index', ['pageTitle' => 'Newsletter', 'subscribers' => $subscribers]);
    }
    public function update(Request $request, NewsletterSubscriber $newsletterSubscriber): RedirectResponse
    {
        $newsletterSubscriber->update($request->validate(['is_active' => 'required|boolean']));
        return back()->with('success', 'Trạng thái đăng ký đã được cập nhật.');
    }
}
