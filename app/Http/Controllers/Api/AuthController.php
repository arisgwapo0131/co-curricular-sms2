<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $credentials['username'])
            ->orWhere('email', $credentials['username'])
            ->first();

        if (!$user || !password_verify($credentials['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials do not match our records.'],
            ]);
        }

        if (strcasecmp($user->status ?? 'Active', 'Active') !== 0) {
            throw ValidationException::withMessages([
                'username' => ['Your account has been deactivated. Please contact the administrator.'],
            ]);
        }

        $user->update(['last_login' => now()]);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => $user,
        ]);
    }

    public function register(Request $request)
    {
        return response()->json([
            'message' => 'Public self-registration is disabled. All student and institutional accounts are pre-provisioned by the administration.',
        ], 403);
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $user->load('student');
        }
        return response()->json([
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }
}
