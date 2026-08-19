<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            'products' => Product::where('is_active', true)->count(),
            'users' => User::count(),
            'orders' => Order::count(),
            'revenue' => Order::where('payment_status', 'paid')->sum('total_amount'),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'processing_orders' => Order::whereIn('status', ['processing', 'shipped'])->count(),
        ];

        $recentOrders = Order::with('user')->latest()->limit(10)->get();

        return view('admin.dashboard', [
            'pageTitle' => 'Admin Dashboard',
            'stats' => $stats,
            'recentOrders' => $recentOrders,
        ]);
    }
}