<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ChatRoom extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all messages for this room
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'room_id');
    }

    /**
     * Get the latest message for this room
     */
    public function latestMessage()
    {
        return $this->hasOne(ChatMessage::class, 'room_id')->latest();
    }

    /**
     * Get participants of this room
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_room_participants', 'room_id', 'user_id')
            ->withTimestamps();
    }



    /**
     * Check if user is participant of this room
     */
    public function hasParticipant($userId): bool
    {
        return $this->participants()->where('user_id', $userId)->exists();
    }

    /**
     * Add participant to room
     */
    public function addParticipant($userId)
    {
        if (!$this->hasParticipant($userId)) {
            $this->participants()->attach($userId);
        }
        return $this;
    }

    /**
     * Remove participant from room
     */
    public function removeParticipant($userId)
    {
        $this->participants()->detach($userId);
        return $this;
    }

    /**
     * Get room channel name for broadcasting
     */
    public function getChannelName(): string
    {
        return "chat.room.{$this->id}";
    }

    /**
     * Get online members for this room
     */
    public function onlineMembers()
    {
        return $this->hasMany(\App\Models\RoomOnlineMember::class, 'room_id');
    }

    /**
     * Get current online members with user details
     */
    public function getCurrentOnlineMembers()
    {
        return \App\Models\RoomOnlineMember::getOnlineMembers($this->id);
    }

    /**
     * Mark user as online in this room
     */
    public function markUserOnline(int $userId): void
    {
        \App\Models\RoomOnlineMember::markUserOnline($this->id, $userId);
    }

    /**
     * Mark user as offline in this room
     */
    public function markUserOffline(int $userId): void
    {
        \App\Models\RoomOnlineMember::markUserOffline($this->id, $userId);
    }
} 