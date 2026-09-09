<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'email',
    'password',
    'phone',
    'avatar',
    'gender',
    'birth_date',
    'is_active',
    'last_login_at',
    'receive_newsletter',
])]
#[Hidden([
    'password',
    'remember_token',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Roles assigned to this user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_user',
            'user_id',
            'role_id'
        );
    }

    public function hasRole(string $name): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(
                fn (Role $role): bool => $role->name === $name && $role->guard_name === 'web'
            );
        }

        return $this->roles()
            ->where('roles.name', $name)
            ->where('roles.guard_name', 'web')
            ->exists();
    }

    /**
     * @param  array<int, string>  $names
     */
    public function hasAnyRole(array $names): bool
    {
        if ($names === []) {
            return false;
        }

        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(
                fn (Role $role): bool => in_array($role->name, $names, true) && $role->guard_name === 'web'
            );
        }

        return $this->roles()
            ->whereIn('roles.name', $names)
            ->where('roles.guard_name', 'web')
            ->exists();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->hasRole('admin')) {
            return true;
        }

        if (! $this->hasRole('staff')) {
            return false;
        }

        if ($this->relationLoaded('roles')
            && $this->roles->every(fn (Role $role): bool => $role->relationLoaded('permissions'))) {
            return $this->roles->contains(
                fn (Role $role): bool => $role->guard_name === 'web'
                    && $role->permissions->contains(
                        fn (Permission $assigned): bool => $assigned->name === $permission
                            && $assigned->guard_name === 'web'
                    )
            );
        }

        return $this->roles()
            ->where('roles.guard_name', 'web')
            ->whereHas('permissions', fn ($query) => $query
                ->where('permissions.name', $permission)
                ->where('permissions.guard_name', 'web'))
            ->exists();
    }

    public function canAccessBackoffice(): bool
    {
        return (bool) $this->is_active && $this->hasAnyRole(['admin', 'staff']);
    }

    /**
     * User addresses.
     */
    public function addresses()
    {
        return $this->hasMany(UserAddress::class);
    }

    /**
     * Orders placed by the user.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Carts belonging to the user.
     */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    /**
     * User wishlist items.
     */
    public function wishlist(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    /**
     * Product reviews written by the user.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Coupon usage history.
     */
    public function couponUsages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'receive_newsletter' => 'boolean',
        ];
    }
}
