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

class ProductRatingUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $product;
    public $averageRating;
    public $reviewsCount;
    public $action;
    public $reviewId;
    public $userId;

    /**
     * Create a new event instance.
     */
    public function __construct(Product $product, string $action = 'created', $reviewId = null, $userId = null)
    {
        $this->product = $product->load(['room']);
        $this->averageRating = round($product->average_rating, 1);
        $this->reviewsCount = $product->reviews_count;
        $this->action = $action; // 'created', 'updated', 'deleted'
        $this->reviewId = $reviewId;
        $this->userId = $userId;
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
        return 'product.rating.updated';
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
                'average_rating' => $this->averageRating,
                'reviews_count' => $this->reviewsCount,
                'room_id' => $this->product->room_id,
            ],
            'action' => $this->action,
            'review_id' => $this->reviewId,
            'user_id' => $this->userId,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Determine if the event should be broadcast to unauthenticated users.
     */
    public function broadcastToEveryone(): bool
    {
        // Allow unauthenticated users to see rating updates on public channels
        return true;
    }
} 