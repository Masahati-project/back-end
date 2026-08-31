<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerProfileUpdateRequest;
use App\Http\Requests\OwnerProfileUpdateRequest;
use App\Http\Requests\UpdateProfilePictureRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function updateOwnerProfile(OwnerProfileUpdateRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('proof_document')) {
            $path = $request->file('proof_document')->store('documents', 'public');

            if ($request->user()->proof_document_url) {
                Storage::disk('public')->delete($request->user()->proof_document_url);
            }

            $data['proof_document_url'] = $path;
        }

        unset($data['proof_document']); // never mass-assign the raw file

        return $this->updateProfile($request, $data);
    }

    public function updateCustomerProfile(CustomerProfileUpdateRequest $request)
    {
        return $this->updateProfile($request, $request->validated());
    }

    public function updateProfilePicture(UpdateProfilePictureRequest $request)
    {
        $user = $request->user();

        if ($user->profile_picture_url) {
            Storage::disk('public')->delete($user->profile_picture_url);
        }

        $user->profile_picture_url = $request->file('profile_picture')->store('profile-pictures', 'public');
        $user->save();

        return response()->json([
            'message' => 'تم تحديث الصورة الشخصية بنجاح',
            'profile_picture_url' => Storage::url($user->profile_picture),
            'user' => $user->fresh(),
        ], 200);
    }


    protected function updateProfile($request, array $data)
    {
        $user = $request->user();

        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
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
            'newPassword' => 'required|string|min:8|confirmed'
        ]);
        
        if(Hash::check($request->oldPassword, $user->password)) {
            $status = $user->forceFill([
                'password' => Hash::make($request->newPassword)
            ]);
            $user->save();

            if($status) {
                return response()->json([
                    'message' => 'تم تغيير كلمة المرور بنجاح'
                ], 200);
            } else {
                return response()->json([
                    'message' => 'لم يتم تغيير كلمة المرور، حاول مجدداً'
                ], 400);
            }
        } else {
            return response()->json([
                'message' => 'معلومات خاطئة، حاول مجدداً'
            ], 400);
        }
    }
}
