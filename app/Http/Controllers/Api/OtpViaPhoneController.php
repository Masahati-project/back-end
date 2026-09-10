<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FirebaseAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

class OtpViaPhoneController extends Controller
{
    public function verifyPhone(Request $request, FirebaseAuthService $firebaseAuth)
    {
        $request->validate([
            'registration_token' => 'required|string',
            'id_token' => 'required|string',
        ]);

        try {
            $firebaseAuth->verifyToken($request->id_token);

            $pendingData = Cache::get('pending_registration_' . $request->registration_token);

            if (!$pendingData) {
                return response()->json([
                    'message' => 'انتهت صلاحية الجلسة، يرجى إعادة محاولة التسجيل من جديد'
                ], 400);
            }

            try {
                if ($pendingData['role'] == 'space_owner') {
                    $user = User::create([
                        'full_name' => $pendingData['name'],
                        'phone' => $pendingData['phone'],
                        'email' => $pendingData['email'],
                        'verified_at' => now(),
                        'password' => $pendingData['password'],
                        'proof_document_url' => $pendingData['proof_document_url'],
                        'role' => $pendingData['role'],
                        'status' => $pendingData['status'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                } else {
                    $user = User::create([
                        'full_name' => $pendingData['name'],
                        'phone' => $pendingData['phone'],
                        'email' => $pendingData['email'],
                        'verified_at' => now(),
                        'password' => $pendingData['password'],
                        'role' => $pendingData['role'],
                        'status' => $pendingData['status'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            } catch (\Throwable $th) {
                return $th->getMessage();
            }

            Cache::delete('pending_registration_' . $request->registration_token);

            return response()->json([
                'user' => $user,
                'message' => 'تم انشاء الحساب بنجاح'
            ], 200);
            

        } catch (FailedToVerifyToken $e) {

            return response()->json([
                'error' => 'رمز التحقق غير صالح أو منتهي الصالحية',
            ], 401);


        } catch (\Exception $e) {

            return response()->json(
                [
                    'error' => 'حدث خطأ أثناء التحقق',
                ],
                500
            );
        }
    }
}
