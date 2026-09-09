<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartService
{
    private const GUEST_SESSION_KEY = 'cart_guest_session_id';

    /**
     * Capture a guest cart before authentication regenerates the session ID.
     *
     * @return array{id: int, session_id: string}|null
     */
    public function captureGuestCart(Request $request): ?array
    {
        $sessionId = $this->guestSessionId($request);
        $storedCartId = $request->session()->get('cart_id');

        $cart = Cart::query()
            ->whereNull('user_id')
            ->where('session_id', $sessionId)
            ->when($storedCartId, fn ($query) => $query->whereKey($storedCartId))
            ->first();

        if (! $cart && $storedCartId) {
            $cart = Cart::query()
                ->whereNull('user_id')
                ->where('session_id', $sessionId)
                ->oldest('id')
                ->first();
        }

        return $cart ? ['id' => (int) $cart->id, 'session_id' => $sessionId] : null;
    }

    public function currentCart(Request $request): Cart
    {
        if ($request->user()) {
            [$cart, $notices] = $this->consolidateAuthenticatedCarts(
                $request->user(),
                $request->session()->getId(),
                null,
                $request->session()->get(self::GUEST_SESSION_KEY)
            );
            $request->session()->forget(self::GUEST_SESSION_KEY);
        } else {
            [$cart, $notices] = $this->consolidateGuestCarts($this->guestSessionId($request));
        }

        $request->session()->put('cart_id', $cart->id);
        if ($notices !== []) {
            $request->session()->flash('cart_notices', $notices);
        }

        return $cart;
    }

    /**
     * @param  array{id: int, session_id: string}|null  $guestIdentity
     * @return array{cart: Cart, notices: array<int, string>}
     */
    public function mergeAfterAuthentication(
        User $user,
        Request $request,
        ?array $guestIdentity
    ): array {
        [$cart, $notices] = $this->consolidateAuthenticatedCarts(
            $user,
            $request->session()->getId(),
            $guestIdentity,
            $guestIdentity['session_id'] ?? $request->session()->get(self::GUEST_SESSION_KEY)
        );

        $request->session()->put('cart_id', $cart->id);
        $request->session()->forget(self::GUEST_SESSION_KEY);

        return ['cart' => $cart, 'notices' => $notices];
    }

    public function ownershipSessionId(Request $request): string
    {
        return $request->user()
            ? $request->session()->getId()
            : $this->guestSessionId($request);
    }

    public function addItem(
        Cart $cart,
        ?User $user,
        string $sessionId,
        int $productId,
        ?int $variantId,
        int $quantity
    ): CartItem {
        return DB::transaction(function () use ($cart, $user, $sessionId, $productId, $variantId, $quantity): CartItem {
            $lockedCart = $this->lockOwnedCart($cart->id, $user, $sessionId);

            $existingItems = CartItem::query()
                ->where('cart_id', $lockedCart->id)
                ->where('product_id', $productId)
                ->where('product_variant_id', $variantId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            [$product, $variant, $stock, $price] = $this->lockPurchasableInventory($productId, $variantId);
            $requestedQuantity = $existingItems->sum(fn (CartItem $item): int => (int) $item->quantity) + $quantity;

            if ($requestedQuantity > $stock) {
                throw ValidationException::withMessages([
                    'quantity' => 'Số lượng sản phẩm vượt quá tồn kho hiện tại.',
                ]);
            }

            $item = $existingItems->first();

            if ($item) {
                CartItem::query()
                    ->whereIn('id', $existingItems->skip(1)->pluck('id'))
                    ->delete();
                $item->update([
                    'quantity' => $requestedQuantity,
                    'unit_price' => $price,
                ]);
            } else {
                $item = $lockedCart->items()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                ]);
            }

            return $item->fresh(['product', 'productVariant']);
        }, 3);
    }

    public function updateItemQuantity(
        Cart $cart,
        CartItem $item,
        ?User $user,
        string $sessionId,
        int $quantity
    ): CartItem {
        return DB::transaction(function () use ($cart, $item, $user, $sessionId, $quantity): CartItem {
            $lockedCart = $this->lockOwnedCart($cart->id, $user, $sessionId);
            $lockedItem = CartItem::query()
                ->whereKey($item->id)
                ->where('cart_id', $lockedCart->id)
                ->lockForUpdate()
                ->firstOrFail();

            [, , $stock, $price] = $this->lockPurchasableInventory(
                (int) $lockedItem->product_id,
                $lockedItem->product_variant_id ? (int) $lockedItem->product_variant_id : null
            );

            if ($quantity > $stock) {
                throw ValidationException::withMessages([
                    'quantity' => 'Số lượng yêu cầu vượt quá tồn kho hiện tại (còn '.$stock.').',
                ]);
            }

            $lockedItem->update([
                'quantity' => $quantity,
                'unit_price' => $price,
            ]);

            return $lockedItem->fresh(['product', 'productVariant']);
        }, 3);
    }

    public function removeItem(
        Cart $cart,
        CartItem $item,
        ?User $user,
        string $sessionId
    ): void {
        DB::transaction(function () use ($cart, $item, $user, $sessionId): void {
            $lockedCart = $this->lockOwnedCart($cart->id, $user, $sessionId);
            $lockedItem = CartItem::query()
                ->whereKey($item->id)
                ->where('cart_id', $lockedCart->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedItem->delete();
        }, 3);
    }

    /**
     * @return array{
     *     entries: Collection<int, array<string, mixed>>,
     *     subtotal_minor: int,
     *     subtotal: string,
     *     subtotal_display: string,
     *     can_checkout: bool
     * }
     */
    public function summarize(Cart $cart): array
    {
        $items = $cart->items()->with(['product.material', 'productVariant'])->orderBy('id')->get();
        $productIds = $items->pluck('product_id')->filter()->unique()->values();
        $activeVariantProductIds = $productIds->isEmpty()
            ? collect()
            : ProductVariant::query()
                ->whereIn('product_id', $productIds)
                ->where('is_active', true)
                ->pluck('product_id')
                ->unique();

        $entries = $items->map(function (CartItem $item) use ($activeVariantProductIds): array {
            $product = $item->product;
            $variant = $item->productVariant;
            $message = null;
            $stock = 0;
            $purchasable = true;

            if (! $product || ! $product->is_active) {
                $purchasable = false;
                $message = 'Sản phẩm không còn khả dụng.';
            } elseif ($item->product_variant_id) {
                if (! $variant || (int) $variant->product_id !== (int) $product->id || ! $variant->is_active) {
                    $purchasable = false;
                    $message = 'Phiên bản sản phẩm không còn khả dụng.';
                } else {
                    $stock = (int) $variant->stock;
                }
            } elseif ($activeVariantProductIds->contains($product->id)) {
                $purchasable = false;
                $message = 'Sản phẩm này cần chọn lại phiên bản.';
            } else {
                $stock = (int) $product->stock;
            }

            $canUpdate = $purchasable && $stock > 0;

            if ($purchasable && $stock < 1) {
                $purchasable = false;
                $message = 'Sản phẩm đã hết hàng.';
            } elseif ($purchasable && (int) $item->quantity > $stock) {
                $purchasable = false;
                $message = 'Số lượng hiện có đã thay đổi (còn '.$stock.').';
            }

            $priceMinor = $product && (! $item->product_variant_id || $variant)
                ? $this->canonicalPriceMinor($product, $variant)
                : $this->decimalToMinor((string) $item->unit_price);
            $lineTotalMinor = $priceMinor * (int) $item->quantity;

            return [
                'item' => $item,
                'product' => $product,
                'variant' => $variant,
                'stock' => $stock,
                'purchasable' => $purchasable,
                'can_update' => $canUpdate,
                'message' => $message,
                'unit_price' => $this->minorToDecimal($priceMinor),
                'unit_price_display' => $this->formatMinor($priceMinor),
                'line_total' => $this->minorToDecimal($lineTotalMinor),
                'line_total_display' => $this->formatMinor($lineTotalMinor),
                'line_total_minor' => $lineTotalMinor,
            ];
        });

        $subtotalMinor = $entries->sum('line_total_minor');

        return [
            'entries' => $entries,
            'subtotal_minor' => $subtotalMinor,
            'subtotal' => $this->minorToDecimal($subtotalMinor),
            'subtotal_display' => $this->formatMinor($subtotalMinor),
            'can_checkout' => $entries->isNotEmpty() && $entries->every('purchasable'),
        ];
    }

    public function canonicalPrice(Product $product, ?ProductVariant $variant = null): string
    {
        return $this->minorToDecimal($this->canonicalPriceMinor($product, $variant));
    }

    private function lockOwnedCart(int $cartId, ?User $user, string $sessionId): Cart
    {
        return Cart::query()
            ->whereKey($cartId)
            ->when(
                $user,
                fn ($query) => $query->where('user_id', $user->id),
                fn ($query) => $query->whereNull('user_id')->where('session_id', $sessionId)
            )
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array{Product, ProductVariant|null, int, string}
     */
    private function lockPurchasableInventory(int $productId, ?int $variantId): array
    {
        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();

        if (! $product || ! $product->is_active) {
            throw ValidationException::withMessages([
                'product_id' => 'Sản phẩm không còn khả dụng.',
            ]);
        }

        $activeVariants = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $variant = null;

        if ($variantId !== null) {
            $variant = ProductVariant::query()
                ->whereKey($variantId)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if (! $variant || ! $variant->is_active) {
                throw ValidationException::withMessages([
                    'product_variant_id' => 'Phiên bản sản phẩm không hợp lệ hoặc đã ngừng bán.',
                ]);
            }
        } elseif ($activeVariants->isNotEmpty()) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Vui lòng chọn phiên bản sản phẩm.',
            ]);
        }

        $inventory = $variant ?: $product;

        if ((int) $inventory->stock < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Sản phẩm đã hết hàng.',
            ]);
        }

        return [$product, $variant, (int) $inventory->stock, $this->canonicalPrice($product, $variant)];
    }

    /**
     * @param  array{id: int, session_id: string}|null  $guestIdentity
     * @return array{Cart, array<int, string>}
     */
    private function consolidateAuthenticatedCarts(
        User $user,
        string $sessionId,
        ?array $guestIdentity = null,
        ?string $currentGuestSessionId = null
    ): array {
        return DB::transaction(function () use ($user, $sessionId, $guestIdentity, $currentGuestSessionId): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $carts = Cart::query()
                ->where(function ($query) use ($user, $guestIdentity, $currentGuestSessionId): void {
                    $query->where('user_id', $user->id);

                    if ($currentGuestSessionId) {
                        $query->orWhere(function ($guestQuery) use ($currentGuestSessionId): void {
                            $guestQuery->whereNull('user_id')->where('session_id', $currentGuestSessionId);
                        });
                    }

                    if ($guestIdentity) {
                        $query->orWhere(function ($capturedQuery) use ($guestIdentity): void {
                            $capturedQuery
                                ->whereKey($guestIdentity['id'])
                                ->whereNull('user_id')
                                ->where('session_id', $guestIdentity['session_id']);
                        });
                    }
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($carts->isEmpty()) {
                $cart = Cart::create(['user_id' => $user->id, 'session_id' => $sessionId]);

                return [$cart, []];
            }

            $shouldNormalize = $carts->count() > 1
                || $carts->first()->user_id === null;

            return $this->mergeLockedCarts($carts, $user, $sessionId, $shouldNormalize);
        }, 3);
    }

    /**
     * @return array{Cart, array<int, string>}
     */
    private function consolidateGuestCarts(string $sessionId): array
    {
        return DB::transaction(function () use ($sessionId): array {
            $carts = Cart::query()
                ->whereNull('user_id')
                ->where('session_id', $sessionId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($carts->isEmpty()) {
                return [Cart::create(['session_id' => $sessionId]), []];
            }

            if ($carts->count() === 1) {
                return [$carts->first(), []];
            }

            return $this->mergeLockedCarts($carts, null, $sessionId, true);
        }, 3);
    }

    /**
     * @param  Collection<int, Cart>  $carts
     * @return array{Cart, array<int, string>}
     */
    private function mergeLockedCarts(
        Collection $carts,
        ?User $user,
        string $sessionId,
        bool $normalizeItems
    ): array {
        $canonical = $carts->sortBy('id')->first();
        $notices = [];

        if ($normalizeItems) {
            $items = CartItem::query()
                ->whereIn('cart_id', $carts->pluck('id'))
                ->orderBy('product_id')
                ->orderByRaw('product_variant_id IS NULL')
                ->orderBy('product_variant_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $productIds = $items->pluck('product_id')->unique()->sort()->values();
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $variants = ProductVariant::query()
                ->whereIn('product_id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $variantsById = $variants->keyBy('id');
            $activeVariantProductIds = $variants->where('is_active', true)->pluck('product_id')->unique();

            $groups = $items->groupBy(
                fn (CartItem $item): string => $item->product_id.':'.($item->product_variant_id ?? 'none')
            );

            foreach ($groups as $group) {
                $first = $group->first();
                $product = $products->get($first->product_id);
                $variant = $first->product_variant_id ? $variantsById->get($first->product_variant_id) : null;
                $reason = $this->unavailableReason($product, $variant, $first, $activeVariantProductIds);

                if ($reason !== null) {
                    CartItem::query()->whereIn('id', $group->pluck('id'))->delete();
                    $notices[] = ($product?->name ?? 'Một sản phẩm').' đã được loại khỏi giỏ khi đăng nhập: '.$reason;

                    continue;
                }

                $stock = (int) ($variant?->stock ?? $product->stock);
                $quantity = $group->sum(fn (CartItem $item): int => (int) $item->quantity);

                if ($stock < 1) {
                    CartItem::query()->whereIn('id', $group->pluck('id'))->delete();
                    $notices[] = $product->name.' đã được loại khỏi giỏ vì hết hàng.';

                    continue;
                }

                if ($quantity > $stock) {
                    $quantity = $stock;
                    $notices[] = 'Số lượng '.$product->name.' đã được điều chỉnh còn '.$stock.' theo tồn kho hiện tại.';
                }

                $keeper = $group->firstWhere('cart_id', $canonical->id) ?: $first;
                CartItem::query()->whereIn('id', $group->where('id', '<>', $keeper->id)->pluck('id'))->delete();
                $keeper->update([
                    'cart_id' => $canonical->id,
                    'quantity' => $quantity,
                    'unit_price' => $this->canonicalPrice($product, $variant),
                ]);
            }
        }

        $canonical->update([
            'user_id' => $user?->id,
            'session_id' => $sessionId,
        ]);

        $obsoleteIds = $carts->where('id', '<>', $canonical->id)->pluck('id');
        if ($obsoleteIds->isNotEmpty()) {
            Cart::query()->whereIn('id', $obsoleteIds)->delete();
        }

        return [$canonical->fresh(), array_values(array_unique($notices))];
    }

    private function unavailableReason(
        ?Product $product,
        ?ProductVariant $variant,
        CartItem $item,
        Collection $activeVariantProductIds
    ): ?string {
        if (! $product || ! $product->is_active) {
            return 'sản phẩm không còn khả dụng.';
        }

        if ($item->product_variant_id && (! $variant || ! $variant->is_active || (int) $variant->product_id !== (int) $product->id)) {
            return 'phiên bản đã ngừng bán.';
        }

        if (! $item->product_variant_id && $activeVariantProductIds->contains($product->id)) {
            return 'sản phẩm cần chọn lại phiên bản.';
        }

        return null;
    }

    private function canonicalPriceMinor(Product $product, ?ProductVariant $variant): int
    {
        if ($variant) {
            $sale = $this->decimalToMinor((string) ($variant->sale_price ?? '0'));

            return $sale > 0
                ? $sale
                : $this->decimalToMinor((string) $variant->price);
        }

        $sale = $this->decimalToMinor((string) ($product->sale_price ?? '0'));

        return $sale > 0
            ? $sale
            : $this->decimalToMinor((string) $product->price);
    }

    private function decimalToMinor(string $amount): int
    {
        $normalized = trim($amount);

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            return 0;
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return ((int) $matches[1] * 100) + (int) $fraction;
    }

    private function minorToDecimal(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    private function formatMinor(int $amount): string
    {
        $whole = intdiv($amount, 100);
        $fraction = $amount % 100;
        $formatted = number_format($whole, 0, ',', '.');

        return $fraction === 0
            ? $formatted.'đ'
            : $formatted.','.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT).'đ';
    }

    private function guestSessionId(Request $request): string
    {
        $sessionId = (string) $request->session()->get(self::GUEST_SESSION_KEY, '');

        if ($sessionId === '') {
            $sessionId = $request->session()->getId();
            $request->session()->put(self::GUEST_SESSION_KEY, $sessionId);
        }

        return $sessionId;
    }
}
