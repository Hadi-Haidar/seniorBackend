<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserOnlineStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;
    public $roomId;
    public $isOnline;
    public $onlineMembers;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user, int $roomId, bool $isOnline, array $onlineMembers = [])
    {
        $this->user = $user;
        $this->roomId = $roomId;
        $this->isOnline = $isOnline;
        $this->onlineMembers = $onlineMembers;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.room.' . $this->roomId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'user.online.status';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => $this->user->avatar,
                'email' => $this->user->email,
            ],
            'room_id' => $this->roomId,
            'is_online' => $this->isOnline,
            'online_members' => $this->onlineMembers,
            'timestamp' => now()->toISOString()
        ];
    }
} 