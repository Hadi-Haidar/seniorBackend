<?php

namespace App\Events;

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $user;

    /**
     * Create a new event instance.
     */
    public function __construct(ChatMessage $message, User $user)
    {
        $this->message = $message->load(['user', 'room']);
        $this->user = $user;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.room.' . $this->message->room_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        try {
            return [
                'message' => [
                    'id' => $this->message->id,
                    'room_id' => $this->message->room_id,
                    'user_id' => $this->message->user_id,
                    'message' => $this->message->message,
                    'type' => $this->message->type,
                    'file_url' => $this->message->file_url,
                    'status' => $this->message->status,
                    'created_at' => $this->message->created_at->toISOString(),
                    'time_ago' => $this->message->created_at->diffForHumans(),
                    'formatted_time' => $this->message->created_at->format('H:i'),
                    'user' => [
                        'id' => $this->message->user->id ?? null,
                        'name' => $this->message->user->name ?? 'Unknown User',
                        'avatar' => $this->message->user->avatar ?? null,
                    ]
                ]
            ];
        } catch (\Exception $e) {
            \Log::error('Error broadcasting message: ' . $e->getMessage());
            return [
                'message' => [
                    'id' => $this->message->id,
                    'room_id' => $this->message->room_id,
                    'user_id' => $this->message->user_id,
                    'message' => $this->message->message,
                    'type' => $this->message->type ?? 'text',
                    'file_url' => $this->message->file_url,
                    'status' => $this->message->status ?? 'sent',
                    'created_at' => $this->message->created_at->toISOString(),
                    'time_ago' => 'now',
                    'formatted_time' => date('H:i'),
                    'user' => [
                        'id' => $this->message->user_id,
                        'name' => 'Unknown User',
                        'avatar' => null,
                    ]
                ]
            ];
        }
    }
} 