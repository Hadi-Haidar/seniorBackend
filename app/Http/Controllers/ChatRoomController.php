<?php

namespace App\Http\Controllers;

use App\Models\ChatRoom;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ChatRoomController extends Controller
{
    protected $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * Get user's chat rooms
     */
    public function index()
    {
        $user = Auth::user();
        $rooms = $this->chatService->getUserRooms($user);
        
        return response()->json([
            'rooms' => $rooms
        ]);
    }

    /**
     * Create a new chat room
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'participants' => 'required|array|min:1',
            'participants.*' => 'exists:users,id'
        ]);

        try {
            // Add the creator to participants
            $participants = array_unique(array_merge($validated['participants'], [Auth::id()]));
            
            $result = $this->chatService->createRoom([
                'name' => $validated['name'],
                'description' => $validated['description']
            ], $participants);

            $message = $result['cost_info']['was_free'] 
                ? 'Chat room created successfully (free)'
                : "Chat room created successfully! {$result['cost_info']['coins_spent']} coins deducted. Remaining balance: {$result['cost_info']['remaining_coins']} coins.";

            return response()->json([
                'room' => $result['room'],
                'message' => $message,
                'cost_info' => $result['cost_info'],
                'usage_info' => $result['usage_info']
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'room_usage' => $this->chatService->getRoomUsageSummary()
            ], 400);
        }
    }

    /**
     * Get room details with messages
     */
    public function show(ChatRoom $room, Request $request)
    {
        // Check if user is participant
        if (!$room->hasParticipant(Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $page = $request->get('page', 1);
        $messages = $this->chatService->getRoomMessages($room, 50, $page);

        // Get online members
        $onlineMembers = $room->getCurrentOnlineMembers();

        return response()->json([
            'room' => $room->load('participants'),
            'messages' => $messages,
            'stats' => $this->chatService->getRoomStats($room),
            'online_members' => $onlineMembers->map(function ($onlineMember) {
                return [
                    'id' => $onlineMember->user->id,
                    'name' => $onlineMember->user->name,
                    'avatar' => $onlineMember->user->avatar,
                    'email' => $onlineMember->user->email,
                    'last_seen' => $onlineMember->last_seen->toISOString(),
                ];
            })->toArray()
        ]);
    }

    /**
     * Update room details
     */
    public function update(Request $request, ChatRoom $room)
    {
        // Check if user is participant
        if (!$room->hasParticipant(Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        $room->update($validated);
        
        return response()->json([
            'room' => $room,
            'message' => 'Room updated successfully'
        ]);
    }

    /**
     * Delete a room
     */
    public function destroy(ChatRoom $room)
    {
        // Only allow deletion if user is participant (you might want to add admin check)
        if (!$room->hasParticipant(Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $room->delete();
        
        return response()->json(['message' => 'Room deleted successfully']);
    }

    /**
     * Get room usage information for the authenticated user
     */
    public function roomUsage()
    {
        return response()->json([
            'usage' => $this->chatService->getRoomUsageSummary()
        ]);
    }
} 