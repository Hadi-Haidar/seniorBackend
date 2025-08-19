<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * Update user profile information
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        // Debug incoming request
        \Log::info('Profile update request', [
            'all' => $request->all(),
            'has_file' => $request->hasFile('avatar'),
            'file_errors' => $request->file('avatar') ? null : 'No file uploaded',
            'user_id' => Auth::id()
        ]);
        
        $user = Auth::user();
        if (!$user) {
            \Log::error('No authenticated user found');
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'bio' => 'sometimes|nullable|string|max:1000',
            'avatar' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
        ]);
        
        if ($request->has('name')) {
            $user->name = $request->name;
        }
        
        if ($request->has('bio')) {
            $user->bio = $request->bio;
        }
        
        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            try {
                $file = $request->file('avatar');
                
                // Create paths with correct slashes for Windows
                $uploadDir = str_replace('/', '\\', storage_path('app\\public\\avatars'));
                
                // Ensure directory exists
                if (!file_exists($uploadDir)) {
                    // Create with Windows-friendly path
                    if (!mkdir($uploadDir, 0755, true)) {
                        throw new \Exception("Failed to create directory: $uploadDir");
                    }
                }
                
                // Use simple PHP file upload without Laravel's store method
                $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9.]/', '_', $file->getClientOriginalName());
                $uploadPath = $uploadDir . '\\' . $filename;
                
                // Use simple PHP move_uploaded_file function
                if (move_uploaded_file($file->getRealPath(), $uploadPath)) {
                    $relativePath = 'avatars/' . $filename;
                    $user->avatar = $relativePath;
                    
                    // Also try direct DB update
                    DB::statement("UPDATE users SET avatar = ? WHERE id = ?", [$relativePath, $user->id]);
                    
                    \Log::info('Avatar uploaded using plain PHP', [
                        'path' => $relativePath,
                        'full_path' => $uploadPath,
                        'exists' => file_exists($uploadPath)
                    ]);
                }
            } catch (\Exception $e) {
                \Log::error('Avatar exception', [
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            }
        } else {
            \Log::error('Avatar missing or invalid', [
                'has_avatar_field' => $request->has('avatar'),
                'all_files' => $request->allFiles()
            ]);
        }
        
        // Right before user->save()
        \Log::info('Before saving user', ['avatar' => $user->avatar]);
        $user->save();
        
        // Get fresh user from database to verify it saved and ensure we return latest data
        $freshUser = User::find($user->id);
        \Log::info('After saving user', ['avatar_in_db' => $freshUser->avatar]);
        
        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $freshUser->id,
                'name' => $freshUser->name,
                'email' => $freshUser->email,
                'avatar' => $freshUser->avatar,
                'bio' => $freshUser->bio,
                'google_id' => $freshUser->google_id,
                'subscription_level' => $freshUser->subscription_level,
                'user_type' => $freshUser->user_type,  // Add user_type field
                'coins' => $freshUser->coins,
                'email_verified_at' => $freshUser->email_verified_at,
                'created_at' => $freshUser->created_at,
                'updated_at' => $freshUser->updated_at
            ]
        ]);
    }
    
    /**
     * Change user password
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        $user = Auth::user();
        
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => [
                'required', 
                'string', 
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
            ]
        ]);
        
        // Check if current password matches
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect'
            ], 422);
        }
        
        // Update password
        $user->password = Hash::make($request->new_password);
        $user->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }
    
    /**
     * Delete user account (Hard delete only)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteAccount(Request $request)
    {
        $user = Auth::user();
        
        // Different verification methods based on authentication type
        if ($user->google_id && !$user->password) {
            // Google user - verify with email
            $request->validate(['email' => 'required|string|email']);
            
            if ($request->email !== $user->email) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email does not match your account'
                ], 422);
            }
        } else {
            // Regular user - verify with password
            $request->validate(['password' => 'required|string']);
        
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password is incorrect'
            ], 422);
            }
        }
        
            // Clean up related data first
        if (method_exists($user, 'coinTransactions')) {
            $user->coinTransactions()->delete(); // Delete related transactions
        }
            
            // Delete tokens to invalidate sessions
            $user->tokens()->delete();
            
            // Hard delete the user
            $user->forceDelete();
            
            return response()->json([
                'success' => true,
                'message' => 'Account permanently deleted'
            ]);
    }

    /**
     * Get current user profile data
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProfile()
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        
        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'bio' => $user->bio,
                'google_id' => $user->google_id,
                'subscription_level' => $user->subscription_level,
                'user_type' => $user->user_type,  // Add user_type field
                'coins' => $user->coins,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at
            ]
        ]);
    }

    /**
     * Serve avatar images with proper CORS headers
     * This fixes CORS issues when loading avatars from storage
     * 
     * @param string $filename
     * @return \Illuminate\Http\Response
     */
    public function getAvatar($filename)
    {
        // Security: Only allow access to avatar files
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
            abort(404);
        }
        
        $path = storage_path('app/public/avatars/' . $filename);
        
        if (!file_exists($path)) {
            abort(404);
        }
        
        $mimeType = mime_content_type($path);
        
        return response()
            ->file($path, [
                'Content-Type' => $mimeType,
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
                'Cache-Control' => 'public, max-age=86400', // Cache for 24 hours
            ]);
    }

    /**
     * Generate default avatars for all users who don't have them
     * This can be called via API endpoint or console command
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateDefaultAvatars()
    {
        try {
            // Find users without avatars AND without Google ID (email users only)
            $users = User::where(function($query) {
                            $query->whereNull('avatar')
                                  ->orWhere('avatar', '');
                        })
                        ->whereNull('google_id') // Only email users, not Google users
                        ->get();

            $count = 0;
            foreach ($users as $user) {
                $defaultAvatar = $this->generateDefaultAvatarForUser($user);
                $user->update(['avatar' => $defaultAvatar]);
                $count++;
                
                \Log::info("Generated default avatar for email user {$user->id}: {$defaultAvatar}");
            }
            
            \Log::info("Generated default avatars for {$count} email users");
            
            return response()->json([
                'success' => true,
                'message' => "Generated default avatars for {$count} users",
                'count' => $count
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to generate default avatars: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate default avatars: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate a default avatar for a specific user
     * 
     * @param User $user
     * @return string
     */
    public function generateDefaultAvatarForUser(User $user): string
    {
        // Use name or email to generate a consistent seed
        $seed = $user->name ?: $user->email;
        
        // Simple hash function to generate a number from string
        $hash = 0;
        for ($i = 0; $i < strlen($seed); $i++) {
            $char = ord($seed[$i]);
            $hash = (($hash << 5) - $hash) + $char;
            $hash = $hash & $hash; // Convert to 32bit integer
        }
        
        // Array of nice colors for avatars (matching frontend)
        $colors = [
            'blue', 'green', 'purple', 'pink', 'indigo', 
            'red', 'yellow', 'teal', 'orange', 'cyan'
        ];
        
        // Use hash to select a color
        $colorIndex = abs($hash) % count($colors);
        $color = $colors[$colorIndex];
        
        // Get user initials
        $initials = $this->getUserInitials($user);
        
        // Create a default avatar identifier that the frontend can recognize
        return "default:{$color}:{$initials}";
    }

    /**
     * Get user initials for avatar generation
     * 
     * @param User $user
     * @return string
     */
    private function getUserInitials(User $user): string
    {
        if (!$user->name || trim($user->name) === '') {
            return '?';
        }
        
        // Split name by spaces and filter out empty strings
        $nameParts = array_filter(explode(' ', trim($user->name)));
        
        if (empty($nameParts)) {
            return '?';
        }
        
        // Always return just the first letter of the first name
        return strtoupper(substr($nameParts[0], 0, 1));
    }

    /**
     * Generate default avatar for current authenticated user
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateMyDefaultAvatar()
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            
            // Don't generate for Google users
            if ($user->google_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Default avatars are not generated for Google users'
                ], 422);
            }
            
            $defaultAvatar = $this->generateDefaultAvatarForUser($user);
            $user->update(['avatar' => $defaultAvatar]);
            
            return response()->json([
                'success' => true,
                'message' => 'Default avatar generated successfully',
                'avatar' => $defaultAvatar
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate default avatar: ' . $e->getMessage()
            ], 500);
        }
    }
}
