<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DirectMessageRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $roomId;
    public $receiverId;
    public $senderId;
    public $conversationId;

    /**
     * Create a new event instance.
     */
    public function __construct(int $roomId, int $receiverId, int $senderId)
    {
        $this->roomId = $roomId;
        $this->receiverId = $receiverId;
        $this->senderId = $senderId;
        
        // Generate conversation ID for this read event
        $userIds = [$receiverId, $senderId];
        sort($userIds);
        $this->conversationId = 'dm_room_' . $roomId . '_' . implode('_', $userIds);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        // Broadcast to both users' private channels
        return [
            new PrivateChannel('user.' . $this->receiverId),
            new PrivateChannel('user.' . $this->senderId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'direct.message.read';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'receiver_id' => $this->receiverId,
            'sender_id' => $this->senderId,
            'conversation_id' => $this->conversationId,
            'timestamp' => now()->toISOString()
        ];
    }
} 