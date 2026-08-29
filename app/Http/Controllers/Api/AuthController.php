<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function registerSpaceOwnerAccount(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:users,full_name',
            'phone' => 'required|regex:/^05[0-9]{8}$/|unique:users,phone',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'proof_document' => 'required|file'
        ]);
        if ($request->hasFile('proof_document')) {
            $file = $request->file('proof_document');
            $path = $file->store('documents', 'public');
            $request->merge([
                'proof_document_url' => $path,
            ]);
        }

        $otp = rand(100000, 999999);
        $token = Str::uuid()->toString();
        Cache::put('pending_registration_' . $token, [
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'proof_document_url' => $request->proof_document_url,
            'role' => 'space_owner',
            'status' => 'pending',
            'otp' => $otp,
        ], now()->addMinutes(10));

        $isSent = OtpController::sendOtp($request->email, $request->name, $otp);

        if (!$isSent) {
            return response()->json([
                'message' => 'فشل إرسال البريد الإلكتروني',
            ], 500);
        } else {
            return response()->json([
                'meassage' => 'تم ارسال الكود, يرجى تفقد الايميل الخاص بك',
                'registration_token' => $token
            ], 200);
        }
    }

    public function registerCustomerAccount(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|regex:/^05[0-9]{8}$/|unique:users,phone',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $otp = rand(100000, 999999);
        $token = Str::uuid()->toString();
        Cache::put('pending_registration_' . $token, [
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'customer',
            'status' => 'active',
            'otp' => $otp,
        ], now()->addMinutes(10));

        $isSent = OtpController::sendOtp($request->email, $request->name, $otp);

        if (!$isSent) {
            return response()->json([
                'message' => 'فشل إرسال البريد الإلكتروني',
            ], 500);
        } else {
            return response()->json([
                'message' => 'تم ارسال الكود, يرجى تفقد الايميل الخاص بك',
                'registration_token' => $token
            ], 200);
        }
    }


    public function loginAccount(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string'
        ]);

        $loginValue = $request->input('login');
        $loginField = filter_var($loginValue, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $credentials = [
            $loginField => $loginValue,
            'password' => $request->input('password'),
        ];

        if (Auth::attempt($credentials)) {
            $user = User::where($loginField, $request->login)->first();

            $user->tokens()->delete();

            $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json([
                'message' => 'تم تسجيل دخولك بنجاح',
                'token'   => $token,
                'user'    => $user->full_name,
            ], 200);
        }

        return response()->json([
            'message' => 'معلومات خاطئة'
        ], 401);
    }

    public function accountDetails(Request $request)
    {
        return response()->json([
            'user' => $request->user()
        ], 200);
    }

    public function logoutAccount(Request $request)
    {
        $user = $request->user();

        $user->tokens()->delete();

        return response()->json([
            'message' => 'تم تسجيل خروجك بنجاح'
        ], 200);
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        User::deleteProofDocument($user->proof_document_url);
        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'تم حذف الحساب بنجاح'
        ], 200);
    }
}
