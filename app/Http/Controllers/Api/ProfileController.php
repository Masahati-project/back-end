<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerProfileUpdateRequest;
use App\Http\Requests\OwnerProfileUpdateRequest;
use App\Http\Requests\UpdateProfilePictureRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function profile(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'name' => $user->full_name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role,
            'picture' => $user->profile_picture_url
                ? Storage::url($user->profile_picture_url)
                : null,
            'proof_document' => $user->proof_document_url
                ? Storage::url($user->proof_document_url)
                : null,
        ], 200);
    }

    public function updateOwnerProfile(OwnerProfileUpdateRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('proof_document')) {

            User::deleteProofDocument($request->user()->proof_document_url);

            $path = $request->file('proof_document')->store('documents', 'cloudinary');
            $data['proof_document_url'] = $path;
        }

        unset($data['proof_document']); // never mass-assign the raw file

        return $this->updateProfile($request, $data);
    }

    public function updateCustomerProfile(CustomerProfileUpdateRequest $request)
    {
        return $this->updateProfile($request, $request->validated());
    }

    public function uploadPicture(Request $request)
    {
        $request->validate([
            'profile_picture' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048'
                // Additional security: validate MIME type and extension match
                // File content validation should be done at storage level
        ]);

        $user = $request->user();

        $path = $request->file('profile_picture')->store('profile-pictures', 'cloudinary');
        $status = $user->forceFill([
            'profile_picture_url' => $path
        ]);
        $user->save();

        if ($status) {
            return response()->json([
                'message' => 'تم رفع الصورة الشخصية بنجاح',
                'profile_picture_url' => Storage::url($user->profile_picture_url),
                'user' => $user->fresh(),
            ], 200);
        } else {
            return response()->json([
                'message' => 'لم يتم رفع الصورة الشخصية'
            ], 400);
        }
    }

    public function updateProfilePicture(UpdateProfilePictureRequest $request)
    {
        $user = $request->user();

        User::deletePicture($user->profile_picture_url);

        $user->profile_picture_url = $request->file('profile_picture')->store('profile-pictures', 'cloudinary');
        $user->save();

        return response()->json([
            'message' => 'تم تحديث الصورة الشخصية بنجاح',
            'profile_picture_url' => Storage::url($user->profile_picture_url),
            'user' => $user->fresh(),
        ], 200);
    }


    protected function updateProfile($request, array $data)
    {
        $user = $request->user();

        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->verified_at = null;
        }

        $user->save();
        return response()->json([
            'message' => 'تم تحديث الملف الشخصي بنجاح',
            'user' => $user->fresh(),
        ], 200);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'oldPassword' => 'required|string|min:8',
            'newPassword' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/'
            ]
        ]);

        if (Hash::check($request->oldPassword, $user->password)) {
            // Invalidate all existing tokens for security
            $user->tokens()->delete();

            $status = $user->forceFill([
                'password' => Hash::make($request->newPassword)
            ]);
            $user->save();

            // Generate new token after password change
            $newToken = $user->createToken('auth_token')->plainTextToken;

            if ($status) {
                return response()->json([
                    'message' => 'تم تغيير كلمة المرور بنجاح',
                    'token' => $newToken
                ], 200);
            } else {
                return response()->json([
                    'message' => 'لم يتم تغيير كلمة المرور، حاول مجدداً'
                ], 400);
            }
        } else {
            return response()->json([
                'message' => 'كلمة المرور الحالية غير صحيحة'
            ], 400);
        }
    }
}
