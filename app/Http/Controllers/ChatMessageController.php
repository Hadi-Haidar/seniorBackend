<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Services\ChatService;
use App\Events\UserTyping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ChatMessageController extends Controller
{
    protected $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * Get messages for a room with pagination
     */
    public function index(ChatRoom $room, Request $request)
    {
        // Check if user is participant
        if (!$room->hasParticipant(Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $page = $request->get('page', 1);
        $messages = $this->chatService->getRoomMessages($room, 50, $page);
        
        return response()->json($messages);
    }

    /**
     * Send a text message
     */
    public function store(Request $request, ChatRoom $room)
    {
        // Check if user is participant
        if (!$room->hasParticipant(Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Add debug logging for the request
        \Log::info('Chat message request debug:', [
            'has_file' => $request->hasFile('file'),
            'file_name' => $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : null,
            'type' => $request->input('type'),
            'message' => $request->input('message'),
            'user_id' => Auth::id(),
            'room_id' => $room->id
        ]);

        $validated = $request->validate([
            'message' => 'nullable|string|max:1000',
            'file' => [
                'nullable',
                'file',
                'max:51200', // 50MB max for all files
                function ($attribute, $value, $fail) use ($request) {
                    if (!$value) return;
                    
                    $type = $request->input('type', 'file');
                    $mimeType = $value->getMimeType();
                    
                    // Get file info for debugging
                    $originalName = $value->getClientOriginalName();
                    $size = $value->getSize();
                    
                    \Log::info('File validation debug:', [
                        'type' => $type,
                        'mime_type' => $mimeType,
                        'original_name' => $originalName,
                        'size' => $size
                    ]);
                    
                    // Define allowed mime types for each type
                    $allowedTypes = [
                        'image' => [
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'image/webp',
                            'image/svg+xml'
                        ],
                        'document' => [
                            // PDF
                            'application/pdf',
                            // Word
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            // Excel
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            // PowerPoint
                            'application/vnd.ms-powerpoint',
                            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            // Text
                            'text/plain',
                            // ZIP
                            'application/zip',
                            'application/x-zip-compressed',
                            // RAR
                            'application/x-rar-compressed',
                            // PDF
                            'application/pdf',
                            // Rich Text
                            'application/rtf',
                            'text/rtf'
                        ],
                        'voice' => [
                            'audio/webm',
                            'video/webm',
                            'audio/ogg',
                            'audio/mp4',
                            'audio/mpeg',
                            'audio/wav',
                            'audio/x-wav',
                            'audio/mp3'
                        ],
                        'video' => [
                            'video/mp4',
                            'video/webm',
                            'video/ogg',
                            'video/quicktime',
                            'video/x-msvideo',
                            'video/x-ms-wmv'
                        ]
                    ];

                    // Check if file type is supported
                    if (!isset($allowedTypes[$type])) {
                        $fail("Unsupported file type: {$type}");
                        return;
                    }

                    // Check if mime type is allowed for the given type
                    if (!in_array($mimeType, $allowedTypes[$type])) {
                        $fail("Invalid file type. Allowed types for {$type} are: " . implode(', ', $allowedTypes[$type]));
                        return;
                    }

                    // Check file size limits
                    $maxSizes = [
                        'image' => 10 * 1024 * 1024, // 10MB
                        'document' => 50 * 1024 * 1024, // 50MB
                        'voice' => 10 * 1024 * 1024, // 10MB
                        'video' => 50 * 1024 * 1024 // 50MB
                    ];

                    if ($size > $maxSizes[$type]) {
                        $maxSizeMB = $maxSizes[$type] / (1024 * 1024);
                        $fail("{$type} files must be smaller than {$maxSizeMB}MB");
                    }
                }
            ],
            'type' => 'required|in:text,image,document,voice,video'
        ]);

        // Ensure we have either a message or a file
        if (empty($validated['message']) && !$request->hasFile('file')) {
            return response()->json([
                'error' => 'Either message or file is required'
            ], 422);
        }

        try {
            if ($request->hasFile('file')) {
                $message = $this->chatService->sendMessageWithFile([
                    'room_id' => $room->id,
                    'user_id' => Auth::id(),
                    'message' => $validated['message'] ?? '',
                    'type' => $validated['type']
                ], $request->file('file'));
            } else {
                $message = $this->chatService->sendMessage([
                    'room_id' => $room->id,
                    'user_id' => Auth::id(),
                    'message' => $validated['message'],
                    'type' => $validated['type']
                ]);
            }

            return response()->json([
                'message' => $message,
                'success' => 'Message sent successfully'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to send message',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Edit a message
     */
    public function update(Request $request, ChatMessage $message)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $editedMessage = $this->chatService->editMessage(
            $message, 
            $validated['message'], 
            Auth::user()
        );

        if (!$editedMessage) {
            return response()->json(['error' => 'Unauthorized or message not found'], 403);
        }

        return response()->json([
            'message' => $editedMessage,
            'success' => 'Message updated successfully'
        ]);
    }

    /**
     * Delete a message
     */
    public function destroy(ChatMessage $message)
    {
        $deleted = $this->chatService->deleteMessage($message, Auth::user());

        if (!$deleted) {
            return response()->json(['error' => 'Unauthorized or message not found'], 403);
        }

        return response()->json(['success' => 'Message deleted successfully']);
    }



    /**
     * Handle typing indicator
     */
    public function typing(Request $request, ChatRoom $room)
    {
        // Check if user is participant
        if (!$room->hasParticipant(Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'is_typing' => 'required|boolean'
        ]);

        $this->chatService->handleTyping(
            Auth::user(), 
            $room->id, 
            $validated['is_typing']
        );
        
        return response()->json(['success' => 'Typing status updated']);
    }



    /**
     * Upload file for chat
     */
    public function uploadFile(Request $request, ChatRoom $room)
    {
        // Check if user is participant
        if (!$room->hasParticipant(Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'type' => 'required|in:image,file,voice'
        ]);

        try {
            $fileData = $this->chatService->uploadFile(
                $request->file('file'), 
                $validated['type']
            );
            
            return response()->json([
                'file_data' => $fileData,
                'success' => 'File uploaded successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to upload file',
                'details' => $e->getMessage()
            ], 500);
        }
    }


} 