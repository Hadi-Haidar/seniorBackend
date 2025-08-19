<?php

namespace App\Services;

use App\Models\Notification;
use App\Events\NotificationCreated;
use App\Models\User;
use App\Models\Post;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\Order;
use App\Models\Payment;

class NotificationService
{
    /**
     * Create a notification and broadcast it
     */
    protected function createAndBroadcast(array $data): Notification
    {
        $notification = Notification::create($data);
        $notification->load('relatedUser'); // Load related user for broadcasting
        
        // Broadcast the notification
        broadcast(new NotificationCreated($notification));
        
        return $notification;
    }

    /**
     * Send notification when a post is liked
     */
    public function sendPostLikeNotification(Post $post, User $liker): ?Notification
    {
        // Don't notify if user likes their own post
        if ($post->user_id === $liker->id) {
            return null;
        }

        // Check if notification for this like already exists to prevent duplicates
        $existingNotification = Notification::where('user_id', $post->user_id)
            ->where('type', Notification::TYPE_POST_LIKE)
            ->whereJsonContains('data->post_id', $post->id)
            ->whereJsonContains('data->liker_id', $liker->id)
            ->first();

        if ($existingNotification) {
            return $existingNotification;
        }

        return $this->createAndBroadcast([
            'user_id' => $post->user_id,
            'type' => Notification::TYPE_POST_LIKE,
            'title' => 'Post Liked',
            'message' => "{$liker->name} liked your post \"{$post->title}\"",
            'data' => [
                'post_id' => $post->id,
                'room_id' => $post->room_id,
                'liker_id' => $liker->id
            ],
            'action_url' => "/user/rooms/{$post->room_id}",
            'related_user_id' => $liker->id
        ]);
    }

    /**
     * Send notification when a post receives a comment
     */
    public function sendPostCommentNotification(Post $post, User $commenter, $comment): ?Notification
    {
        // Don't notify if user comments on their own post
        if ($post->user_id === $commenter->id) {
            return null;
        }

        return $this->createAndBroadcast([
            'user_id' => $post->user_id,
            'type' => Notification::TYPE_POST_COMMENT,
            'title' => 'New Comment',
            'message' => "{$commenter->name} commented on your post \"{$post->title}\"",
            'data' => [
                'post_id' => $post->id,
                'room_id' => $post->room_id,
                'comment_id' => $comment->id,
                'commenter_id' => $commenter->id
            ],
            'action_url' => "/user/rooms/{$post->room_id}",
            'related_user_id' => $commenter->id
        ]);
    }

    /**
     * Send notification when a user requests to join a private room
     */
    public function sendRoomJoinRequestNotifications(Room $room, User $requester): array
    {
        $notifications = [];

        // Check if notification for this join request already exists for the owner
        $existingOwnerNotification = Notification::where('user_id', $room->owner_id)
            ->where('type', Notification::TYPE_ROOM_JOIN_REQUEST)
            ->whereJsonContains('data->room_id', $room->id)
            ->whereJsonContains('data->requester_id', $requester->id)
            ->first();

        if (!$existingOwnerNotification) {
            // Notify room owner
            $notifications[] = $this->createAndBroadcast([
                'user_id' => $room->owner_id,
                'type' => Notification::TYPE_ROOM_JOIN_REQUEST,
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

        // Notify all moderators (excluding the owner to avoid duplicate notifications)
        $moderators = RoomMember::where('room_id', $room->id)
            ->where('role', 'moderator')
            ->where('status', 'approved')
            ->where('user_id', '!=', $requester->id) // Don't notify the requester
            ->where('user_id', '!=', $room->owner_id) // Don't notify the owner again
            ->with('user')
            ->get();

        foreach ($moderators as $moderator) {
            // Check if notification already exists for this moderator
            $existingModeratorNotification = Notification::where('user_id', $moderator->user_id)
                ->where('type', Notification::TYPE_ROOM_JOIN_REQUEST)
                ->whereJsonContains('data->room_id', $room->id)
                ->whereJsonContains('data->requester_id', $requester->id)
                ->first();

            if (!$existingModeratorNotification) {
                $notifications[] = $this->createAndBroadcast([
                    'user_id' => $moderator->user_id,
                    'type' => Notification::TYPE_ROOM_JOIN_REQUEST,
                    'title' => 'Room Join Request',
                    'message' => "{$requester->name} wants to join room \"{$room->name}\"",
                    'data' => [
                        'room_id' => $room->id,
                        'requester_id' => $requester->id
                    ],
                    'action_url' => "/user/rooms/{$room->id}/manage",
                    'related_user_id' => $requester->id
                ]);
            }
        }

        return $notifications;
    }

    /**
     * Send notification when an order is placed
     */
    public function sendOrderPlacedNotification(Order $order): Notification
    {
        // Get the product owner
        $product = $order->product()->with('room')->first();
        $seller = User::find($product->room->owner_id);

        return $this->createAndBroadcast([
            'user_id' => $seller->id,
            'type' => Notification::TYPE_ORDER_PLACED,
            'title' => 'New Order',
            'message' => "{$order->buyer->name} placed an order for {$product->name}",
            'data' => [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'buyer_id' => $order->buyer_id,
                'placed_from' => $order->placed_from,
                'product_name' => $product->name,
                'quantity' => $order->quantity,
                'total_price' => $order->total_price
            ],
            'action_url' => "/user/orders/{$order->id}",
            'related_user_id' => $order->buyer_id
        ]);
    }

    /**
     * Send notification when payment status is updated
     */
    public function sendPaymentStatusNotification(Payment $payment): Notification
    {
        $status = $payment->payment_status;
        $message = match($status) {
            'completed' => "Your payment of \${$payment->amount} has been approved and added to your balance",
            'rejected' => "Your payment of \${$payment->amount} has been rejected" . 
                         ($payment->reject_reason ? ": {$payment->reject_reason}" : ""),
            default => "Your payment status has been updated to {$status}"
        };

        return $this->createAndBroadcast([
            'user_id' => $payment->user_id,
            'type' => Notification::TYPE_PAYMENT_STATUS,
            'title' => 'Payment Status Update',
            'message' => $message,
            'data' => [
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'status' => $status,
                'transaction_id' => $payment->transaction_id,
                'reject_reason' => $payment->reject_reason
            ],
            'action_url' => "/user/coins/history",
            'related_user_id' => null
        ]);
    }

    /**
     * Get unread notifications count for a user
     */
    public function getUnreadCount(User $user): int
    {
        return $user->notifications()->notDeletedByUser()->unread()->count();
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Notification $notification): bool
    {
        return $notification->markAsRead();
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead(User $user): int
    {
        return $user->notifications()->notDeletedByUser()->unread()->update([
            'is_read' => true,
            'read_at' => now()
        ]);
    }

    /**
     * Mark notifications of a specific type as read
     */
    public function markTypeAsRead(User $user, string $type): int
    {
        return $user->notifications()
            ->notDeletedByUser()
            ->unread()
            ->where('type', $type)
            ->update([
                'is_read' => true,
                'read_at' => now()
            ]);
    }
} 