<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = Auth::user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'bio' => ['nullable', 'string', 'max:200'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'avatar' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                Storage::disk('local')->delete($user->avatar_path);
            }

            $file = $request->file('avatar');
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $data['avatar_path'] = $file->storeAs(
                'avatars',
                Str::uuid()->toString().'.'.preg_replace('/[^a-z0-9]/', '', $ext),
                'local'
            );
        }

        unset($data['avatar']);
        $user->update($data);

        return response()->json(['user' => $user->fresh()->toPublicArray()]);
    }

    public function changeCode(Request $request): JsonResponse
    {
        $user = Auth::user();

        $data = $request->validate([
            'current' => ['required', 'digits:4'],
            'code' => ['required', 'digits:4'],
        ], [
            'code.digits' => 'Новый код должен состоять из 4 цифр.',
        ]);

        if (! Hash::check($data['current'], $user->access_code)) {
            return response()->json(['message' => 'Текущий код указан неверно.'], 422);
        }

        if (User::isWeakCode($data['code'])) {
            return response()->json([
                'message' => 'Слишком простой код: не используйте одинаковые или идущие подряд цифры.',
            ], 422);
        }

        $peer = $user->peer();

        if ($peer && Hash::check($data['code'], $peer->access_code)) {
            return response()->json(['message' => 'Этот код уже занят собеседником.'], 422);
        }

        $user->update(['access_code' => $data['code']]);

        return response()->json(['ok' => true]);
    }
}
