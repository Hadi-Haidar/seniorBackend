<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    // Notification types constants
    public const TYPE_POST_LIKE = 'post_like';
    public const TYPE_POST_COMMENT = 'post_comment';
    public const TYPE_ROOM_JOIN_REQUEST = 'room_join_request';
    public const TYPE_ORDER_PLACED = 'order_placed';
    public const TYPE_PAYMENT_STATUS = 'payment_status';
    public const TYPE_SYSTEM = 'system';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'is_read',
        'read_at',
        'action_url',
        'related_user_id',
        'admin_notification_type',
        'user_deleted_at'
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'user_deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get the user who receives the notification
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who triggered the notification
     */
    public function relatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'related_user_id');
    }

    /**
     * Scope to get unread notifications
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope to get read notifications
     */
    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    /**
     * Scope to filter by type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get recent notifications
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to get notifications not deleted by user
     */
    public function scopeNotDeletedByUser($query)
    {
        return $query->whereNull('user_deleted_at');
    }

    /**
     * Scope to get notifications deleted by user
     */
    public function scopeDeletedByUser($query)
    {
        return $query->whereNotNull('user_deleted_at');
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(): bool
    {
        if (!$this->is_read) {
            $this->is_read = true;
            $this->read_at = now();
            return $this->save();
        }
        return true;
    }

    /**
     * Mark notification as unread
     */
    public function markAsUnread(): bool
    {
        if ($this->is_read) {
            $this->is_read = false;
            $this->read_at = null;
            return $this->save();
        }
        return true;
    }

    /**
     * Get notification icon based on type
     */
    public function getIconAttribute(): string
    {
        return match($this->type) {
            self::TYPE_POST_LIKE => 'heart',
            self::TYPE_POST_COMMENT => 'message-circle',
            self::TYPE_ROOM_JOIN_REQUEST => 'user-plus',
            self::TYPE_ORDER_PLACED => 'shopping-cart',
            self::TYPE_PAYMENT_STATUS => 'credit-card',
            self::TYPE_SYSTEM => 'bell',
            default => 'bell'
        };
    }

    /**
     * Get notification color based on type
     */
    public function getColorAttribute(): string
    {
        return match($this->type) {
            self::TYPE_POST_LIKE => 'text-red-500',
            self::TYPE_POST_COMMENT => 'text-blue-500',
            self::TYPE_ROOM_JOIN_REQUEST => 'text-green-500',
            self::TYPE_ORDER_PLACED => 'text-purple-500',
            self::TYPE_PAYMENT_STATUS => 'text-yellow-500',
            self::TYPE_SYSTEM => 'text-blue-600',
            default => 'text-gray-500'
        };
    }

    /**
     * Static method to create notification for post like
     */
    public static function createPostLikeNotification($post, $liker): self
    {
        return self::create([
            'user_id' => $post->user_id,
            'type' => self::TYPE_POST_LIKE,
            'title' => 'Post Liked',
            'message' => "{$liker->name} liked your post \"{$post->title}\"",
            'data' => [
                'post_id' => $post->id,
                'room_id' => $post->room_id,
                'liker_id' => $liker->id
            ],
            'action_url' => "/user/rooms/{$post->room_id}/posts/{$post->id}",
            'related_user_id' => $liker->id
        ]);
    }

    /**
     * Static method to create notification for post comment
     */
    public static function createPostCommentNotification($post, $commenter, $comment): self
    {
        return self::create([
            'user_id' => $post->user_id,
            'type' => self::TYPE_POST_COMMENT,
            'title' => 'New Comment',
            'message' => "{$commenter->name} commented on your post \"{$post->title}\"",
            'data' => [
                'post_id' => $post->id,
                'room_id' => $post->room_id,
                'comment_id' => $comment->id,
                'commenter_id' => $commenter->id
            ],
            'action_url' => "/user/rooms/{$post->room_id}/posts/{$post->id}",
            'related_user_id' => $commenter->id
        ]);
    }

    /**
     * Static method to create notification for room join request
     */
    public static function createRoomJoinRequestNotification($room, $requester, $recipient): self
    {
        return self::create([
            'user_id' => $recipient->id,
            'type' => self::TYPE_ROOM_JOIN_REQUEST,
            'title' => 'Room Join Request',
            'message' => "{$requester->name} wants to join your room \"{$room->name}\"",
            'data' => [
                'room_id' => $room->id,
                'requester_id' => $requester->id
            ],
            'action_url' => "/user/rooms/{$room->id}/manage",
            'related_user_id' => $requester->id
        ]);
    }

    /**
     * Static method to create notification for order placed
     */
    public static function createOrderPlacedNotification($order, $seller): self
    {
        return self::create([
            'user_id' => $seller->id,
            'type' => self::TYPE_ORDER_PLACED,
            'title' => 'New Order',
            'message' => "{$order->buyer->name} placed an order for {$order->product->name}",
            'data' => [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'buyer_id' => $order->buyer_id,
                'placed_from' => $order->placed_from
            ],
            'action_url' => "/user/orders/{$order->id}",
            'related_user_id' => $order->buyer_id
        ]);
    }

    /**
     * Static method to create notification for payment status update
     */
    public static function createPaymentStatusNotification($payment): self
    {
        $status = $payment->payment_status;
        $message = match($status) {
            'completed' => "Your payment of \${$payment->amount} has been approved and added to your balance",
            'rejected' => "Your payment of \${$payment->amount} has been rejected",
            default => "Your payment status has been updated to {$status}"
        };

        return self::create([
            'user_id' => $payment->user_id,
            'type' => self::TYPE_PAYMENT_STATUS,
            'title' => 'Payment Status Update',
            'message' => $message,
            'data' => [
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'status' => $status,
                'transaction_id' => $payment->transaction_id
            ],
            'action_url' => "/user/coins/history",
            'related_user_id' => null
        ]);
    }

    /**
     * Static method to create system notification (from admin)
     */
    public static function createSystemNotification($userId, $title, $message, $adminNotificationType = null, $targetAudience = null): self
    {
        return self::create([
            'user_id' => $userId,
            'type' => self::TYPE_SYSTEM,
            'title' => $title,
            'message' => $message,
            'data' => [
                'admin_notification_type' => $adminNotificationType,
                'target_audience' => $targetAudience,
                'sent_by' => 'admin',
                'sent_at' => now()->toISOString(),
                'is_individual' => false  // Explicitly mark as campaign notification
            ],
            'action_url' => null,
            'related_user_id' => null,
            'admin_notification_type' => $adminNotificationType
        ]);
    }

    /**
     * Get users by target audience
     */
    public static function getUsersByTargetAudience($targetAudience): \Illuminate\Database\Eloquent\Collection
    {
        $query = \App\Models\User::query();

        switch ($targetAudience) {
            case 'All Users':
                // No filter - all users
                break;
            case 'Bronze Users':
                $query->where('subscription_level', 'bronze');
                break;
            case 'Silver Users':
                $query->where('subscription_level', 'silver');
                break;
            case 'Gold Users':
                $query->where('subscription_level', 'gold');
                break;
            case 'Room Owners':
                $query->whereHas('ownedRooms');
                break;
            case 'New Users':
                $query->where('created_at', '>=', now()->subDays(7));
                break;
            default:
                // Default to all users if unknown audience
                break;
        }

        return $query->get();
    }
}
