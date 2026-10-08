<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminProfilePictureRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    /**
     * Admin login
     * POST /api/admin/login
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:120',
            'password' => 'required|string|min:8|max:255',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
                'errors' => []
            ], 401);
        }

        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'This account is not an administrator.',
                'errors' => []
            ], 403);
        }

        // Create token
        $token = $user->createToken('admin-token', ['admin'])->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_at' => now()->addHours(12)->toIso8601String(),
                'admin' => [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'whatsapp' => $user->phone ?? '+970 59 000 0000',
                    'role' => 'admin'
                ]
            ]
        ]);
    }

    /**
     * Admin logout
     * POST /api/admin/logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Get current admin profile
     * GET /api/admin/me
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Not an administrator.',
                'errors' => []
            ], 403);
        }

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->full_name,
                'email' => $user->email,
                'whatsapp' => $user->phone ?? '+970 59 000 0000',
                'role' => 'admin',
                'last_login_at' => $user->updated_at->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
            ]
        ]);
    }

    /**
     * Update admin profile
     * PATCH /api/admin/profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|required|string|min:1|max:60',
            'email' => 'sometimes|required|email|max:120|unique:users,email,' . $user->id,
            'whatsapp' => 'sometimes|nullable|string|max:20',
        ]);

        if ($request->has('name')) {
            $user->full_name = trim($request->name);
        }

        if ($request->has('email')) {
            $user->email = strtolower(trim($request->email));
        }

        if ($request->has('whatsapp')) {
            $user->phone = $request->whatsapp;
        }

        $user->save();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->full_name,
                'email' => $user->email,
                'whatsapp' => $user->phone ?? '+970 59 000 0000',
                'role' => 'admin',
                'last_login_at' => $user->updated_at->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
            ]
        ]);
    }

    /**
     * Change admin password
     * PUT /api/admin/password
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'كلمة المرور الحالية غير صحيحة.',
                'errors' => []
            ], 400);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // Revoke all other tokens except current one
        $currentTokenId = $request->user()->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'data' => [
                'message' => 'تم تغيير كلمة المرور بنجاح.'
            ]
        ]);
    }

    /**
     * Update admin profile picture
     * POST /api/admin/profile/picture
     */
    public function updateProfilePicture(AdminProfilePictureRequest $request)
    {
        $user = $request->user();

        // Delete old picture if exists, using the same helper as customer endpoint
        User::deletePicture($user->profile_picture_url);

        // Store new picture using the same path convention
        $user->profile_picture_url = $request->file('profile_picture')->store('profile-pictures', 'cloudinary');
        $user->save();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->full_name,
                'email' => $user->email,
                'whatsapp' => $user->phone ?? '+970 59 000 0000',
                'role' => 'admin',
                'profile_picture_url' => $user->profile_picture_url,
            ]
        ]);
    }
}
