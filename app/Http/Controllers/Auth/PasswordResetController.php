<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * Send a reset link to the given user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendResetLinkEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find the user
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }

        // If user authenticated with Google, don't allow password reset
        if ($user->google_id && !$user->password) {
            return response()->json([
                'status' => 'error',
                'message' => 'This account uses Google authentication. Please sign in with Google.'
            ], 400);
        }

        // Generate a reset token
        $token = Str::random(64);

        // Store the token in the password_reset_tokens table
        \DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        \DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => $token,
            'created_at' => now()
        ]);

        // Generate the reset URL - updated to point to React frontend
        $resetUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);

        // Send the reset email
        try {
            Mail::to($user->email)->send(new \App\Mail\PasswordReset($user, $resetUrl));

            return response()->json([
                'status' => 'success',
                'message' => 'Password reset link has been sent to your email'
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to send password reset email: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send password reset email. Please try again.'
            ], 500);
        }
    }

    /**
     * Reset the given user's password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function reset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if the token is valid
        $reset = \DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$reset) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid token'
            ], 400);
        }

        // Check if the token is expired (tokens older than 1 hour are invalid)
        if (now()->diffInMinutes($reset->created_at) > 60) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token has expired'
            ], 400);
        }

        // Find the user
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }

        // Reset the user's password
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete the token
        \DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Password has been reset successfully'
        ]);
    }

    /**
     * Display the password reset form.
     * 
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function showResetForm(Request $request)
    {
        $token = $request->token;
        $email = $request->email;
        
        // Check if the token is valid
        $reset = \DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $token)
            ->first();
            
        if (!$reset) {
            return view('auth.password-reset-error', [
                'message' => 'Invalid or expired password reset link.'
            ]);
        }
        
        // Check token expiration
        if (now()->diffInMinutes($reset->created_at) > 60) {
            return view('auth.password-reset-error', [
                'message' => 'Your password reset link has expired.'
            ]);
        }
        
        return view('auth.password-reset', [
            'token' => $token,
            'email' => $email
        ]);
    }
    
    /**
     * Process the password reset.
     * 
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function processReset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }
        
        // Check if the token is valid
        $reset = \DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();
            
        if (!$reset) {
            return view('auth.password-reset-error', [
                'message' => 'Invalid token'
            ]);
        }
        
        // Check if the token is expired
        if (now()->diffInMinutes($reset->created_at) > 60) {
            return view('auth.password-reset-error', [
                'message' => 'Token has expired'
            ]);
        }
        
        // Find the user
        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return view('auth.password-reset-error', [
                'message' => 'User not found'
            ]);
        }
        
        // Reset the user's password
        $user->password = Hash::make($request->password);
        $user->save();
        
        // Delete the token
        \DB::table('password_reset_tokens')->where('email', $request->email)->delete();
        
        return view('auth.password-reset-success');
    }
} 