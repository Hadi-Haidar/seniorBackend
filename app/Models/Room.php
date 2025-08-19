<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'type',
        'password',
        'is_commercial',
        'image',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_commercial' => 'boolean',
    ];

    /**
     * Get the owner of the room
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get all members of the room
     */
    public function members()
    {
        return $this->hasMany(RoomMember::class);
    }

    /**
     * Get all users in this room through room_members
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'room_members')
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    /**
     * Get the moderators of the room
     */
    public function moderators()
    {
        return $this->belongsToMany(User::class, 'room_members')
            ->wherePivot('role', 'moderator')
            ->withTimestamps();
    }

    /**
     * Check if a room is secure (requires password)
     */
    public function isSecure()
    {
        return $this->type === 'secure';
    }

    /**
     * Check if a room is private
     */
    public function isPrivate()
    {
        return $this->type === 'private';
    }

    /**
     * Get all posts in the room
     */
    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Get all products in the room
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Check if a user is a participant in this room
     */
    public function hasParticipant($userId): bool
    {
        return $this->users()->where('user_id', $userId)->wherePivot('status', 'approved')->exists();
    }

    /**
     * Get the corresponding chat room for this room
     * We'll use the same ID to match Room and ChatRoom
     */
    public function chatRoom()
    {
        // Try to find ChatRoom with same ID, or create one if it doesn't exist
        $chatRoom = \App\Models\ChatRoom::find($this->id);
        
        if (!$chatRoom) {
            $chatRoom = \App\Models\ChatRoom::create([
                'id' => $this->id,
                'name' => $this->name . ' - Chat',
                'description' => 'Live chat for ' . $this->name
            ]);
            
            // Add room owner as participant
            if ($this->owner) {
                $chatRoom->addParticipant($this->owner->id);
            }
        }
        
        return $chatRoom;
    }
}
