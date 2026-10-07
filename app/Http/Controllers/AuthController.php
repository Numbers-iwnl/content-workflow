<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sem senha: cada pessoa recebe um link pessoal (por WhatsApp). Ao abrir,
 * o aparelho fica logado. Gerar um link novo invalida o anterior.
 */
class AuthController extends Controller
{
    public function login()
    {
        if (Auth::check()) {
            return redirect()->route('home');
        }

        return view('auth.login');
    }

    public function loginWithLink(Request $request, string $token)
    {
        $user = User::findByLoginToken($token);

        if (! $user) {
            return view('auth.login', ['invalid' => true]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('home');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
