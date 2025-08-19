<?php

namespace App\Services;

use App\Models\ChatRoom;
use App\Models\ChatMessage;
use App\Models\User;
use App\Events\MessageSent;
use App\Events\MessageEdited;
use App\Events\MessageDeleted;
use App\Events\UserJoinedRoom;
use App\Events\UserTyping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\UploadedFile;

class ChatService
{
    protected $roomLimitService;

    public function __construct(RoomLimitService $roomLimitService)
    {
        $this->roomLimitService = $roomLimitService;
    }

    /**
     * Create a new chat room with limit checking
     */
    public function createRoom(array $data, array $participantIds = []): array
    {
        $user = Auth::user();
        
        // Check room creation limits
        $canCreate = $this->roomLimitService->canCreateRoom($user);
        
        if (!$canCreate['can_create']) {
            throw new \Exception(
                $canCreate['insufficient_coins'] 
                    ? "Insufficient coins to create additional room. You need {$canCreate['additional_cost']} coins but have {$canCreate['user_coins']} coins."
                    : "Cannot create room due to limits."
            );
        }

        return DB::transaction(function () use ($data, $participantIds, $user, $canCreate) {
            // Process room creation (deduct coins if needed)
            $usageResult = $this->roomLimitService->processRoomCreation($user);
            
            // Create the room
            $room = ChatRoom::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            if (!empty($participantIds)) {
                $room->participants()->attach($participantIds);
            }

            return [
                'room' => $room->load('participants'),
                'usage_info' => $usageResult,
                'cost_info' => [
                    'was_free' => !$usageResult['cost_deducted'],
                    'coins_spent' => $usageResult['coins_spent'],
                    'remaining_coins' => $usageResult['remaining_coins']
                ]
            ];
        });
    }

    /**
     * Get room usage summary for authenticated user
     */
    public function getRoomUsageSummary(): array
    {
        return $this->roomLimitService->getRoomUsageSummary(Auth::user());
    }

    /**
     * Add participants to a room
     */
    public function addParticipants(ChatRoom $room, array $userIds): ChatRoom
    {
        $existingParticipants = $room->participants()->pluck('user_id')->toArray();
        $newParticipants = array_diff($userIds, $existingParticipants);

        if (!empty($newParticipants)) {
            $room->participants()->attach($newParticipants);
            
            // Broadcast join events for each new participant
            foreach ($newParticipants as $userId) {
                $user = User::find($userId);
                if ($user) {
                    broadcast(new UserJoinedRoom($user, $room));
                }
            }
        }

        return $room->load('participants');
    }

    /**
     * Remove participant from room
     */
    public function removeParticipant(ChatRoom $room, int $userId): bool
    {
        return $room->participants()->detach($userId) > 0;
    }

    /**
     * Send a message to a room
     */
    public function sendMessage(array $data): ChatMessage
    {
        return DB::transaction(function () use ($data) {
            $message = ChatMessage::create([
                'room_id' => $data['room_id'],
                'user_id' => $data['user_id'],
                'message' => $data['message'],
                'type' => $data['type'] ?? 'text',
                'file_url' => $data['file_url'] ?? null,
                'status' => 'sent'
            ]);

            $message->load(['user', 'room']);
            
            // Broadcast the message
            broadcast(new MessageSent($message, $message->user));

            return $message;
        });
    }

    /**
     * Handle file upload for chat
     */
    public function uploadFile(UploadedFile $file, string $type = 'file'): array
    {
        // Determine storage path based on type
        $storageFolder = match($type) {
            'image' => 'chat/images',
            'voice' => 'chat/voice',
            'video' => 'chat/videos',
            'document' => 'chat/documents',
            default => 'chat/files'
        };
        
        // Generate a unique filename while preserving the original extension
        $extension = $file->getClientOriginalExtension();
        $filename = uniqid() . '_' . time() . ($extension ? '.' . $extension : '');
        
        // Store the file
        $path = $file->storeAs($storageFolder, $filename, 'public');
        $fileUrl = Storage::url($path);
        
        // Debug: Log the generated file URL
        \Log::info('File uploaded for chat:', [
            'type' => $type,
            'path' => $path,
            'file_url' => $fileUrl,
            'full_url' => url($fileUrl),
            'storage_path' => storage_path('app/public/' . $path),
            'mime_type' => $file->getMimeType(),
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize()
        ]);
        
        return [
            'file_url' => url($fileUrl),
            'original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'extension' => $extension
        ];
    }

    /**
     * Send message with file
     */
    public function sendMessageWithFile(array $data, UploadedFile $file): ChatMessage
    {
        $fileData = $this->uploadFile($file, $data['type'] ?? 'file');
        
        $data['file_url'] = $fileData['file_url'];
        $data['message'] = $data['message'] ?? $fileData['original_name'];

        return $this->sendMessage($data);
    }



