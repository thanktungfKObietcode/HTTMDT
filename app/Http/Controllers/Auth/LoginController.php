<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    public function showLoginForm(): View
    {
        return view('auth.login', [
            'pageTitle' => 'Đăng nhập | Silver Atelier',
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'remember' => 'nullable|boolean',
        ]);

        $credentials = [
            'email' => $validated['email'],
            'password' => $validated['password'],
            'is_active' => true,
        ];
        $guestCart = $this->cartService->captureGuestCart($request);

        if (Auth::attempt($credentials, $validated['remember'] ?? false)) {
            $request->session()->regenerate();

            Auth::user()->update(['last_login_at' => now()]);

            $user = Auth::user();
            $user->loadMissing('roles.permissions');

            try {
                $mergeResult = $this->cartService->mergeAfterAuthentication($user, $request, $guestCart);
                $cartNotices = $mergeResult['notices'];
            } catch (\Throwable $exception) {
                report($exception);
                $cartNotices = ['Đăng nhập thành công nhưng chưa thể đồng bộ giỏ hàng. Giỏ hàng gốc vẫn được giữ lại.'];
            }

            if ($user->hasRole('admin')) {
                return redirect()->route('admin.dashboard')
                    ->with('success', 'Đăng nhập quản trị thành công!')
                    ->with('cart_notices', $cartNotices);
            }

            if ($destination = $this->staffBackofficeDestination($user)) {
                return redirect()->route($destination)
                    ->with('success', 'Đăng nhập thành công!')
                    ->with('cart_notices', $cartNotices);
            }

            return redirect()->intended(route('home'))
                ->with('success', 'Đăng nhập thành công!')
                ->with('cart_notices', $cartNotices);
        }

        return back()->withErrors([
            'email' => 'Email hoặc mật khẩu không chính xác.',
        ])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Đã đăng xuất thành công.');
    }

    private function staffBackofficeDestination(User $user): ?string
    {
        if (! $user->hasRole('staff')) {
            return null;
        }

        $destinations = [
            'dashboard.view' => 'admin.dashboard',
            'orders.view' => 'admin.orders.index',
            'products.view' => 'admin.products.index',
            'products.create' => 'admin.products.create',
            'content.manage' => 'admin.blog-posts.index',
            'customers.view' => 'admin.users.index',
            'staff.manage' => 'admin.users.index',
            'coupons.manage' => 'admin.coupons.index',
            'shipping.manage' => 'admin.shipping.index',
            'roles.manage' => 'admin.roles.index',
        ];

        foreach ($destinations as $permission => $route) {
            if ($user->hasPermission($permission)) {
                return $route;
            }
        }

        return null;
    }
}
