<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DirectMessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $messageId;
    public $conversationId;
    public $senderId;
    public $receiverId;
    public $roomId;

    /**
     * Create a new event instance.
     */
    public function __construct($messageId, $conversationId, $senderId, $receiverId, $roomId)
    {
        $this->messageId = $messageId;
        $this->conversationId = $conversationId;
        $this->senderId = $senderId;
        $this->receiverId = $receiverId;
        $this->roomId = $roomId;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->senderId),
            new PrivateChannel('user.' . $this->receiverId),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'conversation_id' => $this->conversationId,
            'sender_id' => $this->senderId,
            'receiver_id' => $this->receiverId,
            'room_id' => $this->roomId,
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'direct.message.deleted';
    }
} 