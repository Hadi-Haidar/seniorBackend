<?php

namespace App\Events;

use App\Models\Product;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductStockUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $product;
    public $previousStock;
    public $newStock;
    public $reason;
    public $orderId;
    public $buyerId;

    /**
     * Create a new event instance.
     */
    public function __construct(Product $product, int $previousStock, string $reason = 'purchase', $orderId = null, $buyerId = null)
    {
        $this->product = $product->load(['room', 'images']);
        $this->previousStock = $previousStock;
        $this->newStock = $product->stock;
        $this->reason = $reason; // 'purchase', 'cancelled', 'rejected', 'manual_update'
        $this->orderId = $orderId;
        $this->buyerId = $buyerId;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            // Broadcast to the product's room
            new PrivateChannel('chat.room.' . $this->product->room_id),
            // Broadcast to a general product channel for store pages
            new Channel('product.' . $this->product->id),
            // Broadcast to store channel for global product updates
            new Channel('store.products'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'product.stock.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'previous_stock' => $this->previousStock,
                'current_stock' => $this->newStock,
                'stock_change' => $this->newStock - $this->previousStock,
                'status' => $this->product->status,
                'price' => $this->product->price,
                'room_id' => $this->product->room_id,
            ],
            'reason' => $this->reason,
            'order_id' => $this->orderId,
            'buyer_id' => $this->buyerId,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Determine if the event should be broadcast to unauthenticated users.
     */
    public function broadcastToEveryone(): bool
    {
        // Allow unauthenticated users to see stock updates on public channels
        return true;
    }
} 