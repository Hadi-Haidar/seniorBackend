<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DirectMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'room_id',
        'message',
        'type',
        'file_url',
        'file_name',
        'file_size',
        'mime_type',
        'read_at',
        'edited_at',
        'is_deleted'
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'edited_at' => 'datetime',
        'is_deleted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['conversation_id', 'is_edited', 'is_read'];

    /**
     * Get the sender user
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Get the receiver user
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * Get the room where this direct message belongs
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the other participant (not the current user)
     */
    public function getOtherParticipant(int $currentUserId): User
    {
        return $this->sender_id === $currentUserId ? $this->receiver : $this->sender;
    }

    /**
     * Check if message is read
     */
    public function isRead(): bool
    {
        return !is_null($this->read_at);
    }

    /**
     * Mark message as read
     */
    public function markAsRead(): void
    {
        if (is_null($this->read_at)) {
            $this->update(['read_at' => now()]);
        }
    }

    /**
     * Check if message is edited
     */
    public function isEdited(): bool
    {
        return !is_null($this->edited_at);
    }

    /**
     * Get is_edited attribute for frontend
     */
    public function getIsEditedAttribute(): bool
    {
        return $this->isEdited();
    }

    /**
     * Get is_read attribute for frontend
     */
    public function getIsReadAttribute(): bool
    {
        return $this->isRead();
    }

    /**
     * Edit the message
     */
    public function editMessage(string $newMessage): void
    {
        $this->update([
            'message' => $newMessage,
            'edited_at' => now()
        ]);
    }

    /**
     * Delete the message (hard delete like live chat)
     */
    public function deleteMessage(): bool
    {
        // Delete any associated files first if needed
        if ($this->file_url) {
            $this->deleteAssociatedFile();
        }
        
        return $this->delete();
    }

    /**
     * Delete associated file
     */
    private function deleteAssociatedFile(): void
    {
        try {
            // Extract the path from the full URL
            $parsedUrl = parse_url($this->file_url);
            $path = $parsedUrl['path'] ?? '';
            
            // Remove '/storage/' prefix to get the actual storage path
            $storagePath = str_replace('/storage/', '', $path);
            
            // Check if file exists and delete it
                         if (Storage::disk('public')->exists($storagePath)) {
                Storage::disk('public')->delete($storagePath);
                \Log::info('Direct message file deleted successfully', [
                    'file_url' => $this->file_url,
                    'storage_path' => $storagePath
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to delete direct message file', [
                'file_url' => $this->file_url,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate a unique conversation ID for two users in a specific room
     */
    public function getConversationIdAttribute(): string
    {
        $userIds = [$this->sender_id, $this->receiver_id];
        sort($userIds);
        return 'dm_room_' . $this->room_id . '_' . implode('_', $userIds);
    }

    /**
     * Generate conversation ID for two specific users in a room (static method)
     */
    public static function generateConversationId(int $roomId, int $userId1, int $userId2): string
    {
        $userIds = [$userId1, $userId2];
        sort($userIds);
        return 'dm_room_' . $roomId . '_' . implode('_', $userIds);
    }

    /**
     * Get messages between two users in a specific room
     */
    public static function getConversation(int $roomId, int $userId1, int $userId2, int $limit = 50, int $offset = 0)
    {
        return self::where('room_id', $roomId)
            ->where(function ($query) use ($userId1, $userId2) {
                $query->where('sender_id', $userId1)->where('receiver_id', $userId2);
            })
            ->orWhere(function ($query) use ($userId1, $userId2, $roomId) {
                $query->where('room_id', $roomId)
                      ->where('sender_id', $userId2)
                      ->where('receiver_id', $userId1);
            })
            ->with(['sender', 'receiver', 'room'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * Get unread count for a user in a specific room (optionally from a specific sender)
     */
    public static function getUnreadCount(int $receiverId, int $roomId = null, int $senderId = null): int
    {
        $query = self::where('receiver_id', $receiverId)
            ->whereNull('read_at');

        if ($roomId) {
            $query->where('room_id', $roomId);
        }

        if ($senderId) {
            $query->where('sender_id', $senderId);
        }

        return $query->count();
    }

    /**
     * Get last message between two users in a specific room
     */
    public static function getLastMessage(int $roomId, int $userId1, int $userId2): ?self
    {
        return self::where('room_id', $roomId)
            ->where(function ($query) use ($userId1, $userId2) {
                $query->where('sender_id', $userId1)->where('receiver_id', $userId2);
            })
            ->orWhere(function ($query) use ($userId1, $userId2, $roomId) {
                $query->where('room_id', $roomId)
                      ->where('sender_id', $userId2)
                      ->where('receiver_id', $userId1);
            })
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Get all conversations for a user in a specific room
     */
    public static function getRoomConversations(int $roomId, int $userId): array
    {
        // Get all users this user has direct message conversations with in this room
        $conversations = self::where('room_id', $roomId)
            ->where(function ($query) use ($userId) {
                $query->where('sender_id', $userId)
                      ->orWhere('receiver_id', $userId);
            })
            ->with(['sender', 'receiver'])
            ->get()
            ->groupBy(function ($message) use ($userId) {
                $otherUserId = $message->sender_id === $userId ? $message->receiver_id : $message->sender_id;
                return self::generateConversationId($message->room_id, $userId, $otherUserId);
            })
            ->map(function ($messages) use ($userId) {
                $lastMessage = $messages->sortByDesc('created_at')->first();
                $otherUser = $lastMessage->sender_id === $userId ? $lastMessage->receiver : $lastMessage->sender;
                $unreadCount = self::getUnreadCount($userId, $lastMessage->room_id, $otherUser->id);

                return [
                    'conversation_id' => $lastMessage->conversation_id,
                    'other_user' => $otherUser,
                    'last_message' => $lastMessage,
                    'unread_count' => $unreadCount,
                    'room_id' => $lastMessage->room_id,
                    'updated_at' => $lastMessage->created_at
                ];
            })
            ->sortByDesc('updated_at')
            ->values()
            ->toArray();

        return $conversations;
    }
} 