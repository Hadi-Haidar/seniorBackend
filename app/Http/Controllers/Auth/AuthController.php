<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if email already exists
        $existingUser = User::where('email', $request->email)->first();
        
        if ($existingUser) {
            // If email exists and is verified, show error
            if ($existingUser->email_verified_at) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This email is already registered and verified',
                    'errors' => ['email' => ['The email has already been taken.']]
                ], 422);
            }
            
            // If email exists but is not verified, resend verification code
            $verificationCode = sprintf("%06d", mt_rand(100000, 999999));
            $existingUser->email_verification_token = $verificationCode;
            $existingUser->email_verification_token_created_at = now();
            
            // Update password in case user forgot their password
            $existingUser->password = Hash::make($request->password);
            $existingUser->save();
            
            // Send verification email with new code
            try {
                Mail::to($existingUser->email)->send(new \App\Mail\EmailVerification($existingUser, $verificationCode));
            } catch (\Exception $e) {
                \Log::error('Failed to send verification email: ' . $e->getMessage());
            }

            return response()->json([
                'status' => 'redirect_to_verification',
                'message' => 'This email is already registered but not verified. We\'ve sent you a new verification code.',
                'email' => $request->email,
                'verification_code' => $verificationCode // Remove this in production
            ]);
        }

        // Generate verification code (6 digits)
        $verificationCode = sprintf("%06d", mt_rand(100000, 999999));

        try {
            // Create the user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'email_verification_token' => $verificationCode,
                'email_verification_token_created_at' => now(),
                'google_id' => null,
                'avatar' => null
            ]);
            
            // Generate and store default avatar for email users
            $defaultAvatar = $user->generateDefaultAvatar();
            $user->avatar = $defaultAvatar;
            $user->save();
            
            // Log for debugging
            \Log::info("User created with code: " . $verificationCode);
            \Log::info("Default avatar generated: " . $defaultAvatar);
            
            // Verify the code was saved
            $savedUser = User::find($user->id);
            \Log::info("Saved code: " . $savedUser->email_verification_token);
            \Log::info("Saved avatar: " . $savedUser->avatar);
            
            // Send verification email with code
            try {
                Mail::to($user->email)->send(new \App\Mail\EmailVerification($user, $verificationCode));
            } catch (\Exception $e) {
                // Log error but don't expose details to user
                \Log::error('Failed to send verification email: ' . $e->getMessage());
            }

            // For development/testing purposes only
            return response()->json([
                'status' => 'success',
                'message' => 'User registered successfully. Please check your email for the verification code.',
                'verification_code' => $verificationCode, // Remove this in production
                'user_id' => $user->id // Remove this in production
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to create user: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create user. Please try again.'
            ], 500);
        }
    }

    /**
     * Login user
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
            'remember' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember', false);
        
        if (Auth::attempt($credentials)) {
            $user = Auth::user();
            
            // Prevent banned users from logging in
            if ($user->status === 'banned') {
                Auth::logout();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your account has been banned. Please contact support.'
                ], 403);
            }
            // Make sure user's email is verified
            if (!$user->email_verified_at) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Please verify your email before logging in'
                ], 401);
            }
            
            // Revoke existing tokens
            // $user->tokens()->delete(); // Uncomment if you want to invalidate old tokens
            
            // Create token with or without expiration
            if ($remember) {
                // For remember me: 30 days expiration
                $token = $user->createToken('auth-token', ['*'], now()->addDays(30))->plainTextToken;
            } else {
                // For regular login: 1 day expiration
                $token = $user->createToken('auth-token', ['*'], now()->addDay())->plainTextToken;
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'User logged in successfully',
                'user' => $user->makeVisible(['user_type']), // Make sure user_type is included
                'token' => $token,
                'remember_me' => $remember,
                'is_admin' => $user->isAdmin() // Add convenient is_admin flag
            ]);
        }
        
        return response()->json([
            'status' => 'error',
            'message' => 'Invalid credentials'
        ], 401);
    }

    /**
     * Logout user (works for both Google and email authentication)
     */
    public function logout(Request $request)
    {
        // Revoke all tokens for the authenticated user
        $request->user()->tokens()->delete();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Successfully logged out'
        ]);
    }

    /**
     * Verify email with code
     */
    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code' => 'required|string|size:6'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)
                   ->where('email_verification_token', $request->code)
                   ->whereNull('email_verified_at')
                   ->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid verification code or email already verified'
            ], 400);
        }

        // Check if code has expired (24 hours)
        if ($user->email_verification_token_created_at && 
            now()->diffInHours($user->email_verification_token_created_at) > 24) {
            return response()->json([
                'status' => 'error',
                'message' => 'Verification code has expired. Please request a new one.'
            ], 400);
        }

        $user->email_verified_at = now();
        $user->email_verification_token = null;
        $user->email_verification_token_created_at = null;
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Email verified successfully'
        ]);
    }
    
    /**
     * Resend verification code
     */
    public function resendVerificationCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)
                   ->whereNull('email_verified_at')
                   ->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found or email already verified'
            ], 404);
        }

        // Generate new verification code
        $verificationCode = sprintf("%06d", mt_rand(100000, 999999));
        $user->email_verification_token = $verificationCode;
        $user->email_verification_token_created_at = now();
        $user->save();

        // Send verification email with new code
        try {
            Mail::to($user->email)->send(new \App\Mail\EmailVerification($user, $verificationCode));
            
            return response()->json([
                'status' => 'success',
                'message' => 'Verification code sent successfully',
                'verification_code' => $verificationCode // Remove this in production
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to send verification email: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send verification email. Please try again.'
            ], 500);
        }
    }
}
