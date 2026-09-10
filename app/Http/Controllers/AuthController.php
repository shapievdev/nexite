<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('chat');
        }

        // Экран входа ничего не рассказывает о участниках — только поле кода.
        return view('login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:4'],
        ], [
            'code.required' => 'Введите код доступа.',
            'code.digits' => 'Код состоит из 4 цифр.',
        ]);

        $key = 'login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages([
                'code' => 'Слишком много попыток. Повторите через '
                    .RateLimiter::availableIn($key).' сек.',
            ]);
        }

        $user = User::orderBy('id')->get()
            ->first(fn (User $u) => Hash::check($data['code'], $u->access_code));

        if (! $user) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'code' => 'Неверный код доступа.',
            ]);
        }

        RateLimiter::clear($key);

        $request->session()->regenerate();
        Auth::login($user, remember: true);
        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        return redirect()->intended(route('chat'));
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($user = Auth::user()) {
            $user->forceFill(['last_seen_at' => now()->subMinute(), 'typing_at' => null])->saveQuietly();
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
