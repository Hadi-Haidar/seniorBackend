<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Events\ProductStockUpdated;

class CartController extends Controller
{
    /**
     * Get user's cart items
     */
    public function index()
    {
        $cartItems = Auth::user()->cartItems()
            ->with(['product.images', 'product.room'])
            ->get();

        // Calculate available stock for each cart item (current stock + reserved stock)
        $cartItems->each(function ($cartItem) {
            $product = $cartItem->product;
            
            // Available stock = current product stock + reserved stock for this cart item
            $cartItem->available_stock = $product->stock + $cartItem->reserved_stock;
            
            // Can purchase if we have enough reserved stock OR enough product stock
            // Since we reserve stock when adding to cart, reserved_stock should always >= quantity
            $cartItem->can_purchase = ($cartItem->reserved_stock >= $cartItem->quantity) || 
                                    ($product->stock >= $cartItem->quantity);
            
            // Additional check: make sure product is still active
            if ($product->status !== 'active') {
                $cartItem->can_purchase = false;
            }
            

        });

        $total = $cartItems->sum('total_price');

        return response()->json([
            'cart_items' => $cartItems,
            'total' => $total,
            'count' => $cartItems->count()
        ]);
    }

    /**
     * Add item to cart
     */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1'
        ]);

        $product = Product::with('room')->findOrFail($request->product_id);

        // Check if user is trying to add their own product to cart
        if ($product->room->owner_id === Auth::id()) {
            return response()->json([
                'error' => 'You cannot add your own products to cart'
            ], 400);
        }

        // Check if product is active
        if ($product->status !== 'active') {
            return response()->json([
                'error' => 'This product is not available'
            ], 400);
        }

        // Check if enough stock is available
        if ($product->stock < $request->quantity) {
            return response()->json([
                'error' => 'Insufficient stock. Available: ' . $product->stock
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Check if item already exists in cart
            $cartItem = CartItem::where('user_id', Auth::id())
                ->where('product_id', $request->product_id)
                ->first();

            if ($cartItem) {
                // Update quantity and reserve additional stock
                $newQuantity = $cartItem->quantity + $request->quantity;
                
                // Check if new total quantity is available
                $currentReserved = $cartItem->reserved_stock;
                $additionalNeeded = $request->quantity;
                
                if ($product->stock < $additionalNeeded) {
                    return response()->json([
                        'error' => 'Cannot add that quantity. Available: ' . $product->stock . ', Currently in cart: ' . $cartItem->quantity
                    ], 400);
                }
                
                // Store previous stock for broadcasting
                $previousStock = $product->stock;
                
                // Reserve additional stock
                $product->stock -= $additionalNeeded;
                $product->save();
                
                // Broadcast stock update
                broadcast(new ProductStockUpdated($product, $previousStock, 'cart_reserved', null, Auth::id()));
                
                // Update cart item
                $cartItem->quantity = $newQuantity;
                $cartItem->reserved_stock = $currentReserved + $additionalNeeded;
                $cartItem->save();
            } else {
                // Store previous stock for broadcasting
                $previousStock = $product->stock;
                
                // Reserve stock for new cart item
                $product->stock -= $request->quantity;
                $product->save();
                
                // Broadcast stock update
                broadcast(new ProductStockUpdated($product, $previousStock, 'cart_reserved', null, Auth::id()));
                
                // Create new cart item
                $cartItem = CartItem::create([
                    'user_id' => Auth::id(),
                    'product_id' => $request->product_id,
                    'quantity' => $request->quantity,
                    'reserved_stock' => $request->quantity
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Item added to cart successfully',
                'cart_item' => $cartItem->load(['product.images'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to add item to cart'
            ], 500);
        }
    }

    /**
     * Update cart item quantity
     */
    public function update(Request $request, $cartItemId)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1'
        ]);

        $cartItem = CartItem::where('id', $cartItemId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $product = $cartItem->product;

        try {
            DB::beginTransaction();

            $oldQuantity = $cartItem->quantity;
            $newQuantity = $request->quantity;
            $quantityDifference = $newQuantity - $oldQuantity;

            if ($quantityDifference > 0) {
                // Increasing quantity - need to reserve more stock
                if ($product->stock < $quantityDifference) {
                    return response()->json([
                        'error' => 'Insufficient stock. Available: ' . $product->stock
                    ], 400);
                }
                
                // Store previous stock for broadcasting
                $previousStock = $product->stock;
                
                // Reserve additional stock
                $product->stock -= $quantityDifference;
                $product->save();
                
                // Broadcast stock update
                broadcast(new ProductStockUpdated($product, $previousStock, 'cart_increased', null, Auth::id()));
                
                $cartItem->reserved_stock += $quantityDifference;
            } else if ($quantityDifference < 0) {
                // Decreasing quantity - release some stock
                $stockToRelease = abs($quantityDifference);
                
                // Store previous stock for broadcasting
                $previousStock = $product->stock;
                
                // Return stock to product
                $product->stock += $stockToRelease;
                $product->save();
                
                // Broadcast stock update
                broadcast(new ProductStockUpdated($product, $previousStock, 'cart_decreased', null, Auth::id()));
                
                $cartItem->reserved_stock -= $stockToRelease;
            }

            // Update quantity
            $cartItem->quantity = $newQuantity;
            $cartItem->save();

            DB::commit();

            return response()->json([
                'message' => 'Cart item updated successfully',
                'cart_item' => $cartItem->load(['product.images'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to update cart item'
            ], 500);
        }
    }

    /**
     * Remove item from cart
     */
    public function destroy($cartItemId)
    {
        $cartItem = CartItem::where('id', $cartItemId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        try {
            DB::beginTransaction();

            // Release reserved stock back to product
            if ($cartItem->reserved_stock > 0) {
                $product = $cartItem->product;
                $previousStock = $product->stock;
                $product->stock += $cartItem->reserved_stock;
                $product->save();
                
                // Broadcast stock update
                broadcast(new ProductStockUpdated($product, $previousStock, 'cart_released', null, Auth::id()));
            }

            // Delete cart item
            $cartItem->delete();

            DB::commit();

            return response()->json([
                'message' => 'Item removed from cart successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to remove item from cart'
            ], 500);
        }
    }

    /**
     * Clear entire cart
     */
    public function clear()
    {
        try {
            DB::beginTransaction();

            $cartItems = Auth::user()->cartItems()->with('product')->get();

            // Release all reserved stock
            foreach ($cartItems as $cartItem) {
                if ($cartItem->reserved_stock > 0) {
                    $product = $cartItem->product;
                    $previousStock = $product->stock;
                    $product->stock += $cartItem->reserved_stock;
                    $product->save();
                    
                    // Broadcast stock update
                    broadcast(new ProductStockUpdated($product, $previousStock, 'cart_cleared', null, Auth::id()));
                }
            }

            // Delete all cart items
            Auth::user()->cartItems()->delete();

            DB::commit();

            return response()->json([
                'message' => 'Cart cleared successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to clear cart'
            ], 500);
        }
    }

    /**
     * Get cart count for user
     */
    public function count()
    {
        $count = Auth::user()->cartItems()->sum('quantity');

        return response()->json([
            'count' => $count
        ]);
    }

    /**
     * Purchase all items in cart (create orders for all cart items)
     */
    public function purchaseAll(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string|max:20',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'delivery_notes' => 'nullable|string|max:500',
            'placed_from' => 'required|in:store,room', // Track where the cart purchase was initiated
        ]);

        $cartItems = Auth::user()->cartItems()->with('product')->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'error' => 'Cart is empty'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Check if we have multiple different products
            $uniqueProductIds = $cartItems->pluck('product_id')->unique();
            $hasMultipleProducts = $uniqueProductIds->count() > 1;

            $totalAmount = 0;
            $orders = [];
            $mainOrder = null;

            if ($hasMultipleProducts) {
                // Generate a unique batch ID for grouping orders
                $batchId = 'BATCH-' . strtoupper(uniqid()) . '-' . time();
            }

            foreach ($cartItems as $index => $cartItem) {
                $product = $cartItem->product;



                // Check if product is still active
                if ($product->status !== 'active') {
                    DB::rollBack();
                    return response()->json([
                        'error' => "Product '{$product->name}' is no longer available"
                    ], 400);
                }

                // Check if we can purchase this cart item
                $canPurchase = ($cartItem->reserved_stock >= $cartItem->quantity) || 
                              ($product->stock >= $cartItem->quantity);
                
                if (!$canPurchase) {
                    DB::rollBack();
                    return response()->json([
                        'error' => "Cannot purchase '{$product->name}' - insufficient stock. Available: {$product->stock}, Reserved: {$cartItem->reserved_stock}, Needed: {$cartItem->quantity}"
                    ], 400);
                }

                // For cart items, the stock is already reserved, so we just need to make it permanent
                if ($cartItem->reserved_stock >= $cartItem->quantity) {
                    // Stock is already reserved, just clear the reservation
                    // (the stock was already reduced when adding to cart)
                    $cartItem->reserved_stock = 0;
                    $cartItem->save();
                } else {
                    // This should rarely happen, but if reserved stock is insufficient,
                    // try to reduce from current product stock
                    $neededStock = $cartItem->quantity - $cartItem->reserved_stock;
                    if ($product->stock >= $neededStock) {
                        $product->stock -= $neededStock;
                        $product->save();
                        $cartItem->reserved_stock = 0;
                        $cartItem->save();
                    } else {
                        DB::rollBack();
                        return response()->json([
                            'error' => "Failed to confirm purchase for '{$product->name}' - insufficient stock"
                        ], 400);
                    }
                }

                // Calculate total price
                $totalPrice = $product->price * $cartItem->quantity;
                $totalAmount += $totalPrice;

                // Create order data
                $orderData = [
                    'product_id' => $product->id,
                    'buyer_id' => Auth::id(),
                    'quantity' => $cartItem->quantity,
                    'total_price' => $totalPrice,
                    'phone_number' => $request->phone_number,
                    'address' => $request->address,
                    'city' => $request->city,
                    'delivery_notes' => $request->delivery_notes,
                    'status' => 'pending',
                    'placed_from' => $request->placed_from
                ];

                if ($hasMultipleProducts) {
                    // Add batch_id for multiple products
                    $orderData['batch_id'] = $batchId;
                    
                    if ($index === 0) {
                        // First item becomes the main order
                        $mainOrder = \App\Models\Order::create($orderData);
                        $orders[] = $mainOrder->load(['product', 'buyer']);
                    } else {
                        // Other items become child orders
                        $orderData['parent_order_id'] = $mainOrder->id;
                        $childOrder = \App\Models\Order::create($orderData);
                        $orders[] = $childOrder->load(['product', 'buyer']);
                    }
                } else {
                    // Single product type - create regular orders
                    $order = \App\Models\Order::create($orderData);
                    $orders[] = $order->load(['product', 'buyer']);
                }
            }

            // Clear the cart after successful orders
            Auth::user()->cartItems()->delete();

            DB::commit();

            return response()->json([
                'message' => 'All cart items purchased successfully',
                'total_orders' => $hasMultipleProducts ? 1 : count($orders), // Only count main orders
                'total_amount' => $totalAmount,
                'orders' => $orders,
                'main_order_id' => $mainOrder ? $mainOrder->id : $orders[0]->id
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Purchase all failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to purchase cart items: ' . $e->getMessage()
            ], 500);
        }
    }
}
