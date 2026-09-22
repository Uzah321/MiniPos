<?php

namespace Modules\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Authentication\Http\Controllers\PosAuthController;

class AuthController extends Controller
{
    public function login(Request $request, PosAuthController $controller)
    {
        $request->merge([
            'employee_code' => $request->input('login'),
            'pin' => $request->input('credential'),
        ]);

        return $controller->login($request);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
