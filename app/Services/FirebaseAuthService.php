<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

class FirebaseAuthService
{
    protected $auth;
    public function __construct()
    {
        $factory = (new Factory)->withServiceAccount(config('firebase.credentials'));
        $this->auth = $factory->createAuth();
    }

    public function verifyToken(string $idToken): array
    {
        $verifiedIdToken = $this->auth->verifyIdToken($idToken);

        return [
            'phone_number' => $verifiedIdToken->claims()->get('phone_number'),
            'uid' => $verifiedIdToken->claims()->get('sub'),
        ];
    }
}
