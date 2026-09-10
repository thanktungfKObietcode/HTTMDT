<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactMessageController extends Controller
{
    public function index(Request $request): View
    {
        $messages = ContactMessage::query()
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.trim($request->string('q')->toString()).'%')->orWhere('email', 'like', '%'.trim($request->string('q')->toString()).'%')->orWhere('subject', 'like', '%'.trim($request->string('q')->toString()).'%')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->latest()->paginate(20)->withQueryString();
        return view('admin.contact-messages.index', ['pageTitle' => 'Liên hệ', 'messages' => $messages]);
    }
    public function show(ContactMessage $contactMessage): View
    {
        return view('admin.contact-messages.show', ['pageTitle' => 'Chi tiết liên hệ', 'contactMessage' => $contactMessage]);
    }
    public function update(Request $request, ContactMessage $contactMessage): RedirectResponse
    {
        $contactMessage->update($request->validate(['status' => 'required|in:new,read,resolved']));
        return back()->with('success', 'Trạng thái liên hệ đã được cập nhật.');
    }
}