    /**
     * Get room messages with pagination
     */
    public function getRoomMessages(ChatRoom $room, int $perPage = 50, int $page = 1)
    {
        return $room->messages()
            ->with(['user'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get user's chat rooms with latest message
     */
    public function getUserRooms(User $user)
    {
        return $user->chatRooms()
            ->with(['latestMessage.user', 'participants'])
            ->get();
    }

    /**
     * Search messages in room
     */
    public function searchMessages(ChatRoom $room, string $query, int $limit = 20)
    {
        return $room->messages()
            ->with(['user'])
            ->where('message', 'LIKE', "%{$query}%")
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Edit a message
     */
    public function editMessage(ChatMessage $message, string $newContent, User $user): ?ChatMessage
    {
        if ($message->user_id !== $user->id) {
            return null; // User can only edit their own messages
        }

        // Prevent editing image and other non-text messages
        if ($message->type !== 'text') {
            return null; // Only text messages can be edited
        }

        $message->editMessage($newContent);
        
        // Broadcast the edited message
        broadcast(new MessageEdited($message->fresh('user'), $user));

        return $message;
    }

    /**
     * Delete a message
     */
    public function deleteMessage(ChatMessage $message, User $user): bool
    {
        // Check if user can delete this message
        $canDelete = $this->canUserDeleteMessage($message, $user);
        
        if (!$canDelete) {
            return false;
        }

        // Store message details before deletion
        $messageId = $message->id;
        $roomId = $message->room_id;
        $fileUrl = $message->file_url;
        
        // Delete associated file if it exists
        if ($fileUrl && in_array($message->type, ['image', 'voice', 'video', 'document', 'file'])) {
            $this->deleteMessageFile($fileUrl);
        }
        
        // Delete the message
        $deleted = $message->delete();
        
        if ($deleted) {
            // Broadcast the deletion
            broadcast(new MessageDeleted($messageId, $roomId, $user));
        }

        return $deleted;
    }

    /**
     * Check if user can delete a message
     */
    private function canUserDeleteMessage(ChatMessage $message, User $user): bool
    {
        // Users can always delete their own messages
        if ($message->user_id === $user->id) {
            return true;
        }

        // Check user's role in the room
        $userRole = $this->getUserRoleInRoom($message->room_id, $user->id);
        
        // Only owners and moderators can delete other people's messages
        if (!in_array($userRole, ['owner', 'moderator'])) {
            return false;
        }

        // If user is owner, they can delete any message
        if ($userRole === 'owner') {
            return true;
        }

        // If user is moderator, check if the message author is also the room owner
        $messageAuthorRole = $this->getUserRoleInRoom($message->room_id, $message->user_id);
        
        // Moderators cannot delete owner's messages
        if ($userRole === 'moderator' && $messageAuthorRole === 'owner') {
            return false;
        }

        // Moderators can delete messages from other members/moderators
        return true;
    }

    /**
     * Get user's role in a room
     */
    private function getUserRoleInRoom(int $chatRoomId, int $userId): string
    {
        // Find the corresponding Room (not ChatRoom) by ID
        // ChatRoom and Room use the same ID
        $room = \App\Models\Room::find($chatRoomId);
        
        if (!$room) {
            return 'none';
        }

        // Check if user is the room owner
        if ($room->owner_id === $userId) {
            return 'owner';
        }

        // Check user's membership and role
        $membership = \App\Models\RoomMember::where('room_id', $chatRoomId)
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->first();

        if (!$membership) {
            return 'none';
        }

        return $membership->role ?? 'member';
    }

    /**
     * Delete file associated with a message
     */
    private function deleteMessageFile(string $fileUrl): void
    {
        try {
            // Extract the path from the full URL
            // URL format: http://localhost:8000/storage/chat/images/filename.ext
            $parsedUrl = parse_url($fileUrl);
            $path = $parsedUrl['path'] ?? '';
            
            // Remove '/storage/' prefix to get the actual storage path
            $storagePath = str_replace('/storage/', '', $path);
            
            // Check if file exists and delete it
            if (Storage::disk('public')->exists($storagePath)) {
                Storage::disk('public')->delete($storagePath);
                \Log::info('Chat file deleted successfully', [
                    'file_url' => $fileUrl,
                    'storage_path' => $storagePath
                ]);
            } else {
                \Log::warning('Chat file not found for deletion', [
                    'file_url' => $fileUrl,
                    'storage_path' => $storagePath
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to delete chat file', [
                'file_url' => $fileUrl,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle typing indicator
     */
    public function handleTyping(User $user, int $roomId, bool $isTyping = true): void
    {
        broadcast(new UserTyping($user, $roomId, $isTyping));
    }

    /**
     * Get room statistics
     */
    public function getRoomStats(ChatRoom $room): array
    {
        return [
            'total_messages' => $room->messages()->count(),
            'total_participants' => $room->participants()->count(),
            'messages_today' => $room->messages()
                ->whereDate('created_at', today())
                ->count(),
            'active_users_today' => $room->messages()
                ->whereDate('created_at', today())
                ->distinct('user_id')
                ->count('user_id')
        ];
    }

    /**
     * Create a direct message room between two users
     */
    public function createDirectMessageRoom(User $user1, User $user2): ChatRoom
    {
        // Check if a DM room already exists between these users
        $existingRoom = ChatRoom::whereHas('participants', function ($query) use ($user1) {
                $query->where('user_id', $user1->id);
            })
            ->whereHas('participants', function ($query) use ($user2) {
                $query->where('user_id', $user2->id);
            })
            ->whereDoesntHave('participants', function ($query) use ($user1, $user2) {
                $query->whereNotIn('user_id', [$user1->id, $user2->id]);
            })
            ->first();

        if ($existingRoom) {
            return $existingRoom;
        }

        // Create new DM room
        return $this->createRoom([
            'name' => "DM: {$user1->name} & {$user2->name}",
            'description' => 'Direct message room'
        ], [$user1->id, $user2->id]);
    }
} 