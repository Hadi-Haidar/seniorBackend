<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\ChatRoom;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Chat room channels - only participants can access
Broadcast::channel('chat.room.{roomId}', function ($user, $roomId) {
    $chatRoom = ChatRoom::find($roomId);
    
    if (!$chatRoom) {
        \Log::warning("Chat room {$roomId} not found");
        return false;
    }
    
    // Check if user is a participant of the chat room
    $hasAccess = $chatRoom->hasParticipant($user->id);
    
    return $hasAccess;
});

// Private chat room channels - for online members and real-time features
Broadcast::channel('private-chat.room.{roomId}', function ($user, $roomId) {
    $chatRoom = ChatRoom::find($roomId);
    
    if (!$chatRoom) {
        \Log::warning("Chat room {$roomId} not found for private channel auth");
        return false;
    }
    
    // Check if user is a participant of the chat room
    $hasAccess = $chatRoom->hasParticipant($user->id);
    
    if ($hasAccess) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar ?? null,
        ];
    }
    
    return false;
});

// User private channel for personal notifications
Broadcast::channel('user.{userId}', function ($user, $userId) {
    $hasAccess = (int) $user->id === (int) $userId;
    
    return $hasAccess ? [
        'id' => $user->id,
        'name' => $user->name,
        'avatar' => $user->avatar,
    ] : false;
});

// Allow access to room private channels for members, moderators, and owners
Broadcast::channel('room.{roomId}', function ($user, $roomId) {
    // Check if user is the owner
    $isOwner = Room::where('id', $roomId)
                   ->where('owner_id', $user->id)
                   ->exists();
    
    if ($isOwner) {
        return true;
    }
    
    // Check if user is a member or moderator with approved status
    return RoomMember::where('room_id', $roomId)
                    ->where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->exists();
});

// Broadcasting is currently disabled. Uncomment when implementing real-time features.
/*
// Allow access to room private channels for members, moderators, and owners
Broadcast::channel('room.{roomId}', function ($user, $roomId) {
    // TEMPORARY FOR TESTING: Allow any authenticated user to access any room
    // Remove this line in production!
    return true;
    
    // Check if user is the owner
    $isOwner = Room::where('id', $roomId)
                   ->where('owner_id', $user->id)
                   ->exists();
    
    if ($isOwner) {
        return true;
    }
    
    // Check if user is a member or moderator with approved status
    return RoomMember::where('room_id', $roomId)
                    ->where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->exists();
});
*/

// Public product stock update channels - no authentication required
Broadcast::channel('product.{productId}', function () {
    // Allow everyone to listen to individual product stock updates
    return true;
});

// Public store products channel - no authentication required  
Broadcast::channel('store.products', function () {
    // Allow everyone to listen to general store product updates
    return true;
}); 