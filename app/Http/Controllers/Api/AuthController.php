<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function registerSpaceOwnerAccount(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'phone' => 'required|numeric|unique:users,phone',
            'email' => 'required|email|unique:users,email',
            'password' => 'required',
            'proof_document' => 'required|file'
        ]);
        if ($request->hasFile('proof_document')) {
            $file = $request->file('proof_document');
            $path = $file->store('/picture', 'public');

            $user = User::create([
                'name' => $request->full_name,
                'phone' => $request->phone,
                'email' => $request->email,
                'password' => bcrypt($request->password),
                'role' => 'space_owner',
                'proof_document_url' => $path,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'user' => $user,
            'status' => 201,
            'message' => 'space owner registered successfully'
        ]);
    }

    public function registerCustomerAccount(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'phone' => 'required|numeric|unique:users,phone',
            'email' => 'required|email|unique:users,email',
            'password' => 'required',
        ]);

        $user = User::create([
            'name' => $request->full_name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => 'customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'user' => $user,
            'status' => 201,
            'message' => 'customer registered successfully'
        ]);
    }
    public function loginAccount(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);


        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json('Invalid credentials', 401);
        }


        $token = $user->createToken('remember_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => 'logged in'
        ]);
    }

    public function accountDetails(Request $request)
    {
        return $request->user();
    }

    public function logoutAccount(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json([
            'message' => 'logged out successfully'
        ]);
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();
        User::deleteProofDocument($user->proof_document_url);
        $user->tokens()->delete();
        $user->delete();
        return response()->json([
            'message' => 'user deleted successfully',
            'status' => 201
        ]);
    }
}
