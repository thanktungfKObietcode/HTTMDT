<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    public function showRegistrationForm(): View
    {
        return view('auth.register', [
            'pageTitle' => 'Đăng ký | Silver Atelier',
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        $guestCart = $this->cartService->captureGuestCart($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $customerRole = Role::query()
                ->where('name', 'customer')
                ->where('guard_name', 'web')
                ->lockForUpdate()
                ->first();

            if (! $customerRole) {
                throw ValidationException::withMessages([
                    'registration' => 'Không thể hoàn tất đăng ký lúc này.',
                ]);
            }

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'is_active' => true,
            ]);

            $user->roles()->attach($customerRole->id);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        try {
            $mergeResult = $this->cartService->mergeAfterAuthentication($user, $request, $guestCart);
            $cartNotices = $mergeResult['notices'];
        } catch (\Throwable $exception) {
            report($exception);
            $cartNotices = ['Đăng ký thành công nhưng chưa thể đồng bộ giỏ hàng. Giỏ hàng gốc vẫn được giữ lại.'];
        }

        return redirect()->route('home')
            ->with('success', 'Đăng ký thành công. Chào mừng '.$user->name.'!')
            ->with('cart_notices', $cartNotices);
    }
}
