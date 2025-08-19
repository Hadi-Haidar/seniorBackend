<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirectToGoogle()
    {
        try {
            // Check configuration
            if (!config('services.google.client_id')) {
                return response()->json(['error' => 'Google Client ID not configured'], 500);
            }
            if (!config('services.google.client_secret')) {
                return response()->json(['error' => 'Google Client Secret not configured'], 500);
            }
            if (!config('services.google.redirect')) {
                return response()->json(['error' => 'Google Redirect URI not configured'], 500);
            }

            // Use stateless() to avoid session issues
            return Socialite::driver('google')
                ->stateless()
                ->redirect();
        } catch (Exception $e) {
            Log::error('Google redirect error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to redirect to Google: ' . $e->getMessage()
            ], 500);
        }
    }

    public function handleGoogleCallback()
    {
        try {
            // Use stateless() to avoid session/state validation issues
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();
            
            Log::info('Google OAuth successful', [
                'google_id' => $googleUser->id,
                'email' => $googleUser->email,
                'name' => $googleUser->name,
                'avatar' => $googleUser->avatar
            ]);
            
            // Find or create user
            $user = User::where('google_id', $googleUser->id)->first();

            if (!$user) {
                // Check if email already exists with different auth method
                $existingUser = User::where('email', $googleUser->email)->first();
                
                if ($existingUser) {
                    // Update existing user with Google info
                    $this->updateExistingUserWithGoogle($existingUser, $googleUser);
                    $user = $existingUser;
                } else {
                    // Create new user
                    $user = $this->createNewGoogleUser($googleUser);
                }
            } else {
                // User already exists with Google ID - update their data
                $this->updateExistingGoogleUser($user, $googleUser);
            }

            // Generate Sanctum token
            $token = $user->createToken('google-auth')->plainTextToken;

            Log::info('User authenticated successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'final_avatar' => $user->avatar,
                'avatar_type' => $this->getAvatarType($user->avatar)
            ]);

            // Redirect to frontend with success parameters
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $userData = urlencode(json_encode([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'google_id' => $user->google_id,
                'subscription_level' => $user->subscription_level,
                'user_type' => $user->user_type,  // Add user_type field
                'coins' => $user->coins
            ]));
            
            return redirect()->to("{$frontendUrl}/auth/google/callback?success=true&token={$token}&user={$userData}");

        } catch (Exception $e) {
            Log::error('Google OAuth callback error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_data' => request()->all()
            ]);
            
            // Redirect to frontend with error
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $errorMessage = urlencode('Authentication failed: ' . $e->getMessage());
            
            return redirect()->to("{$frontendUrl}/auth/google/callback?error=true&message={$errorMessage}");
        }
    }

    /**
     * Update existing user (originally email-based) with Google information
     */
    private function updateExistingUserWithGoogle($existingUser, $googleUser)
    {
        $updateData = [
            'google_id' => $googleUser->id,
            'email_verified_at' => now(), // Google emails are pre-verified
        ];
        
        // Only update avatar if user doesn't have a manually uploaded avatar or generated avatar
        // Check if current avatar is not a local file (manually uploaded) or default generated
        $currentAvatarType = $this->getAvatarType($existingUser->avatar);
        $shouldUpdateAvatar = $currentAvatarType !== 'local' && $currentAvatarType !== 'default';
        
        if ($googleUser->avatar && $shouldUpdateAvatar) {
            // Optimize Google avatar URL for better loading
            $avatarUrl = $this->optimizeGoogleAvatarUrl($googleUser->avatar);
            $updateData['avatar'] = $avatarUrl;
            
            Log::info('Updating avatar with Google avatar', [
                'user_id' => $existingUser->id,
                'current_avatar_type' => $currentAvatarType,
                'reason' => 'No manually uploaded or generated avatar found'
            ]);
        } else {
            Log::info('Preserving existing avatar', [
                'user_id' => $existingUser->id,
                'current_avatar_type' => $currentAvatarType,
                'current_avatar' => $existingUser->avatar,
                'reason' => $currentAvatarType === 'local' ? 'User has manually uploaded avatar' : 
                          ($currentAvatarType === 'default' ? 'User has generated avatar' : 'No Google avatar available')
            ]);
        }
        
        $existingUser->update($updateData);
        
        Log::info('Updated existing user with Google info', [
            'user_id' => $existingUser->id,
            'avatar_updated' => isset($updateData['avatar']),
            'new_avatar' => $updateData['avatar'] ?? 'preserved',
            'avatar_type' => $this->getAvatarType($existingUser->fresh()->avatar)
        ]);
    }

    /**
     * Create a new user from Google authentication
     */
    private function createNewGoogleUser($googleUser)
    {
        // Optimize Google avatar URL for better loading
        $avatarUrl = $googleUser->avatar ? $this->optimizeGoogleAvatarUrl($googleUser->avatar) : null;
        
        $user = User::create([
            'name' => $googleUser->name,
            'email' => $googleUser->email,
            'google_id' => $googleUser->id,
            'avatar' => $avatarUrl,
            'password' => null,
            'email_verified_at' => now(), // Google emails are pre-verified
        ]);
        
        Log::info('Created new Google user', [
            'user_id' => $user->id,
            'stored_avatar' => $user->avatar,
            'avatar_type' => $this->getAvatarType($user->avatar)
        ]);

        return $user;
    }

    /**
     * Update existing Google user with latest data
     */
    private function updateExistingGoogleUser($user, $googleUser)
    {
        $updateData = [
            'name' => $googleUser->name, // Always update name to keep it current
        ];
        
        // Only update avatar if user doesn't have a manually uploaded avatar or generated avatar
        // Check if current avatar is not a local file (manually uploaded) or default generated
        $currentAvatarType = $this->getAvatarType($user->avatar);
        $shouldUpdateAvatar = $currentAvatarType !== 'local' && $currentAvatarType !== 'default';
        
        if ($googleUser->avatar && $shouldUpdateAvatar) {
            $avatarUrl = $this->optimizeGoogleAvatarUrl($googleUser->avatar);
            $updateData['avatar'] = $avatarUrl;
            
            Log::info('Updating avatar with Google avatar', [
                'user_id' => $user->id,
                'current_avatar_type' => $currentAvatarType,
                'reason' => 'No manually uploaded or generated avatar found'
            ]);
        } else {
            Log::info('Preserving existing avatar', [
                'user_id' => $user->id,
                'current_avatar_type' => $currentAvatarType,
                'current_avatar' => $user->avatar,
                'reason' => $currentAvatarType === 'local' ? 'User has manually uploaded avatar' : 
                          ($currentAvatarType === 'default' ? 'User has generated avatar' : 'No Google avatar available')
            ]);
        }
        
        $user->update($updateData);
        
        Log::info('Updated existing Google user', [
            'user_id' => $user->id,
            'avatar_updated' => isset($updateData['avatar']),
            'new_avatar' => $updateData['avatar'] ?? 'preserved',
            'avatar_type' => $this->getAvatarType($user->fresh()->avatar)
        ]);
    }

    /**
     * Optimize Google avatar URL for better loading
     */
    private function optimizeGoogleAvatarUrl($avatarUrl)
    {
        if (!$avatarUrl || !str_contains($avatarUrl, 'googleusercontent.com')) {
            return $avatarUrl;
        }
        
        // Remove any existing size parameters and add optimized ones
        $baseUrl = explode('=', $avatarUrl)[0];
        return $baseUrl . '=s96-c-k-no'; // s96=size, c=crop, k=keep aspect ratio, no=no redirect
    }

    /**
     * Helper to determine avatar type for logging
     */
    private function getAvatarType($avatar)
    {
        if (!$avatar) return 'none';
        if (str_starts_with($avatar, 'http')) return 'google';
        if (str_starts_with($avatar, 'default:')) return 'default';
        return 'local';
    }
}
