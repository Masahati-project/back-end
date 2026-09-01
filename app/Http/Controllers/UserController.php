<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\support\facades\Hash;
use Illuminate\support\facades\Auth;
class UserController extends Controller
{
    public function register(Request $request)
    {
     

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'image' => 'nullable|image',
        ]);

        $path = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $path = $file->store('picture', 'public');
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'image' => $path ?? 'default.png',
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => 'User Registered Successfully',
            'User' => $user
        ], 201);
         }
     
     public function login(Request $request)
{
    $request->validate([
        'email' => 'required|string|email',
        'password' => 'required|string',
    ]);

    if (!Auth::attempt($request->only('email', 'password'))) {
        return response()->json([
            'message' => 'invalid email or password'
        ], 401);
    }

    $user = User::where('email', $request->email)->firstOrFail();
    $token = $user->createToken('auth_Token')->plainTextToken;

    return response()->json([
        'message' => 'Login Successfully',
        'User' => $user,
        'Token' => $token
    ], 201);
}

       public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
            return response()->json([
         'message'=>'Logout Successfully']);
       

    }
}