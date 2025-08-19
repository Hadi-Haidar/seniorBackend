<?php

namespace App\Http\Controllers;

use App\Models\DirectMessage;
use App\Models\User;
use App\Models\Room;
use App\Events\DirectMessageSent;
use App\Events\DirectMessageTyping;
use App\Events\DirectMessageRead;
use App\Events\DirectMessageEdited;
use App\Events\DirectMessageDeleted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class DirectMessageController extends Controller
{
    /**
     * Get conversation between current user and another user in a specific room
     */
    public function getConversation(Request $request, Room $room, User $user)
    {
        $currentUserId = Auth::id();
        $otherUserId = $user->id;

        // Check if current user is a member of the room
        if (!$room->hasParticipant($currentUserId)) {
            return response()->json(['error' => 'You are not a member of this room'], 403);
        }

        // Don't allow user to message themselves
        if ($currentUserId === $otherUserId) {
            return response()->json(['error' => 'Cannot message yourself'], 400);
        }

        $limit = $request->get('limit', 50);
        $offset = $request->get('offset', 0);

        $messages = DirectMessage::getConversation($room->id, $currentUserId, $otherUserId, $limit, $offset);

        // Mark messages as read
        DirectMessage::where('room_id', $room->id)
            ->where('sender_id', $otherUserId)
            ->where('receiver_id', $currentUserId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'messages' => $messages,
            'conversation_id' => DirectMessage::generateConversationId($room->id, $currentUserId, $otherUserId),
            'other_user' => $user,
            'room' => $room
        ]);
    }

    /**
     * Send a direct message in a specific room
     */
    public function sendMessage(Request $request, Room $room, User $user)
    {
        $validated = $request->validate([
            'message' => 'nullable|string|max:2000',
            'type' => 'sometimes|in:text,image',
            'file' => 'sometimes|file|max:10240' // 10MB max
        ]);

        // Validate that either message or file is provided
        if (empty($validated['message']) && !$request->hasFile('file')) {
            return response()->json(['error' => 'Either message text or file is required'], 400);
        }

        $currentUserId = Auth::id();
        $receiverId = $user->id;

        // Check if current user is a member of the room
        if (!$room->hasParticipant($currentUserId)) {
            return response()->json(['error' => 'You are not a member of this room'], 403);
        }

        // Check if target user is also a member of the room
        if (!$room->hasParticipant($receiverId)) {
            return response()->json(['error' => 'Target user is not a member of this room'], 400);
        }

        // Don't allow user to message themselves
        if ($currentUserId === $receiverId) {
            return response()->json(['error' => 'Cannot message yourself'], 400);
        }

        $messageData = [
            'sender_id' => $currentUserId,
            'receiver_id' => $receiverId,
            'room_id' => $room->id,
            'message' => $validated['message'],
            'type' => $validated['type'] ?? 'text'
        ];

        // Handle file upload if present
        if ($request->hasFile('file')) {
            $fileData = $this->handleFileUpload($request->file('file'), $validated['type'] ?? 'image');
            $messageData = array_merge($messageData, $fileData);
        }

        $message = DirectMessage::create($messageData);
        $message->load(['sender', 'receiver', 'room']);

        // Broadcast the message to both users
        broadcast(new DirectMessageSent($message));

        return response()->json([
            'message' => $message,
            'success' => 'Message sent successfully'
        ], 201);
    }

    /**
     * Get all conversations for the current user in a specific room
     */
    public function getRoomConversations(Room $room)
    {
        $currentUserId = Auth::id();

        // Check if current user is a member of the room
        if (!$room->hasParticipant($currentUserId)) {
            return response()->json(['error' => 'You are not a member of this room'], 403);
        }

        $conversations = DirectMessage::getRoomConversations($room->id, $currentUserId);

        return response()->json([
            'conversations' => $conversations,
            'room' => $room
        ]);
    }

    /**
     * Edit a message in a room context
     */
    public function editMessage(Request $request, Room $room, DirectMessage $message)
    {
        // Check if current user is a member of the room
        if (!$room->hasParticipant(Auth::id())) {
            return response()->json(['error' => 'You are not a member of this room'], 403);
        }

        // Check if message belongs to this room
        if ($message->room_id !== $room->id) {
            return response()->json(['error' => 'Message not found in this room'], 404);
        }

        // Check if user owns the message
        if ($message->sender_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Prevent editing image messages
        if ($message->type === 'image') {
            return response()->json(['error' => 'Image messages cannot be edited'], 422);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:2000'
        ]);

        $message->editMessage($validated['message']);
        $message->load(['sender', 'receiver', 'room']);

        // Broadcast the edited message
        broadcast(new DirectMessageEdited($message));

        return response()->json([
            'message' => $message,
            'success' => 'Message updated successfully'
        ]);
    }

    /**
     * Delete a message in a room context
     */
    public function deleteMessage(Room $room, DirectMessage $message)
    {
        // Check if current user is a member of the room
        if (!$room->hasParticipant(Auth::id())) {
            return response()->json(['error' => 'You are not a member of this room'], 403);
        }

        // Check if message belongs to this room
        if ($message->room_id !== $room->id) {
            return response()->json(['error' => 'Message not found in this room'], 404);
        }

        // Check if user owns the message
        if ($message->sender_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Store message details before deletion
        $messageId = $message->id;
        $conversationId = DirectMessage::generateConversationId(
            $message->room_id,
            $message->sender_id,
            $message->receiver_id
        );
        $senderId = $message->sender_id;
        $receiverId = $message->receiver_id;
        $roomId = $message->room_id;

        $deleted = $message->deleteMessage();

        if ($deleted) {
            // Broadcast the deletion
            broadcast(new DirectMessageDeleted($messageId, $conversationId, $senderId, $receiverId, $roomId));
        }

        return response()->json(['success' => 'Message deleted successfully']);
    }

    /**
     * Send typing indicator in a room context
     */
    public function sendTyping(Request $request, Room $room, User $user)
    {
        $validated = $request->validate([
            'is_typing' => 'required|boolean'
        ]);

        $currentUserId = Auth::id();
        $receiverId = $user->id;

        // Check if current user is a member of the room
        if (!$room->hasParticipant($currentUserId)) {
            return response()->json(['error' => 'You are not a member of this room'], 403);
        }

        // Check if target user is also a member of the room
        if (!$room->hasParticipant($receiverId)) {
            return response()->json(['error' => 'Target user is not a member of this room'], 400);
        }

        // Don't allow user to type to themselves
        if ($currentUserId === $receiverId) {
            return response()->json(['error' => 'Cannot type to yourself'], 400);
        }

        $conversationId = DirectMessage::generateConversationId($room->id, $currentUserId, $receiverId);

        // Broadcast typing indicator
        broadcast(new DirectMessageTyping(
            Auth::user(),
            $user,
            $conversationId,
            $validated['is_typing']
        ));

        return response()->json(['success' => 'Typing indicator sent']);
    }

    /**
     * Mark direct messages from a user as read in a room context
     */
    public function markAsRead(Request $request, Room $room, User $user)
    {
        $currentUserId = Auth::id();
        $senderId = $user->id;

        // Basic validation
        if (!$room->hasParticipant($currentUserId) || !$room->hasParticipant($senderId)) {
            return response()->json(['error' => 'User not in room'], 403);
        }

        DirectMessage::where('room_id', $room->id)
            ->where('sender_id', $senderId)
            ->where('receiver_id', $currentUserId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
            
        // Broadcast that messages have been read for real-time unread count updates
        broadcast(new DirectMessageRead($room->id, $currentUserId, $senderId));

        return response()->json(['success' => 'Messages marked as read']);
    }

    /**
     * Handle file upload for direct messages
     */
    private function handleFileUpload(UploadedFile $file, string $type): array
    {
        $allowedTypes = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        ];

        $extension = strtolower($file->getClientOriginalExtension());
        
        // Validate file type for images
        if ($type === 'image' && !in_array($extension, $allowedTypes['image'])) {
            throw new \InvalidArgumentException("Invalid image file type. Allowed: " . implode(', ', $allowedTypes['image']));
        }

        // Generate unique filename with date structure
        $filename = time() . '_' . uniqid() . '.' . $extension;
        $dateSubPath = date('Y/m/d');
        $storagePath = "direct-messages/{$type}s/{$dateSubPath}";
        
        // Store file using Laravel's storage system - this will handle directory creation automatically
        $path = $file->store($storagePath, 'public');
        
        // Generate the URL using Storage::url (this will be /storage/path/to/file)
        $fileUrl = Storage::url($path);

        return [
            'file_url' => url($fileUrl),
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType()
        ];
    }
} 