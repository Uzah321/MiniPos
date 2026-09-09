<?php

namespace App\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Terminals\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'credential' => ['required', 'string'],
            'terminal_id' => ['required', 'exists:terminals,id'],
        ]);

        $user = User::query()
            ->where('employee_code', $data['login'])
            ->orWhere('email', $data['login'])
            ->first();

        $terminal = Terminal::findOrFail($data['terminal_id']);

        $credentialValid = $user && (
            Hash::check($data['credential'], $user->password)
            || ($user->pin_hash && Hash::check($data['credential'], $user->pin_hash))
        );

        if (! $user || ! $credentialValid) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->active) {
            throw ValidationException::withMessages([
                'login' => ['This account is not active.'],
            ]);
        }

        if ($user->store_id !== null && $user->store_id !== $terminal->store_id) {
            throw ValidationException::withMessages([
                'login' => ['This account is not assigned to this store.'],
            ]);
        }

        $token = $user->createToken("terminal-{$terminal->id}")->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'employee_code' => $user->employee_code,
                'store_id' => $user->store_id,
                'roles' => $user->getRoleNames(),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
