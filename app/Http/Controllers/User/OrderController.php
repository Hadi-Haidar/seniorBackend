<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\OrderChatDeletion;
use App\Models\Product;
use App\Models\RoomMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\CartItem;
use App\Services\NotificationService;
use App\Events\ProductStockUpdated;

class OrderController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Place a new order on a product
     */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'phone_number' => 'required|string|max:20',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'delivery_notes' => 'nullable|string|max:500',
            'from_cart' => 'nullable|boolean', // Optional parameter to indicate if order comes from cart
            'placed_from' => 'required|in:store,room' // Track where the order was placed from
        ]);

        $product = Product::with('room')->findOrFail($request->product_id);
        
        // Check if user is trying to order their own product
        if ($product->room->owner_id === Auth::id()) {
            return response()->json([
                'error' => 'You cannot order your own products'
            ], 400);
        }
        
        // Check if product is active
        if ($product->status !== 'active') {
            return response()->json([
                'error' => 'This product is not available for ordering'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $fromCart = $request->boolean('from_cart', false);
            
            if ($fromCart) {
                // If ordering from cart, find the cart item and confirm purchase
                $cartItem = CartItem::where('user_id', Auth::id())
                    ->where('product_id', $request->product_id)
                    ->where('quantity', '>=', $request->quantity)
                    ->first();

                if (!$cartItem) {
                    return response()->json([
                        'error' => 'Cart item not found or insufficient quantity in cart'
                    ], 400);
                }

                // Confirm purchase (this will make the stock reduction permanent)
                if (!$cartItem->confirmPurchase()) {
                    return response()->json([
                        'error' => 'Failed to confirm purchase - insufficient stock'
                    ], 400);
                }

                // If ordering partial quantity from cart, update cart item
                if ($cartItem->quantity > $request->quantity) {
                    $cartItem->quantity -= $request->quantity;
                    $cartItem->reserved_stock -= $request->quantity;
                    $cartItem->save();
                } else {
                    // Remove cart item if entire quantity is ordered
                    $cartItem->delete();
                }
            } else {
                // Direct order (Buy Now) - check and reduce stock immediately
                if ($product->stock < $request->quantity) {
                    return response()->json([
                        'error' => 'Insufficient stock. Available: ' . $product->stock
                    ], 400);
                }

                // Store previous stock for broadcasting
                $previousStock = $product->stock;
                
                // Reduce stock immediately for direct orders
                $product->stock -= $request->quantity;
                $product->save();
                
                // Broadcast stock update
                broadcast(new ProductStockUpdated($product, $previousStock, 'purchase', null, Auth::id()));
            }

            $totalPrice = $product->price * $request->quantity;

            $order = Order::create([
                'product_id' => $request->product_id,
                'buyer_id' => Auth::id(),
                'quantity' => $request->quantity,
                'total_price' => $totalPrice,
                'phone_number' => $request->phone_number,
                'address' => $request->address,
                'city' => $request->city,
                'delivery_notes' => $request->delivery_notes,
                'status' => 'pending',
                'placed_from' => $request->placed_from
            ]);

            // Send notification to seller
            try {
                $this->notificationService->sendOrderPlacedNotification($order);
            } catch (\Exception $e) {
                \Log::error('Failed to send order notification', [
                    'order_id' => $order->id,
                    'product_id' => $order->product_id,
                    'buyer_id' => $order->buyer_id,
                    'error' => $e->getMessage()
                ]);
                // Don't fail the order if notification fails
            }

            DB::commit();

            return response()->json([
                'message' => 'Order placed successfully',
                'order' => $order->load(['product', 'buyer'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to place order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List all orders for current user (as buyer or seller)
     */
    public function index()
    {
        $user = Auth::user();
        
        // Get orders where user is buyer and hasn't deleted the chat (only main orders)
        $buyerOrders = Order::with(['product:id,name,visibility,room_id', 'product.room.owner:id,name,avatar', 'product.images', 'childOrders.product.images'])
            ->select('*') // Include all order fields including placed_from and batch_id
            ->where('buyer_id', $user->id)
            ->whereNull('parent_order_id') // Only main orders
            ->whereDoesntHave('chatDeletions', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // Get orders where user is seller (owns the product's room) and hasn't deleted the chat (only main orders)
        $sellerOrders = Order::with(['product:id,name,visibility,room_id', 'product.room.owner:id,name,avatar', 'product.images', 'buyer:id,name,avatar', 'childOrders.product.images'])
            ->select('*') // Include all order fields including placed_from and batch_id
            ->whereHas('product.room', function($query) use ($user) {
                $query->where('owner_id', $user->id);
            })
            ->whereNull('parent_order_id') // Only main orders
            ->whereDoesntHave('chatDeletions', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // Add membership information to each order
        $allOrders = collect([$buyerOrders, $sellerOrders])->flatten();
        $roomIds = $allOrders->pluck('product.room_id')->unique()->values();
        $buyerIds = $allOrders->pluck('buyer_id')->unique()->values();
        
        // Get all buyer memberships for all relevant rooms
        $buyerMemberships = RoomMember::whereIn('user_id', $buyerIds)
            ->whereIn('room_id', $roomIds)
            ->where('status', 'approved')
            ->get()
            ->groupBy('user_id')
            ->map(function($memberships) {
                return $memberships->pluck('room_id')->toArray();
            });

        // Add membership information to each order based on BUYER's membership
        $buyerOrders->each(function($order) use ($buyerMemberships) {
            $roomId = $order->product->room_id;
            $buyerId = $order->buyer_id;
            $buyerRoomMemberships = $buyerMemberships->get($buyerId, []);
            // Check if buyer is a member OR if buyer is the room owner
            $order->is_buyer_member_of_room = in_array($roomId, $buyerRoomMemberships) || $order->product->room->owner_id === $buyerId;
        });

        $sellerOrders->each(function($order) use ($buyerMemberships) {
            $roomId = $order->product->room_id;
            $buyerId = $order->buyer_id;
            $buyerRoomMemberships = $buyerMemberships->get($buyerId, []);
            // Check if buyer is a member OR if buyer is the room owner
            $order->is_buyer_member_of_room = in_array($roomId, $buyerRoomMemberships) || $order->product->room->owner_id === $buyerId;
        });

        return response()->json([
            'buyer_orders' => $buyerOrders,
            'seller_orders' => $sellerOrders
        ]);
    }

    /**
     * View single order (ensure buyer/seller access only)
     */
    public function show($id)
    {
        $order = Order::with(['product:id,name,visibility,room_id', 'product.room.owner:id,name,avatar', 'product.images', 'buyer:id,name,avatar', 'messages.sender:id,name,avatar'])
            ->findOrFail($id);

        $user = Auth::user();
        
        // Check if user is buyer or seller
        if ($order->buyer_id !== $user->id && $order->product->room->owner_id !== $user->id) {
            return response()->json([
                'error' => 'You do not have access to this order'
            ], 403);
        }

        return response()->json([
            'order' => $order
        ]);
    }

    /**
     * Update order status (seller only)
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:accepted,rejected,delivered'
        ]);

        $order = Order::findOrFail($id);
        $user = Auth::user();

        // Check if user is the seller (owns the product's room)
        if ($order->product->room->owner_id !== $user->id) {
            return response()->json([
                'error' => 'Only the seller can update order status'
            ], 403);
        }

        // Validate status transitions
        $validTransitions = [
            'pending' => ['accepted', 'rejected','cancelled'],
            'accepted' => ['delivered'],
            'delivered' => [],
            'rejected' => [],
            'cancelled' => []
        ];

        if (!in_array($request->status, $validTransitions[$order->status])) {
            return response()->json([
                'error' => 'Invalid status transition from ' . $order->status . ' to ' . $request->status
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Get the main order (either this order or its parent)
            $mainOrder = $order->isMainOrder() ? $order : $order->parentOrder;
            
            // Update main order status
            $mainOrder->update(['status' => $request->status]);
            
            // If seller is rejecting the order, return stock to product
            if ($request->status === 'rejected') {
                $product = $mainOrder->product;
                $previousStock = $product->stock;
                $product->stock += $mainOrder->quantity;
                $product->save();
                
                // Broadcast stock update
                broadcast(new ProductStockUpdated($product, $previousStock, 'rejected', $mainOrder->id, $mainOrder->buyer_id));
            }
            
            // Get all child orders and update them
            $childOrders = $mainOrder->childOrders;
            if ($childOrders->count() > 0) {
                foreach ($childOrders as $childOrder) {
                    // If seller is rejecting the order, return stock to product
                    if ($request->status === 'rejected') {
                        $product = $childOrder->product;
                        $previousStock = $product->stock;
                        $product->stock += $childOrder->quantity;
                        $product->save();
                        
                        // Broadcast stock update for child order
                        broadcast(new ProductStockUpdated($product, $previousStock, 'rejected', $childOrder->id, $childOrder->buyer_id));
                    }
                    
                    $childOrder->update(['status' => $request->status]);
                }
                
                $message = 'All items in the order updated successfully';
            } else {
                $message = 'Order status updated successfully';
            }

            DB::commit();

            return response()->json([
                'message' => $message,
                'order' => $order->fresh()->load(['product', 'buyer'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to update order status'
            ], 500);
        }
    }

    /**
     * Cancel order (buyer only, if status is pending)
     */
    public function cancel($id)
    {
        $order = Order::findOrFail($id);
        $user = Auth::user();

        // Check if user is the buyer
        if ($order->buyer_id !== $user->id) {
            return response()->json([
                'error' => 'Only the buyer can cancel the order'
            ], 403);
        }

        // Check if order can be cancelled
        if (!$order->canBeCancelled()) {
            return response()->json([
                'error' => 'Order cannot be cancelled. Current status: ' . $order->status
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Get the main order (either this order or its parent)
            $mainOrder = $order->isMainOrder() ? $order : $order->parentOrder;
            
            // Cancel main order and return stock
            $product = $mainOrder->product;
            $previousStock = $product->stock;
            $product->stock += $mainOrder->quantity;
            $product->save();
            $mainOrder->update(['status' => 'cancelled']);
            
            // Broadcast stock update
            broadcast(new ProductStockUpdated($product, $previousStock, 'cancelled', $mainOrder->id, $mainOrder->buyer_id));
            
            // Cancel all child orders and return their stock
            $childOrders = $mainOrder->childOrders;
            if ($childOrders->count() > 0) {
                foreach ($childOrders as $childOrder) {
                    $product = $childOrder->product;
                    $previousStock = $product->stock;
                    $product->stock += $childOrder->quantity;
                    $product->save();
                    
                    // Broadcast stock update for child order
                    broadcast(new ProductStockUpdated($product, $previousStock, 'cancelled', $childOrder->id, $childOrder->buyer_id));
                    
                    $childOrder->update(['status' => 'cancelled']);
                }
                
                $message = 'All items in the order cancelled successfully';
            } else {
                $message = 'Order cancelled successfully';
            }

            DB::commit();

            return response()->json([
                'message' => $message,
                'order' => $order->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to cancel order'
            ], 500);
        }
    }

    /**
     * Send a message in the order chat
     */
    public function sendMessage(Request $request, $orderId)
    {
        $request->validate([
            'message' => 'nullable|string|max:1000',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048'
        ]);

        // Ensure at least one field is provided
        if (!$request->message && !$request->hasFile('image')) {
            return response()->json([
                'error' => 'Please provide either a message or an image'
            ], 400);
        }

        $order = Order::findOrFail($orderId);
        $user = Auth::user();

        // Check if user is buyer or seller
        if ($order->buyer_id !== $user->id && $order->product->room->owner_id !== $user->id) {
            return response()->json([
                'error' => 'You do not have access to this order chat'
            ], 403);
        }

        $messageData = [
            'order_id' => $orderId,
            'sender_id' => $user->id,
            'type' => 'text',
            'message' => $request->message
        ];

        // Handle image upload
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('order-messages', 'public');
            $messageData['type'] = 'image';
            $messageData['file_path'] = $path;
            $messageData['message'] = null;
        }

        $message = OrderMessage::create($messageData);

        return response()->json([
            'message' => 'Message sent successfully',
            'order_message' => $message->load('sender:id,name,avatar')
        ], 201);
    }

    /**
     * View all messages in an order thread
     */
    public function getMessages($orderId)
    {
        $order = Order::findOrFail($orderId);
        $user = Auth::user();

        // Check if user is buyer or seller
        if ($order->buyer_id !== $user->id && $order->product->room->owner_id !== $user->id) {
            return response()->json([
                'error' => 'You do not have access to this order chat'
            ], 403);
        }

        // Check if user has deleted the chat
        if ($order->isChatDeletedByUser($user->id)) {
            return response()->json([
                'order' => $order->load(['product:id,name,visibility,room_id', 'product.room.owner:id,name,avatar', 'buyer:id,name,avatar']),
                'messages' => [],
                'chat_deleted' => true
            ]);
        }

        $messages = OrderMessage::with('sender:id,name,avatar')
            ->where('order_id', $orderId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'order' => $order->load(['product:id,name,visibility,room_id', 'product.room.owner:id,name,avatar', 'buyer:id,name,avatar']),
            'messages' => $messages,
            'chat_deleted' => false,
            'can_delete_chat' => $order->canDeleteChat()
        ]);
    }

    /**
     * Delete chat for the current user (only if order status allows)
     */
    public function deleteChat($orderId)
    {
        $order = Order::with(['product.room', 'chatDeletions'])->findOrFail($orderId);
        $user = Auth::user();

        // Check if user is buyer or seller
        if ($order->buyer_id !== $user->id && $order->product->room->owner_id !== $user->id) {
            return response()->json([
                'error' => 'You do not have access to this order chat'
            ], 403);
        }

        // Check if chat deletion is allowed for this order status
        if (!$order->canDeleteChat()) {
            return response()->json([
                'error' => 'Chat deletion is only allowed for orders with status: delivered, rejected, or cancelled'
            ], 400);
        }

        // Check if user has already deleted the chat
        if ($order->isChatDeletedByUser($user->id)) {
            return response()->json([
                'error' => 'You have already deleted this chat'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Create deletion record for this user
            OrderChatDeletion::create([
                'order_id' => $orderId,
                'user_id' => $user->id,
                'deleted_at' => now()
            ]);

            // Check if both parties have now deleted the chat
            if ($order->isChatDeletedByBothParties()) {
                // Permanently delete all messages and chat deletion records
                OrderMessage::where('order_id', $orderId)->delete();
                OrderChatDeletion::where('order_id', $orderId)->delete();
                
                $message = 'Chat deleted permanently as both parties have deleted it';
            } else {
                $message = 'Chat deleted from your view. The other party can still see it until they also delete it';
            }

            DB::commit();

            return response()->json([
                'message' => $message,
                'permanently_deleted' => $order->isChatDeletedByBothParties()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to delete chat: ' . $e->getMessage()
            ], 500);
        }
    }
}
