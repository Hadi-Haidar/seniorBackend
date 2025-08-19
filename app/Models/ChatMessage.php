<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'user_id',
        'message',
        'type', // text, image, file, voice
        'file_url',
        'status', // sent, delivered, read, edited
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['time_ago', 'is_edited'];

    /**
     * Get the room this message belongs to
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'room_id');
    }

    /**
     * Get the user who sent this message
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }



    /**
     * Get time ago attribute
     */
    public function getTimeAgoAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Get formatted time
     */
    public function getFormattedTimeAttribute(): string
    {
        return $this->created_at->format('H:i');
    }

    /**
     * Check if message is edited
     */
    public function isEdited(): bool
    {
        return $this->status === 'edited';
    }

    /**
     * Get is_edited attribute for frontend
     */
    public function getIsEditedAttribute(): bool
    {
        return $this->isEdited();
    }

    /**
     * Edit message content
     */
    public function editMessage(string $newMessage): self
    {
        $this->update([
            'message' => $newMessage,
            'status' => 'edited'
        ]);
        return $this;
    }


} 